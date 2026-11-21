<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1519: what the stock asks the print rules about one label — the node the record lies in and those above it, the Bauform and
 * the group with theirs, the place type. The paths are walked in trees already read, so a list asks without one more read (CD-7).
 *
 * ```mermaid
 * flowchart LR
 *   M["material"] -->|nodes = groups| Q["question"]
 *   L["lot"] -->|nodes = «Bestandsposten»| Q
 *   P["place"] -->|nodes = «Lagerorte», place type| Q
 *   S["where its lots lie"] -->|stock, D-1576| Q
 *   Q --> R["labels module: the first rule that holds"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class LabelQuestion
{
    /**
     * @param array<int, array{parent: int}> $groups the material tree
     * @param array<int, array{parent: int}> $forms  the Bauform tree
     * @return array{nodes: list<int>, groups: list<int>, forms: list<int>}
     */
    public static function forMaterial(array $groups, int $group, array $forms, int $form): array
    {
        $path = self::path($groups, $group);

        return ['nodes' => $path, 'groups' => $path, 'forms' => self::path($forms, $form)];
    }

    /**
     * A lot is a record of its own node, labelled by what its material is.
     *
     * @param array<int, array{parent: int}> $groups
     * @param array<int, array{parent: int}> $forms
     * @return array{nodes: list<int>, groups: list<int>, forms: list<int>}
     */
    public static function forLot(int $lotNode, array $groups, int $group, array $forms, int $form): array
    {
        return ['nodes' => [$lotNode]] + self::forMaterial($groups, $group, $forms, $form);
    }

    /**
     * D-1563 (2): the place rides along as the record, so a rule's field filters read the place's own values; its branch in the
     * storage structure — the place, the places above it, then the node «Lagerorte» — is what a rule's node is asked against, as
     * a material's group path is (Prüftor 21.11.). The place type stays a filter's matter, never the node's.
     *
     * D-1570: the place type's own label and count ride along as `from` (field → value), so a rule that reads them from there
     * needs no query per place; a value the type leaves empty is not sent.
     *
     * @param list<int>       $above the places above `$place`, nearest first
     * @param array<int, int> $from  field → the place type's value
     * @return array{nodes: list<int>, place_type: int, record?: int, groups?: list<int>, from?: array<int, int>}
     */
    public static function forPlace(int $locationNode, int $type, int $place = 0, array $above = [], array $from = []): array
    {
        $out  = ['nodes' => [$locationNode], 'place_type' => $type];
        $from = array_filter($from, static fn (int $value, int $field): bool => $field > 0 && $value > 0, ARRAY_FILTER_USE_BOTH);
        $out += $from === [] ? [] : ['from' => $from];

        return $place > 0 ? $out + ['record' => $place, 'groups' => [$place, ...$above, $locationNode]] : $out;
    }

    /**
     * D-1576: where the stock lies, for a rule filter on the lot's «Lagerort» — each place a lot of it lies at (a lot label: that
     * lot's places; a material label: those of every lot of it) and every place above it, so «liegt unter» a box holds for a lot
     * in one of its compartments. A material with nothing in stock sends nothing: the filter does not hold.
     *
     * @param list<int> $places where it lies
     * @return array{stock?: array<int, list<string>>}
     */
    public static function lyingAt(int $field, array $places, LocationTree $tree): array
    {
        $out = [];
        foreach ($places as $place) {
            for ($at = $place; $at > 0 && ! isset($out[$at]); $at = $tree->parentOf($at)) {
                $out[$at] = (string) $at;
            }
        }

        return $field > 0 && $out !== [] ? ['stock' => [$field => array_values($out)]] : [];
    }

    /**
     * The node and every node above it, nearest first; the walk ends at the first node the tree does not hold (its root) and
     * never runs in a circle.
     *
     * @param array<int, array{parent: int}> $tree
     * @return list<int>
     */
    public static function path(array $tree, int $node): array
    {
        $out = [];
        for ($at = $node; $at > 0 && ! in_array($at, $out, true); $at = (int) ($tree[$at]['parent'] ?? 0)) {
            $out[] = $at;
        }

        return $out;
    }
}
