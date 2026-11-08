<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Stock on hand, computed from the movements: nothing about a quantity is stored (D-956, D-1030).
 *
 * A Pos line moves at a place: the place it names (D-1039, «Umbuchung»), else the place its lot came in. So the quantity
 * of a lot per place is the sum of its lines there, and a lot booked before lines had a place lies wholly where it came in.
 * Lines of a reservation (D-1083) move nothing: they lower what is available, not what is there.
 *
 * ```mermaid
 * flowchart LR
 *   P["Vorgänge Pos: Menge ±, Lagerort 0..1"] -->|sum per lot and place| O["Posten an einem Ort"]
 *   O -->|sum per lot| L["Bestandsposten: rest"]
 *   L -->|sum per thing| M["Ding: Bestand"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §3.5a
 */
final class Ledger
{
    /** Below this a quantity counts as nothing; decimals from the model add up with float noise. */
    private const NOTHING = 0.0000001;

    /** @var array<int, float> lot id → rest */
    private array $rest = [];

    /** @var array<int, int> lot id → thing id (D-995: the thing is the material) */
    private array $thingOf = [];

    /** @var array<int, string> lot id → receipt date */
    private array $dateOf = [];

    /** @var array<int, int> lot id → the place it came in, 0 when it names none */
    private array $homeOf = [];

    /** @var array<int, array<int, float>> lot id → place id → quantity there */
    private array $at = [];

    /** @param Table|null $transactions the Vorgänge, to leave out the lines of reservations; without them every line counts */
    public function __construct(Structure $structure, Table $lots, Table $lines, ?Table $transactions = null)
    {
        foreach ($lots->ids() as $lot) {
            $this->thingOf[$lot]    = (int) $lots->first($lot, $structure->field('lot_thing'));
            $this->dateOf[$lot]     = (string) $lots->first($lot, $structure->field('lot_date'));
            $this->homeOf[$lot]     = (int) $lots->first($lot, $structure->field('lot_location'));
            $this->rest[$lot]       = 0.0;
            $this->at[$lot]         = [];
        }

        $skip  = $transactions === null ? [] : self::reservationLines($structure, $transactions);
        $place = $structure->hasField('line_location') ? $structure->field('line_location') : null;

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $structure->field('line_lot'));

            // A line on a lot that no longer exists counts nowhere rather than inventing a lot.
            if (! isset($this->rest[$lot]) || isset($skip[$line])) {
                continue;
            }

            $quantity          = (float) $lines->first($line, $structure->field('line_quantity'));
            $where             = $place === null ? 0 : (int) $lines->first($line, $place);
            $where             = $where > 0 ? $where : $this->homeOf[$lot];
            $this->rest[$lot] += $quantity;
            $this->at[$lot][$where] = ($this->at[$lot][$where] ?? 0.0) + $quantity;
        }
    }

    /**
     * The lines of the Vorgänge of kind «Reservierung» (D-1083), which move no stock.
     *
     * @return array<int, true>
     */
    public static function reservationLines(Structure $s, Table $transactions): array
    {
        if (! $s->hasValue('kind_reservation')) {
            return [];
        }

        $out = [];

        foreach ($transactions->ids() as $deal) {
            if ((int) $transactions->first($deal, $s->field('transaction_kind')) === $s->value('kind_reservation')) {
                foreach ($transactions->all($deal, $s->field('transaction_lines')) as $line) {
                    $out[(int) $line] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Where a lot lies and how much: every place where its lines leave something other than nothing.
     *
     * @return array<int, float> place id → quantity (0: the lot names no place)
     */
    public function placesOf(int $lot): array
    {
        return array_filter($this->at[$lot] ?? [], static fn (float $q): bool => abs($q) > self::NOTHING);
    }

    /**
     * Lots below nothing at some place — what an Umbuchung leaves when the lot's «Lagerort» was changed by hand afterwards
     * (his report 2026-11-08): the old place goes negative, the new one counts twice.
     *
     * @return list<int>
     */
    public function misplaced(): array
    {
        return array_keys(array_filter($this->at, static fn (array $places): bool => array_filter($places, static fn (float $q): bool => $q < -self::NOTHING) !== []));
    }

    public function restAt(int $lot, int $place): float
    {
        return $this->at[$lot][$place] ?? 0.0;
    }

    /** The place the lot came in; its lines without a place of their own lie there. */
    public function homeOf(int $lot): int
    {
        return $this->homeOf[$lot] ?? 0;
    }

    /**
     * Everything that lies somewhere, as lot → place → quantity, only what is more than nothing.
     *
     * @return array<int, array<int, float>>
     */
    public function stockByPlace(): array
    {
        $out = [];

        foreach (array_keys($this->at) as $lot) {
            foreach ($this->placesOf($lot) as $place => $quantity) {
                if ($quantity > 0.0) {
                    $out[$lot][$place] = $quantity;
                }
            }
        }

        return $out;
    }

    public function restOf(int $lot): float
    {
        return $this->rest[$lot] ?? 0.0;
    }

    public function stockOf(int $thing): float
    {
        $sum = 0.0;

        foreach ($this->lotsOf($thing) as $lot) {
            $sum += $this->rest[$lot];
        }

        return $sum;
    }

    /**
     * The lots of a thing, oldest first (receipt date, then id).
     *
     * @return list<int>
     */
    public function lotsOf(int $thing): array
    {
        $lots = array_keys(array_filter($this->thingOf, static fn (int $t): bool => $t === $thing));

        usort($lots, fn (int $a, int $b): int => [$this->dateOf[$a], $a] <=> [$this->dateOf[$b], $b]);

        return $lots;
    }

    /**
     * The lot a consumption takes from unless the user picks another: the oldest with something left (FIFO).
     * Without any such lot the oldest lot overall — a consumption may push the stock below zero (concept §11).
     */
    public function proposedLot(int $thing): int
    {
        $lots = $this->lotsOf($thing);

        foreach ($lots as $lot) {
            if ($this->rest[$lot] > 0) {
                return $lot;
            }
        }

        return $lots[0] ?? throw StockError::noLot($thing);
    }

    public function thingOf(int $lot): ?int
    {
        return $this->thingOf[$lot] ?? null;
    }

    /** @return list<int> */
    public function things(): array
    {
        return array_values(array_unique($this->thingOf));
    }

    /**
     * The purchases received already: those some lot names as its origin.
     *
     * @return array<int, true>
     */
    public static function receivedPurchases(Structure $structure, Table $lots): array
    {
        $received = [];

        foreach ($lots->ids() as $lot) {
            $purchase = (int) $lots->first($lot, $structure->field('lot_purchase'));

            if ($purchase > 0) {
                $received[$purchase] = true;
            }
        }

        return $received;
    }
}
