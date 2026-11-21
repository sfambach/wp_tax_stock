<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\RulesPage;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * The Lager's part of the one rules page (D-1558): it registers the page, says where the rules root and the shared fields are,
 * and what its own type «Testregeln» needs — the position, the chooser's roots, the explanation. What a test rule decides stays
 * in {@see StockService::testRequired()}.
 *
 * ```mermaid
 * flowchart LR
 *   S["Lager structure: rules_root, test_rule …"] -->|taxmod_rules_setup| P["RulesPage (modeller)"]
 *   T["Testregeln: position, roots, hint"] -->|taxmod_rule_types| P
 *   P --> M["Einstellungen › Regeln › one item per type; Stammdaten › Test › Testregeln"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1558
 */
final class RulesSetup
{
    public static function boot(): void
    {
        add_filter(RulesPage::SETUP_FILTER, static fn (mixed $setup): mixed => $setup ?? self::setup());
        add_filter(RulesPage::TYPES_FILTER, static fn (array $types): array => $types + self::testType());
    }

    /** @return array{root: int, name: int, node: int, filters: int, filter_fields: array{kind: int, field: int, operator: int, value: int}}|null */
    private static function setup(): ?array
    {
        try {
            $s = (new StockService())->structure();
        } catch (StockError) {
            return null;
        }

        if (! $s->hasNode('rules_root') || ! $s->hasField('test_rule_node')) {
            return null;
        }

        $field = static fn (string $key): int => $s->hasField($key) ? $s->field($key) : 0;

        return ['plugin' => __('Logistik', 'taxmod-stock'), 'version' => (string) TAXMOD_STOCK_VERSION, 'root' => $s->node('rules_root'), 'name' => $field('label'), 'node' => $field('test_rule_node'), 'filters' => $field('test_rule_filters'),
            // The order a filter reads (D-1552): «Art · Feld · Operator · Wert».
            'filter_fields' => ['kind' => $field('test_filter_kind'), 'field' => $field('test_filter_field'), 'operator' => $field('test_filter_operator'), 'value' => $field('test_filter_value')]];
    }

    /** @return array<int, array{position: int, roots: array<int, list<int>>, hint: string, one: string}> */
    private static function testType(): array
    {
        try {
            $stock = new StockService();
            $s     = $stock->structure();
        } catch (StockError) {
            return [];
        }

        if (! $s->hasNode('test_rule')) {
            return [];
        }

        $roots = $stock->materialRoots();

        return [$s->node('test_rule') => [
            'position' => $s->hasField('test_rule_position') ? $s->field('test_rule_position') : 0,
            // D-1546: the rule's node is chosen under the branches «Material steht in» names (D-1522).
            'roots'    => $roots === [] || ! $s->hasField('test_rule_node') ? [] : [$s->field('test_rule_node') => $roots],
            'hint'     => __('Nach dem Wareneingang braucht ein gebrauchter Posten einen Test, wenn eine Regel passt: sein Material liegt unter dem Knoten der Regel, und jeder Filter stimmt — je nach Art auf ein Feld des Materials, des Postens, seines Einkaufs oder des Vorgangs, der ihn gebracht hat. Passt keine Regel, gibt es keinen Test. Neuware geht immer ohne Test durch, «ungetestet» wartet immer.', 'taxmod-stock'),
            'one'      => __('Testregel', 'taxmod-stock'),
        ]];
    }

    /** The test rules' tab on the one page: the type node, '' while there is none. */
    public static function testRulesTab(): string
    {
        try {
            $s = (new StockService())->structure();
        } catch (StockError) {
            return '';
        }

        return $s->hasNode('test_rule') ? (string) $s->node('test_rule') : '';
    }

    /**
     * His word 21.11.2026: «in Einstellungen einen Regeln … Punkt … darunter dann die unterschiedlichen Regeln» — one item per
     * child of the rules root, each opening the page with that type.
     *
     * @return list<array<string, mixed>>
     */
    public static function menuItems(): array
    {
        static $items = null;

        if ($items !== null) {
            return $items;
        }

        $page  = RulesPage::fromSetup();
        $group = __('Regeln', 'taxmod-stock');
        $items = [];

        try {
            foreach ($page?->types() ?? [] as $type => $name) {
                $items[] = ['key' => 'rules-' . $type, 'label' => $name, 'page' => RulesPage::SLUG, 'tab' => (string) $type, 'path' => [$group]];
            }
        } catch (\Throwable) {
            // Without the modeller's tree the group stays away; the page itself says what is missing.
        }

        return $items;
    }
}
