<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

/**
 * The open proposals of the old ways as the model's operations, read only (D-1558, INF-137): the groups marked «Nach
 * Wareneingang testen» become test rules, today's label preselections become print rules. The answer is a data job for the
 * queue — written there with dry_run and undo_act, never here. Once it ran, the old ways go.
 *
 * ```mermaid
 * flowchart LR
 *   G["groups with «Nach Wareneingang testen»"] --> T["create_record Testregeln: Knoten, Position"]
 *   V["preselections: Bauform, Gruppe, Platzart"] -->|taxmod_print_rule_takeover| P["the labels module's operations"]
 *   T --> J["changes: one act"]
 *   P --> J
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/neues-konzept-eingang.md INF-137
 */
final class RuleProposalsAbility
{
    public const NAME = 'taxmod-stock/rule-proposals';

    /**
     * `apply_filters(PRINT_FILTER, null, $proposals)`: the labels module's answer `{changes, open}` — the operations for the print
     * proposals not yet a rule, and those left open with their reason (Prüftor 21.11.: «für» goes to the rule's «Wofür»).
     */
    public const PRINT_FILTER = 'taxmod_print_rule_takeover';

    public function register(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability(self::NAME, [
            'label'               => __('Read the open rule proposals', 'taxmod-stock'),
            'description'         => __('The proposals of the old ways as one data job, read only: test lists the groups marked «Nach Wareneingang testen» that are no test rule yet (group, path), print lists today\'s label preselections, changes is the list of operations for taxmod/apply that makes them rules. Nothing is written.', 'taxmod-stock'),
            'category'            => 'taxmod',
            'input_schema'        => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->answer(),
            'permission_callback' => static fn (): bool => current_user_can(\Taxmod\WordPress\Rights::CONFIGURE),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /** @return array{test: list<array{group: int, path: string}>, print: list<array<string, mixed>>, changes: list<array<string, mixed>>}|\WP_Error `print` rows carry `open` with the reason where no operation is made */
    public function answer(): array|\WP_Error
    {
        try {
            $stock = new StockService();
            $s     = $stock->structure();
            $test  = \TaxmodStock\Core\TestRules::isConfigured($s) ? $stock->testRuleProposals() : [];
            $next  = max([0, ...array_map(static fn (array $rule): int => $rule['position'], $stock->testRules()->book)]);
            $ops   = [];

            foreach ($test as $i => $group) {
                $values = [(string) $s->field('test_rule_node') => (string) $group];

                if ($s->hasField('test_rule_position')) {
                    $values[(string) $s->field('test_rule_position')] = (string) ($next + $i + 1);
                }

                $ops[] = ['op' => 'create_record', 'ref' => 'testregel' . $i, 'node' => (string) $s->node('test_rule'), 'values' => $values];
            }

            $print  = $stock->labelRuleProposals();
            $answer = apply_filters(self::PRINT_FILTER, null, $print);
            $reason = [
                /* translators: %s: what the preselection was for (Material, Posten, Lagerort) */
                'for'       => __('Zuordnung von «%s» offen', 'taxmod-stock'),
                'structure' => __('Feld oder Filterart fehlt in der Struktur', 'taxmod-stock'),
            ];

            foreach (is_array($answer) && is_array($answer['open'] ?? null) ? $answer['open'] : [] as $open) {
                $i = (int) ($open['proposal'] ?? -1);

                if (isset($print[$i])) {
                    $for              = (int) ($print[$i]['for'] ?? 0);
                    $word             = $for > 0 ? ($stock->path($for) ?: '#' . $for) : '';
                    $print[$i]['open'] = sprintf($reason[(string) ($open['reason'] ?? '')] ?? '%s', $word);
                }
            }

            return [
                'test'    => array_map(static fn (int $group): array => ['group' => $group, 'path' => $stock->path($group)], $test),
                'print'   => $print,
                'changes' => array_merge($ops, is_array($answer) && is_array($answer['changes'] ?? null) ? array_values($answer['changes']) : []),
            ];
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('taxmod_stock_refused', $e->getMessage());
        }
    }
}
