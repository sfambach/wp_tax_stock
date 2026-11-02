<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What lies at each storage place: the lots with a rest there and their materials (design 2, «Lager (Stammdaten)»).
 *
 * A lot may lie at several places since the Umbuchung moves parts of it (D-1039, D-1082); it counts at each. What lies at
 * no known place is counted under 0, so nothing in stock goes missing from the overview.
 */
final class LocationStock
{
    /**
     * @param array<int, string> $locations place id → name
     * @return array<int, array{name: string, lots: int, things: list<int>}> place id → what lies there; every place, empty ones too
     */
    public static function of(Structure $s, Table $lots, Ledger $ledger, array $locations): array
    {
        $out = [];

        foreach ($locations as $id => $name) {
            $out[$id] = ['name' => $name, 'lots' => 0, 'things' => []];
        }

        foreach ($ledger->stockByPlace() as $lot => $places) {
            $thing = (int) $lots->first($lot, $s->field('lot_thing'));

            foreach (array_keys($places) as $place) {
                $place = isset($out[$place]) ? $place : 0;
                $out[$place] ??= ['name' => '', 'lots' => 0, 'things' => []];
                $out[$place]['lots']++;

                if ($thing > 0 && ! in_array($thing, $out[$place]['things'], true)) {
                    $out[$place]['things'][] = $thing;
                }
            }
        }

        return $out;
    }
}
