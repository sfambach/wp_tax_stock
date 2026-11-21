<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1579: a new, empty group takes materials over from the group above it — one rule of the modeller's rule book (D-1552) on
 * the parent's node, its filters «Feld · Operator · Wert» as he sets them; the rule book decides, this class only translates.
 *
 * ```mermaid
 * flowchart LR
 *   F["filter rows of the request"] -->|filters| R["one rule on the parent group"]
 *   M["materials right in the parent"] -->|values, read once| B["ModelDoor::firstRule"]
 *   R --> B
 *   B -->|holds| P["preview, ticked"]
 *   P -->|minus the unticked| A["moveThingsToGroup (D-1073), one act"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1579
 */
final class TakeOver
{
    /** At most this many filter rows; every row must hold. */
    public const ROWS = 3;

    /** The rule book's operators offered here: «liegt unter» reads nodes, and the fields offered hold plain values. */
    public const OPERATORS = ['equals', 'not_equals', 'greater', 'less', 'contains'];

    /**
     * @param list<array{field: int, operator: string, value: string}> $filters
     */
    private function __construct(public readonly int $parent, public readonly array $filters)
    {
    }

    /**
     * The filter rows as posted: a row without a field or with a field the parent does not offer is left out, an operator not
     * offered is «gleich».
     *
     * @param array<array-key, mixed> $rows    the request's `tf` rows
     * @param list<int>               $offered the parent's plain fields
     */
    public static function fromRows(int $parent, array $rows, array $offered): self
    {
        $offered = array_flip($offered);
        $out     = [];

        foreach (array_slice(array_values(array_filter($rows, 'is_array')), 0, self::ROWS) as $row) {
            $field = (int) ($row['field'] ?? 0);

            if ($field <= 0 || ! isset($offered[$field])) {
                continue;
            }

            $operator = (string) ($row['op'] ?? '');
            $out[]    = ['field' => $field, 'operator' => in_array($operator, self::OPERATORS, true) ? $operator : self::OPERATORS[0], 'value' => trim((string) ($row['value'] ?? ''))];
        }

        return new self($parent, $out);
    }

    /**
     * The one rule for the rule book: the parent's node, these filters.
     *
     * @return list<array{id: int, position: int, conditions: array<int, int>, filters: list<array{field: int, value: string, operator: string}>}>
     */
    public function book(): array
    {
        return [['id' => 1, 'position' => 0, 'conditions' => [TestRules::NODE => $this->parent], 'filters' => array_map(
            static fn (array $filter): array => ['field' => $filter['field'], 'value' => $filter['value'], 'operator' => $filter['operator']],
            $this->filters
        )]];
    }

    /** @return list<int> the fields the filters read, so each material's values are read once (CD-7) */
    public function fields(): array
    {
        return array_values(array_unique(array_column($this->filters, 'field')));
    }

    /**
     * What is left out: those unticked on earlier pages of the preview, and those shown now and left unticked — a page turned
     * forgets nothing, and a page never seen takes everything it holds (D-1545).
     *
     * @param list<int> $skipped unticked on earlier pages
     * @param list<int> $shown   the rows of this page
     * @param list<int> $ticked  those of them still ticked
     * @return list<int>
     */
    public static function skipped(array $skipped, array $shown, array $ticked): array
    {
        return array_values(array_diff(array_unique(array_merge($skipped, array_diff($shown, $ticked))), $ticked));
    }

    /**
     * What the act moves: every material the rule holds for, minus those left out.
     *
     * @param list<int> $matched
     * @param list<int> $skipped {@see self::skipped()}
     * @return list<int>
     */
    public static function chosen(array $matched, array $skipped): array
    {
        return array_values(array_diff($matched, $skipped));
    }

    /**
     * D-1579: the button stands only at an empty group with a group above it.
     *
     * @param array<int, int>   $parents group → its parent group
     * @param array<int, mixed> $groups  the groups of the material tree, by id
     * @param array<int, true>  $filled  groups holding a material
     */
    public static function offered(int $group, array $parents, array $groups, array $filled): bool
    {
        if (! isset($groups[$group]) || ! isset($groups[$parents[$group] ?? 0])) {
            return false;
        }

        return array_intersect_key(GroupStock::branch($parents, $group), $filled) === [];
    }
}
