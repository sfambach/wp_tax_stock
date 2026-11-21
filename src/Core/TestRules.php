<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The test rules as the modeller's one rule book reads them (D-1541): each rule a node — it holds for every material below —
 * plus filters «Feld · Operator · Wert». Which rule holds is decided by the rule book (`ModelDoor::firstRule`), never here;
 * this class only translates. A rule that holds means «Test nötig»; none holds → «kein Test».
 *
 * ```mermaid
 * flowchart LR
 *   R["Testregeln records + their filters"] -->|book| B["ModelDoor::firstRule"]
 *   M["material: its node with every node above, its values"] -->|context| B
 *   B -->|rule id| T["Test nötig"]
 *   B -->|null| N["kein Test"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1541
 */
final class TestRules
{
    /** The one condition key: the rule's node. */
    public const NODE = 1;

    /** The rule book's word for the one operator built (D-1541); any other never holds. */
    public const EQUALS = 'equals';

    /**
     * @param list<array{id: int, name: string, position: int, conditions: array<int, int>, filters: list<array{field: int, value: string, operator: string}>}> $book
     */
    private function __construct(public readonly array $book)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** Whether the model holds the rules: the node and its fields are named in the structure. */
    public static function isConfigured(Structure $s): bool
    {
        return $s->hasNode('test_rule') && $s->hasField('test_rule_node');
    }

    /**
     * One record of «Testregeln» each; its filters are records of their own, held by the rule (composition).
     *
     * @param Table|null $filters the filter records, null when the structure has none
     */
    public static function fromTables(Structure $s, Table $rules, ?Table $filters): self
    {
        $equals  = $s->hasValue('operator_equals') ? (string) $s->value('operator_equals') : '';
        $int     = static fn (Table $t, int $id, string $key): int => $s->hasField($key) ? (int) ($t->first($id, $s->field($key)) ?? 0) : 0;
        $text    = static fn (Table $t, int $id, string $key): string => $s->hasField($key) ? trim((string) ($t->first($id, $s->field($key)) ?? '')) : '';
        $book    = [];

        foreach ($rules->ids() as $id) {
            $own = [];

            foreach ($filters !== null && $s->hasField('test_rule_filters') ? $rules->all($id, $s->field('test_rule_filters')) : [] as $filter) {
                $filter   = (int) $filter;
                $field    = $int($filters, $filter, 'test_filter_field');
                $operator = $text($filters, $filter, 'test_filter_operator');

                if ($field > 0) {
                    // An empty operator is «gleich»; one not built yet is passed on as it is and never holds (D-1541).
                    $own[] = ['field' => $field, 'value' => $text($filters, $filter, 'test_filter_value'), 'operator' => $operator === '' || $operator === $equals ? self::EQUALS : $operator];
                }
            }

            $book[] = ['id' => $id, 'name' => $text($rules, $id, 'label'), 'position' => $int($rules, $id, 'test_rule_position'), 'conditions' => [self::NODE => $int($rules, $id, 'test_rule_node')], 'filters' => $own];
        }

        return new self($book);
    }

    /**
     * The rules that may hold: a rule needs its node (his word 21.11.2026, «es muss ein Knoten ausgewählt werden») — one still
     * without, such as a new one not yet saved, never holds, so it cannot send everything to the test.
     *
     * @return list<array{id: int, name: string, position: int, conditions: array<int, int>, filters: list<array{field: int, value: string, operator: string}>}>
     */
    public function holding(): array
    {
        return array_values(array_filter($this->book, static fn (array $rule): bool => ($rule['conditions'][self::NODE] ?? 0) > 0));
    }

    public function isEmpty(): bool
    {
        return $this->book === [];
    }

    /** @return list<int> the fields the filters read, so the caller reads each material's values once (CD-7) */
    public function fields(): array
    {
        $out = [];

        foreach ($this->book as $rule) {
            foreach ($rule['filters'] as $filter) {
                $out[$filter['field']] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * What counts for a material: its node with every node above it, so a rule on «Widerstände» holds for each resistor below.
     *
     * @param array<int, int> $parents node → parent, the tree of the groups
     * @return array<int, list<int>>
     */
    public static function context(?int $node, array $parents): array
    {
        $chain = [];

        for ($at = (int) $node, $seen = []; $at > 0 && ! isset($seen[$at]); $at = $parents[$at] ?? 0) {
            $chain[]   = $at;
            $seen[$at] = true;
        }

        return [self::NODE => $chain];
    }
}
