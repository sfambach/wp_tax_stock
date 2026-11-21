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

    /** @return array{nodes: list<int>, place_type: int, record?: int} */
    public static function forPlace(int $locationNode, int $type, int $place = 0): array
    {
        // D-1563 (2): the place rides along as the record, so a rule's field filters read the place's own values.
        return ['nodes' => [$locationNode], 'place_type' => $type] + ($place > 0 ? ['record' => $place] : []);
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
