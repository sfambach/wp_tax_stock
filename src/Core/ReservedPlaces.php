<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Places «reserviert für Produktion» (D-1082): everything on such a place or on a place below it is reserved. Nothing
 * about it is stored at a material or a lot — it is computed from where things lie, so a box hung elsewhere takes its
 * whole content with it at once.
 *
 * Strict, his word «wir bleiben bei streng»: the reservation is freed only by moving the goods out of the area or by
 * consuming them for exactly the area's Bezug (D-1088), and goods already reserved may not go into such an area.
 *
 * ```mermaid
 * flowchart TD
 *   P["Platz P (reserviert)"] --> K["Kiste K"]
 *   K --> X["Posten: reserviert, Bezug = Projekt von K oder P, sonst «Produktion»"]
 *   X -->|Umbuchung hinaus| F["frei"]
 *   X -->|Verbrauch für genau diesen Bezug| V["verbraucht"]
 * ```
 */
final class ReservedPlaces
{
    /** @var array<int, int|null> place → its reserving place, computed once per place */
    private array $reservingOf = [];

    /** @param array<int, true> $flagged the places carrying the flag themselves */
    public function __construct(private readonly LocationTree $tree, private readonly array $flagged)
    {
    }

    public static function from(Structure $s, Table $places, LocationTree $tree): self
    {
        $flagged = [];

        if ($s->hasField('location_reserved')) {
            foreach ($places->ids() as $id) {
                if ($places->first($id, $s->field('location_reserved')) === '1') {
                    $flagged[$id] = true;
                }
            }
        }

        return new self($tree, $flagged);
    }

    public function isFlagged(int $place): bool
    {
        return isset($this->flagged[$place]);
    }

    /** @return list<int> */
    public function flagged(): array
    {
        return array_keys($this->flagged);
    }

    /** The nearest flagged place at or above `$place` — the one whose reservation holds what lies there; null when free. */
    public function reservingPlaceOf(int $place): ?int
    {
        if ($place <= 0 || $this->flagged === []) {
            return null;
        }

        if (! array_key_exists($place, $this->reservingOf)) {
            $this->reservingOf[$place] = null;

            foreach (array_reverse($this->tree->lineOf($place)) as $at) {
                if (isset($this->flagged[$at])) {
                    $this->reservingOf[$place] = $at;
                    break;
                }
            }
        }

        return $this->reservingOf[$place];
    }

    /**
     * An Umbuchung into a reserved area takes only goods not reserved yet: neither by another area nor by a reservation
     * naming the lot. Within one area, and out of it, goods move freely.
     */
    public function checkTransfer(int $lot, int $from, int $to, bool $bound): void
    {
        $target = $this->reservingPlaceOf($to);

        if ($target === null) {
            return;
        }

        $source = $this->reservingPlaceOf($from);

        if ($bound || ($source !== null && $source !== $target)) {
            throw StockError::alreadyReserved($lot);
        }
    }

    /**
     * The Bezug of what lies at `$place` in a reserved area: the project of the nearest place up the tree, 0 for
     * «Produktion» (D-1082).
     */
    public function subjectAt(int $place): int
    {
        return $this->tree->projectIdOf($place);
    }

    /**
     * Inside a reserved area only a consumption for exactly its Bezug (D-1088): the project of the nearest place up the
     * tree, or «Produktion» chosen explicitly where there is none. Any other purpose moves the goods out first (D-1082).
     */
    public function checkConsumption(int $place, ?Purpose $purpose = null): void
    {
        if ($this->reservingPlaceOf($place) === null) {
            return;
        }

        if (! ($purpose ?? Purpose::none())->matchesPlace($this->subjectAt($place))) {
            throw StockError::reservedForProduction($place);
        }
    }

    /**
     * Hanging a place under another moves everything in it: into a reserved area only what is not reserved by an area
     * outside the place, or by a reservation naming the lot.
     *
     * @param array<int, true> $bound lots a reservation names
     */
    public function checkHang(int $place, int $parent, Ledger $ledger, array $bound): void
    {
        $target = $parent > 0 ? $this->reservingPlaceOf($parent) : null;

        if ($target === null) {
            return;
        }

        $inside = array_flip($this->tree->withAllBelow($place));

        foreach ($ledger->stockByPlace() as $lot => $places) {
            foreach (array_keys($places) as $at) {
                if (! isset($inside[$at])) {
                    continue;
                }

                $holder = $this->reservingPlaceOf($at);

                if (isset($bound[$lot]) || ($holder !== null && ! isset($inside[$holder]) && $holder !== $target)) {
                    throw StockError::alreadyReserved($lot);
                }
            }
        }
    }

    /**
     * Setting the flag makes what lies there reserved: not over goods a reservation already names.
     *
     * @param array<int, true> $bound
     */
    public function checkFlag(int $place, Ledger $ledger, array $bound): void
    {
        $inside = array_flip($this->tree->withAllBelow($place));

        foreach ($ledger->stockByPlace() as $lot => $places) {
            if (isset($bound[$lot]) && array_intersect_key($places, $inside) !== []) {
                throw StockError::alreadyReserved($lot);
            }
        }
    }

    /**
     * What each reserved area holds — its reservation's positions, computed (D-1083: read-only).
     *
     * @return array<int, array<int, array<int, float>>> reserving place → lot → place → quantity
     */
    public function holdings(Ledger $ledger): array
    {
        $out = [];

        foreach ($ledger->stockByPlace() as $lot => $places) {
            foreach ($places as $place => $quantity) {
                $holder = $this->reservingPlaceOf($place);

                if ($holder !== null) {
                    $out[$holder][$lot][$place] = $quantity;
                }
            }
        }

        return $out;
    }

    /**
     * How much of each thing lies in reserved areas.
     *
     * @return array<int, float> thing → quantity
     */
    public function reservedByThing(Ledger $ledger): array
    {
        $out = [];

        foreach ($this->holdings($ledger) as $lots) {
            foreach ($lots as $lot => $places) {
                $thing       = (int) $ledger->thingOf($lot);
                $out[$thing] = ($out[$thing] ?? 0.0) + array_sum($places);
            }
        }

        return $out;
    }
}
