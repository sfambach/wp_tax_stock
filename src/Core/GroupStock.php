<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1174: the stock of a material group — every material in the group and in the groups below it, and what it adds up to.
 *
 * ```mermaid
 * flowchart LR
 *   G["Gruppe Bauteile"] --> A["Aktiv"] --> T["Transistor"]
 *   G --> P["Passiv"]
 *   B["branch(Bauteile)"] -.->|holds| G & A & T & P
 * ```
 *
 * The tree is walked in memory from one read of the parents (CD-7); a loop in it ends the walk instead of hanging it.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1174
 */
final class GroupStock
{
    /**
     * The group and every group below it.
     *
     * @param array<int, int> $parents group → its parent group
     * @return array<int, true> group → true
     */
    public static function branch(array $parents, int $group): array
    {
        $children = [];

        foreach ($parents as $node => $parent) {
            $children[$parent][] = $node;
        }

        $branch = [$group => true];
        $open   = [$group];

        while ($open !== []) {
            foreach ($children[array_pop($open)] ?? [] as $child) {
                if (! isset($branch[$child])) {
                    $branch[$child] = true;
                    $open[]         = $child;
                }
            }
        }

        return $branch;
    }

    /**
     * What the rows of a group add up to. Quantities are summed only when every row with stock counts in the same unit;
     * otherwise the sum would mix pieces and metres, and it is left out.
     *
     * @param list<array{stock: float, low: bool, unit?: string}> $rows
     * @return array{materials: int, inStock: int, low: int, quantity: float|null}
     */
    public static function totals(array $rows): array
    {
        $inStock = array_values(array_filter($rows, static fn (array $row): bool => $row['stock'] > 0.0));
        $units   = array_unique(array_map(static fn (array $row): string => $row['unit'] ?? '', $inStock));

        return [
            'materials' => count($rows),
            'inStock'   => count($inStock),
            'low'       => count(array_filter($rows, static fn (array $row): bool => $row['low'])),
            'quantity'  => count($units) > 1 ? null : (float) array_sum(array_map(static fn (array $row): float => $row['stock'], $inStock)),
        ];
    }
}
