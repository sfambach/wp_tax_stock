<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What lies where and how much of a material there is — the answer of the ability `taxmod-stock/stock`, so a reader does not
 * load every lot and every transaction to learn it (D-1209).
 *
 * ```mermaid
 * flowchart LR
 *   Q["place? · materials?"] --> P["place and all places inside it (LocationTree)"]
 *   P --> L["lots with a rest there (Ledger)"]
 *   L --> A["rows: lot · material · place · quantity, totals per material"]
 * ```
 *
 * ⚠️ *The quantity is the ledger's rest, as on the stock page (D-956): reserved stock still counts, it lowers what is available,
 * not what is there (D-1083).*
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1209, D-1324
 */
final class StockAnswer
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

    /**
     * @param int       $place     0 for every place
     * @param list<int> $materials empty for every material
     * @return array{total_lots: int, total_materials: int, lots: list<array{lot: int, material: int, material_word: string, place: int, place_word: string, quantity: float}>, materials: list<array{material: int, material_word: string, quantity: float, lots: int}>}
     */
    public static function of(Ledger $ledger, LocationTree $places, Things $things, int $place, bool $below, array $materials, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        $where = $place === 0 ? null : array_fill_keys($below ? $places->withAllBelow($place) : [$place], true);
        $only  = $materials === [] ? null : array_fill_keys($materials, true);

        if ($where !== null && $where === []) {
            $where = [$place => true];
        }

        $rows   = [];
        $totals = [];

        foreach ($ledger->stockByPlace() as $lot => $at) {
            $material = (int) $ledger->thingOf($lot);

            if ($only !== null && ! isset($only[$material])) {
                continue;
            }

            foreach ($at as $spot => $quantity) {
                if ($where !== null && ! isset($where[$spot])) {
                    continue;
                }

                $rows[] = ['lot' => $lot, 'material' => $material, 'material_word' => $things->word($material), 'place' => $spot, 'place_word' => $spot === 0 ? '' : $places->pathOf($spot), 'quantity' => $quantity];
                $totals[$material] ??= ['material' => $material, 'material_word' => $things->word($material), 'quantity' => 0.0, 'lots' => 0];
                $totals[$material]['quantity'] += $quantity;
                $totals[$material]['lots']++;
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['place_word'], $a['material_word'], $a['lot']] <=> [$b['place_word'], $b['material_word'], $b['lot']]);
        usort($totals, static fn (array $a, array $b): int => strnatcasecmp($a['material_word'], $b['material_word']) ?: $a['material'] <=> $b['material']);

        // D-1324: the totals are paged like the lots — the whole stock has more materials than one answer should carry.
        return [
            'total_lots'      => count($rows),
            'lots'            => array_slice($rows, max(0, $offset), $limit),
            'total_materials' => count($totals),
            'materials'       => array_slice(array_values($totals), max(0, $offset), $limit),
        ];
    }
}
