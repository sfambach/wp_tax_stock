<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What a place is for (D-1528, W6): its place type's «Platzfunktion» — Lager, Wareneingang, Warenausgang, Test, Klärung. A place
 * without a type or a type without a function is a storage place. A place inside a place with a function has that function.
 *
 * ```mermaid
 * flowchart LR
 *   T["Platzart: Platzfunktion"] --> P["Lagerort: Platzart"] --> B["und alle Orte darunter"]
 * ```
 *
 * Stock lying on a Warenausgang place is «bereitgestellt»: it belongs to a sale and is not available.
 */
final class PlaceFunctions
{
    /** The functions read here; «Test» and «Klärung» stand in the list but nothing reads them until D-1503 is decided. */
    public const OUTBOUND = 'function_outbound';
    public const INBOUND = 'function_inbound';

    /** @param array<string, list<int>> $places function key → the places with it, those below included */
    private function __construct(private readonly array $places)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * One pass over the places and their types (CD-7).
     *
     * @param array<int, int>    $typeOf     place → its place type, 0 for none
     * @param array<int, int>    $functionOf place type → its function's constant id
     * @param array<int, string> $keyOf      constant id → function key
     * @param \Closure(int): list<int> $withAllBelow
     */
    public static function from(array $typeOf, array $functionOf, array $keyOf, \Closure $withAllBelow): self
    {
        $places = [];

        foreach ($typeOf as $place => $type) {
            $key = $keyOf[$functionOf[$type] ?? 0] ?? null;

            if ($key !== null) {
                foreach ([$place, ...$withAllBelow($place)] as $one) {
                    $places[$key][$one] = true;
                }
            }
        }

        return new self(array_map(static fn (array $set): array => array_keys($set), $places));
    }

    /** @return list<int> the places with this function, those inside them included, ascending */
    public function with(string $key): array
    {
        $out = $this->places[$key] ?? [];
        sort($out);

        return $out;
    }

    public function is(int $place, string $key): bool
    {
        return in_array($place, $this->places[$key] ?? [], true);
    }

    /**
     * What lies on the Warenausgang places, per material — not available any more (D-1528).
     *
     * @param array<int, array<int, float>> $stockByPlace lot → place → pieces
     * @param \Closure(int): ?int           $thingOf      lot → its material
     * @return array<int, float> material → pieces
     */
    public function providedByThing(array $stockByPlace, \Closure $thingOf): array
    {
        $outbound = array_flip($this->places[self::OUTBOUND] ?? []);
        $out      = [];

        if ($outbound === []) {
            return [];
        }

        foreach ($stockByPlace as $lot => $at) {
            $here = array_sum(array_intersect_key($at, $outbound));
            $thing = $thingOf($lot);

            if ($here > 0.0 && $thing !== null) {
                $out[$thing] = ($out[$thing] ?? 0.0) + $here;
            }
        }

        return $out;
    }
}
