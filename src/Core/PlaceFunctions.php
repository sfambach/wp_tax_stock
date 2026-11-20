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
    public const OUTBOUND = 'function_outbound';
    public const INBOUND = 'function_inbound';
    /** D-1503: where a lot waits for its test, and where what failed it waits for a decision. */
    public const TEST = 'function_test';
    public const CLARIFY = 'function_clarify';

    /** Every function key this class reads, in the order of the list «Platzfunktionen». */
    public const KEYS = ['function_storage', self::INBOUND, self::OUTBOUND, self::TEST, self::CLARIFY];

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
     * D-1503: the topmost place that has both functions — «Wareneingang › Test» is a test place inside the goods receipt. A
     * place inside it has them too and is skipped; of several, the lowest id.
     *
     * @param array<int, int> $parents place → its parent place
     */
    public function firstWithBoth(string $key, string $also, array $parents): ?int
    {
        $both = array_values(array_intersect($this->with($key), $this->with($also)));

        foreach ($both as $place) {
            if (! in_array($parents[$place] ?? 0, $both, true)) {
                return $place;
            }
        }

        return null;
    }

    /**
     * D-1503: the topmost place with this function that lies nearest to `$from` — the one sharing the deepest ancestor with
     * it; without a shared one, the lowest id. A place inside a place with the function is skipped.
     *
     * ```mermaid
     * flowchart LR
     *   F["from: Wareneingang › Test"] --> A["its ancestors"] --> C{"a Klärung below the nearest?"}
     *   C -->|yes| K["that Klärung"]
     *   C -->|no| L["lowest Klärung"]
     * ```
     *
     * @param array<int, int> $parents place → its parent place
     */
    public function nearest(string $key, int $from, array $parents): ?int
    {
        $all = $this->with($key);
        $top = array_values(array_filter($all, static fn (int $p): bool => ! in_array($parents[$p] ?? 0, $all, true)));

        if ($top === []) {
            return null;
        }

        $chain = static function (int $at) use ($parents): array {
            for ($out = [], $seen = []; $at > 0 && ! isset($seen[$at]); $at = $parents[$at] ?? 0) {
                $out[] = $at;
                $seen[$at] = true;
            }

            return $out;
        };

        foreach ($chain($from) as $ancestor) {
            foreach ($top as $place) {
                if (in_array($ancestor, $chain($place), true)) {
                    return $place;
                }
            }
        }

        return $top[0];
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
