<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestRules;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Einstellungen › Testregeln (D-1541): what needs a test after the goods receipt — the rules in their order, each a
 * node (it holds for every material below), opened in the modeller's record editor; filters «Feld · Wert» follow (0.64.184).
 * The same page as the print rules: «Neue Regel», and
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
        $explain = __('Nach dem Wareneingang braucht ein gebrauchter Posten einen Test, wenn eine Regel passt: sein Material liegt unter dem Knoten der Regel. Passt keine Regel, gibt es keinen Test. Neuware geht immer ohne Test durch, «ungetestet» wartet immer.', 'taxmod-stock');
        $chosen  = absint($_GET['satz'] ?? 0);

        foreach ($rules as $rule) {
            if ($rule['id'] === $chosen) {
                return $page(ListPage::recordHead(__('Testregel', 'taxmod-stock'), $this->nodeWord($rule), self::url(), __('Testregeln', 'taxmod-stock'), $explain)
                    . (string) apply_filters('taxmod_record_editor', '', $chosen, self::url(['satz' => $chosen])));
            }
        }

        if ($rules === [] && $this->stock->testRuleProposals() === []) {
            $explain .= ' ' . __('Solange es keine Regel gibt, entscheidet noch die Gruppen-Eigenschaft «Nach Wareneingang testen».', 'taxmod-stock');
        }

        return $page(ListPage::listHead(__('Testregeln', 'taxmod-stock'), count($rules), $explain)
            . ListPage::listBar('', buttons: $this->buttons())
            . ListPage::table([[__('Nr.', 'taxmod-stock'), '4em'], [__('Knoten', 'taxmod-stock'), ''], [__('Ergebnis', 'taxmod-stock'), '8em']], $this->rows($rules)));
    }

    /** admin-post «Neue Regel» and «Gruppen übernehmen»: capability → nonce → validate → act → back with a notice (CD-5). */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);
        $do   = sanitize_key((string) ($_POST['do'] ?? ''));
        $back = self::url();

        try {
            if ($do === 'create') {
                $back = self::url(['satz' => $this->stock->createTestRule()]);
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

    /** @param list<array{id: int, position: int, conditions: array<int, int>}> $rules */
    private function rows(array $rules): string
    {
        if ($rules === []) {
            return '<tr class="no-items"><td class="colspanchange" colspan="5">' . esc_html__('Noch keine Regel.', 'taxmod-stock') . '</td></tr>';
        }

        $body = '';

        foreach ($rules as $rule) {
            $url   = self::url(['satz' => $rule['id']]);
            $body .= '<tr><th scope="row" class="check-column"></th><td>' . esc_html((string) $rule['position']) . '</td>'
                . '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($this->nodeWord($rule)) . '</strong></a></td>'
                . '<td>' . esc_html__('Test nötig', 'taxmod-stock') . '</td>'
                . '<td style="text-align:right">' . ListPage::openButton($url) . '</td></tr>';
        }

        return $body;
    }

    /** @param array{conditions: array<int, int>} $rule */
    private function nodeWord(array $rule): string
    {
        $node = $rule['conditions'][TestRules::NODE] ?? 0;

        return $node > 0 ? $this->stock->path($node) : __('alles', 'taxmod-stock');
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
