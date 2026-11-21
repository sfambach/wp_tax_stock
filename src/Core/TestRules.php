<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The test rules as the modeller's one rule book reads them (D-1541): each rule a node — it holds for every material below.
 * Which rule holds is decided by the rule book (`ModelDoor::firstRule`), never here; this class only translates. A rule that
 * holds means «Test nötig»; none holds → «kein Test». The filters «Feld · Operator · Wert» follow with the modeller's
 * field reference (Prüftor 2026-11-21: 0.2.195, Lager 0.64.184).
 *
 * ```mermaid
 * flowchart LR
 *   R["Testregeln records"] -->|book| B["ModelDoor::firstRule"]
 *   M["material: its node with every node above"] -->|context| B
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

    /**
     * @param list<array{id: int, position: int, conditions: array<int, int>}> $book
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

    /** One record of «Testregeln» each: its position and its node; a rule without a node holds for every material. */
    public static function fromTable(Structure $s, Table $rules): self
    {
        $int  = static fn (int $id, string $key): int => $s->hasField($key) ? (int) ($rules->first($id, $s->field($key)) ?? 0) : 0;
        $book = [];

        foreach ($rules->ids() as $id) {
            $book[] = ['id' => $id, 'position' => $int($id, 'test_rule_position'), 'conditions' => [self::NODE => $int($id, 'test_rule_node')]];
        }

        return new self($book);
    }

    public function isEmpty(): bool
    {
        return $this->book === [];
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
