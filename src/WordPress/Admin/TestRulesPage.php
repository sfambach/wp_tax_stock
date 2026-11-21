<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestRules;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Einstellungen › Testregeln (D-1541): what needs a test after the goods receipt — the rules in their order, each a
 * node with filters «Feld · Wert», opened in the modeller's record editor. The same page as the print rules: «Neue Regel», and
 * «Gruppen übernehmen», which lists what it would write before it writes it — the groups marked «Nach Wareneingang testen».
 *
 * ```mermaid
 * flowchart LR
 *   L["Liste: Regeln nach Position"] -->|"Auge"| E["Satz-Editor des Modellers"]
 *   L -->|"Neue Regel"| E
 *   G["Gruppen mit «Nach Wareneingang testen»"] -->|"Gruppen übernehmen: ein Akt"| L
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1541
 */
final class TestRulesPage
{
    public const PAGE   = Pages::TEST_RULES;
    public const ACTION = 'taxmod_stock_test_rules';

    private const BULK_FORM = 'taxmod-stock-test-rules-bulk';

    private const NOTICE = 'taxmod_stock_test_rules_notice_';

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /** @param array<string, int|string> $args */
    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => self::PAGE] + $args, admin_url('admin.php'));
    }

    public function render(): string
    {
        $page = static fn (string $body): string => ListPage::page(__('Testregeln', 'taxmod-stock'), [], [['html' => Notice::take(self::NOTICE, true) . $body]], (string) TAXMOD_STOCK_VERSION);
        $s    = $this->stock->structure();

        if (! TestRules::isConfigured($s)) {
            return $page('<div class="notice notice-warning inline"><p>' . esc_html__('Im Modell gibt es noch keinen Knoten «Testregeln». Bis dahin entscheidet die Gruppen-Eigenschaft «Nach Wareneingang testen».', 'taxmod-stock') . '</p></div>');
        }

        $rules   = $this->stock->testRules()->book;
        $explain = __('Nach dem Wareneingang braucht ein gebrauchter Posten einen Test, wenn eine Regel passt: sein Material liegt unter dem Knoten der Regel, und jeder Filter «Feld · Wert» stimmt. Passt keine Regel, gibt es keinen Test. Neuware geht immer ohne Test durch, «ungetestet» wartet immer.', 'taxmod-stock');
        $chosen  = absint($_GET['satz'] ?? 0);

        foreach ($rules as $rule) {
            if ($rule['id'] === $chosen) {
                // His answer 21.11.2026 07:19: «Es muss ein Knoten ausgewählt werden» — the record editor saves it, so the error stands here after the save until one is chosen.
                // His word 08:21: not already when a rule is created — only once it was saved without a node.
                $missing = ($rule['conditions'][TestRules::NODE] ?? 0) > 0 || empty($_GET['gespeichert']) ? '' : '<div class="notice notice-error inline"><p>' . esc_html__('Es muss ein Knoten ausgewählt werden. Ohne Knoten greift die Regel nicht.', 'taxmod-stock') . '</p></div>';

                return $page($missing . ListPage::recordHead(__('Testregel', 'taxmod-stock'), $this->ruleWord($rule), self::url(), __('Testregeln', 'taxmod-stock'), $explain)
                    . (string) apply_filters('taxmod_record_editor', '', $chosen, self::url(['satz' => $chosen, 'gespeichert' => 1]), [], $this->roots()));
            }
        }

        if ($rules === [] && $this->stock->testRuleProposals() === []) {
            $explain .= ' ' . __('Solange es keine Regel gibt, entscheidet noch die Gruppen-Eigenschaft «Nach Wareneingang testen».', 'taxmod-stock');
        }

        return $page(ListPage::listHead(__('Testregeln', 'taxmod-stock'), count($rules), $explain)
            . $this->bulkBar()
            . ListPage::table([[__('Nr.', 'taxmod-stock'), '4em'], [__('Regel', 'taxmod-stock'), '14em'], [__('Knoten', 'taxmod-stock'), ''], [__('Filter', 'taxmod-stock'), ''], [__('Ergebnis', 'taxmod-stock'), '8em']], $this->rows($rules)));
    }

    /**
     * D-1546: the rule's node is chosen under the branches «Material steht in» names (D-1522), never under a wired branch.
     *
     * @return array<int, list<int>> relation id → roots
     */
    private function roots(): array
    {
        $s     = $this->stock->structure();
        $roots = $this->stock->materialRoots();

        return $roots === [] || ! $s->hasField('test_rule_node') ? [] : [$s->field('test_rule_node') => $roots];
    }

    /** admin-post «Neue Regel» and «Gruppen übernehmen»: capability → nonce → validate → act → back with a notice (CD-5). */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);
        $do   = sanitize_key((string) ($_POST['do'] ?? '')) ?: sanitize_key((string) ($_POST['bulk'] ?? ''));
        $back = self::url();

        try {
            if ($do === 'create') {
                $back = self::url(['satz' => $this->stock->createTestRule()]);
            } elseif ($do === 'remove') {
                $chosen = array_values(array_unique(array_map('absint', (array) wp_unslash($_POST['selected'] ?? []))));
                $this->stock->removeTestRules($chosen);
                $n = count($chosen);
                Notice::remember('success', sprintf(/* translators: %d: number of rules */ _n('%d Testregel gelöscht.', '%d Testregeln gelöscht.', $n, 'taxmod-stock'), $n), self::NOTICE);
            } elseif ($do === 'takeover') {
                $n = $this->stock->takeOverTestRules();
                Notice::remember('success', sprintf(/* translators: %d: number of groups */ _n('%d Gruppe als Testregel übernommen.', '%d Gruppen als Testregeln übernommen.', $n, 'taxmod-stock'), $n), self::NOTICE);
            } else {
                throw StockError::nothingToBook();
            }
        } catch (StockError $e) {
            Notice::remember('error', Messages::of($e), self::NOTICE);
        }

        wp_safe_redirect($back);
        exit;
    }

    /** @param list<array{id: int, name: string, position: int, conditions: array<int, int>, filters: list<array{field: int, value: string, operator: string}>}> $rules */
    private function rows(array $rules): string
    {
        if ($rules === []) {
            return '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__('Noch keine Regel.', 'taxmod-stock') . '</td></tr>';
        }

        $body = '';

        foreach ($rules as $rule) {
            $url   = self::url(['satz' => $rule['id']]);
            $names = $this->fieldNames($rule['conditions'][TestRules::NODE] ?? 0);
            $when  = array_map(static fn (array $f): string => sprintf('%s %s %s', $names[$f['field']] ?? '#' . $f['field'], $f['operator'] === TestRules::EQUALS ? '=' : '?', $f['value']), $rule['filters']);
            $body .= '<tr><th scope="row" class="check-column"><input type="checkbox" name="selected[]" form="' . esc_attr(self::BULK_FORM) . '" value="' . esc_attr((string) $rule['id']) . '"></th><td>' . esc_html((string) $rule['position']) . '</td>'
                . '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($this->ruleWord($rule)) . '</strong></a></td>'
                . '<td>' . esc_html($this->nodeWord($rule)) . '</td>'
                . '<td>' . esc_html(implode(' · ', $when) ?: '—') . '</td>'
                . '<td>' . esc_html(($rule['conditions'][TestRules::NODE] ?? 0) > 0 ? __('Test nötig', 'taxmod-stock') : '—') . '</td>'
                . '<td style="text-align:right">' . ListPage::openButton($url) . '</td></tr>';
        }

        return $body;
    }

    /** The name is optional (his word 21.11.2026): without one the rule is called by its node. @param array{name: string, conditions: array<int, int>} $rule */
    private function ruleWord(array $rule): string
    {
        return $rule['name'] !== '' ? $rule['name'] : $this->nodeWord($rule);
    }

    /** @param array{conditions: array<int, int>} $rule */
    private function nodeWord(array $rule): string
    {
        $node = $rule['conditions'][TestRules::NODE] ?? 0;

        return $node > 0 ? $this->stock->path($node) : __('ohne Knoten – greift nicht', 'taxmod-stock');
    }

    /** @return array<int, string> relation id → the field's name at the rule's node */
    private function fieldNames(int $node): array
    {
        if ($node <= 0) {
            return [];
        }

        try {
            return array_map(static fn (array $field): string => $field['name'], $this->stock->fieldsOf($node));
        } catch (StockError) {
            return [];
        }
    }

    /**
     * D-1403: «Löschen» in the list's bulk actions, the page's own buttons beside it in the same row. The shared bar posts its
     * choice as «action», which admin-post routes on: the handler's name goes in its place, the choice travels as «bulk».
     */
    private function bulkBar(): string
    {
        $bar = ListPage::bulkBar(self::BULK_FORM, self::ACTION, ['remove' => __('Löschen', 'taxmod-stock')], '', '<div class="alignleft actions">' . $this->buttons() . '</div>', false);

        $bar = str_replace('name="action" id="' . self::BULK_FORM . '-action"', 'name="bulk" id="' . self::BULK_FORM . '-action"', $bar);

        // Only the bar's own form — the buttons beside it carry forms of their own.
        return (string) preg_replace('#</form>#', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"></form>', $bar, 1);
    }

    /** «Neue Regel», and «Gruppen übernehmen» with the list of what it would write — the dry run is the dialog itself. */
    private function buttons(): string
    {
        $form   = static fn (string $inner): string => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false) . $inner . '</form>';
        $create = $form('<button type="submit" class="button" name="do" value="create">' . esc_html__('Neue Regel', 'taxmod-stock') . '</button>');
        $open   = $this->stock->testRuleProposals();

        if ($open === []) {
            return $create;
        }

        // Prüftor 2026-11-21: each group shows the groups below it, so it is seen before writing what falls under it (e.g. resistors).
        $below = $this->stock->groupsBelow($open);
        $list  = implode('', array_map(fn (int $group): string => '<li><strong>' . esc_html($this->stock->path($group)) . '</strong>'
            . (($below[$group] ?? []) === [] ? '' : '<br><span class="description">' . esc_html(sprintf(/* translators: %s: the groups below, comma separated */ __('darunter: %s', 'taxmod-stock'), implode(', ', $below[$group]))) . '</span>') . '</li>', $open));

        return $create . ' ' . $form(ListPage::dialogAction('taxmod-stock-test-takeover', sprintf(/* translators: %d: number of groups */ __('Gruppen übernehmen (%d)', 'taxmod-stock'), count($open)), __('Gruppen übernehmen', 'taxmod-stock'),
            '<p>' . esc_html__('Diese Gruppen tragen «Nach Wareneingang testen». Sie werden als Testregeln angelegt, hinter den vorhandenen, in einem Schritt (rückgängig machbar). Danach entscheiden nur noch die Regeln.', 'taxmod-stock') . '</p>'
            . '<ol style="max-height:40vh;overflow:auto">' . $list . '</ol>',
            'do', 'takeover', __('Übernehmen', 'taxmod-stock'), wide: true));
    }
}
