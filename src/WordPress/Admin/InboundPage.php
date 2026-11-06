<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestResult;
use TaxmodStock\WordPress\InboundService;

/**
 * The page «Wareneingang», under the Taxmod menu (D-1028): every incoming order in one stage, a tab per stage (D-1001) —
 * Bestellt → Versendet → Angekommen → Eingebucht, like the eBay shipping page the other way round (D-985).
 *
 * Drawn with the modeller's shared list page (D-989). «Angekommen» is the goods receipt check: its details hold the
 * booking form of the Lager page, so there is one receipt and not two. Every button posts to one handler, in the order
 * of CD-5, and writes in one call.
 *
 * ```mermaid
 * flowchart LR
 *   O["Bestellt"] -->|tracking number| S["Versendet"] -->|delivered · button| A["Angekommen"] -->|receipt| B["Eingebucht"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md
 */
final class InboundPage
{
    /** The cockpit keeps the slug of the former menu «Logistik» (D-1004, D-1028). */
    public const PAGE   = \TaxmodStock\WordPress\Plugin::MENU;
    public const ACTION = 'taxmod_stock_inbound';

    /** The value of `back` with which a receipt booked here returns here. */
    public const BACK = 'inbound';

    private const ALL         = 'all';
    /** D-1002: the lots that wait for their test, after «Eingebucht». */
    private const TEST        = 'test';
    private const OVERDUE     = 'overdue';
    private const FILTER_FORM = 'taxmod-stock-inbound-filter';
    private const BULK_FORM   = 'taxmod-stock-inbound-bulk';

    /** Carrier events shown in a row's details, newest first. */
    private const EVENTS = 5;

    /** Whether the page draws one order's own view: its forms come back there, not to the list. */
    private bool $detail = false;

    /** Built once per request: it indexes every lot and line. */
    private ?\TaxmodStock\Core\Ledger $ledger = null;

    public function __construct(private readonly InboundService $inbound = new InboundService())
    {
    }

    /** @param array<string, int|string> $query */
    public static function url(array $query = []): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE] + $query), admin_url('admin.php'));
    }

    public function render(): string
    {
        $html = '<div class="wrap">' . ListPage::heading(__('Wareneingang', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice();

        try {
            $html .= $this->body();
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . '</div>';
    }

    /** admin-post handler: capability → nonce → validate → sanitize → act → back to the tab with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'taxmod-stock'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        // A row's button names its op; the bulk bar sends its choice as «bulk» (see {@see bulkBar()}).
        $op    = sanitize_key((string) ($_POST['op'] ?? '')) ?: sanitize_key((string) ($_POST['bulk'] ?? ''));
        $ids   = array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? [])))) ?: array_filter([absint($_POST['order'] ?? 0)]);
        $tab   = sanitize_key((string) ($_POST['tab'] ?? ''));
        $today = current_time('Y-m-d');

        try {
            $text = match ($op) {
                'arrived' => $this->applied($this->inbound->markArrived(array_values($ids), $today)),
                'back'    => $this->applied($this->inbound->markNotArrived((int) ($ids[0] ?? 0))),
                'unbook'  => $this->applied($this->inbound->unbook((int) ($ids[0] ?? 0))),
                // D-1060: a purchase marked private already in the order view, and taken back.
                'private'   => $this->applied($this->inbound->stock()->closeWithoutStock((int) ($ids[0] ?? 0), absint($_POST['purchase'] ?? 0), __('privat', 'taxmod-stock'))),
                'unprivate' => $this->applied($this->inbound->stock()->reopenForStock((int) ($ids[0] ?? 0), absint($_POST['purchase'] ?? 0))),
                'tracking' => $this->applied($this->inbound->addTracking((int) ($ids[0] ?? 0), sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? ''))))),
                'untrack' => $this->applied($this->inbound->removeTracking((int) ($ids[0] ?? 0), sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? ''))))),
                'test'    => $this->applied($this->inbound->recordTest(
                    absint($_POST['lot'] ?? 0),
                    TestResult::tryFrom(sanitize_key((string) ($_POST['result'] ?? ''))) ?? throw StockError::nothingToBook(),
                    sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? ''))),
                    $today
                )),
                'refresh' => sprintf(
                    /* translators: %d: number of tracking numbers a carrier answered for */
                    _n('Für %d Sendungsnummer kam eine Antwort.', 'Für %d Sendungsnummern kam eine Antwort.', $n = $this->inbound->refreshTracking(array_values($ids)), 'taxmod-stock'),
                    $n
                ),
                default   => throw StockError::nothingToBook(),
            };
            $this->remember('success', $text);
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
        }

        $tab = in_array($tab, self::tabSlugs(), true) ? $tab : '';
        wp_safe_redirect(self::url(['tab' => $tab, 'order' => empty($_POST['detail']) ? 0 : (int) ($ids[0] ?? 0)]));
        exit;
    }

    private function stay(): string
    {
        return $this->detail ? '<input type="hidden" name="detail" value="1">' : '';
    }

    private function body(): string
    {
        $s       = $this->inbound->stock()->structure();
        $today   = current_time('Y-m-d');
        $orders  = $this->inbound->orders();
        $labels  = $this->inbound->stock()->labels('order');
        $archive = $this->inbound->stock()->inboundArchive($today);
        $stages  = array_map(static fn (InboundOrder $o): InboundStage => $o->stage($s, $archive), $orders);
        $tab     = sanitize_key((string) ($_GET['tab'] ?? InboundStage::Arrived->value));
        $tab     = in_array($tab, self::tabSlugs(), true) ? $tab : InboundStage::Arrived->value;
        $search  = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $filter  = sanitize_key((string) ($_GET['filter'] ?? ''));
        $open    = absint($_GET['order'] ?? 0);
        $counts  = array_count_values(array_map(static fn (InboundStage $st): string => $st->value, $stages));
        $tabs    = [];

        foreach (InboundStage::tabs() as $stage) {
            $tabs[] = [$stage->value, $this->stageName($stage), $counts[$stage->value] ?? 0];
        }

        $testing = $this->inbound->tests()->isConfigured();
        $toTest  = $testing ? $this->inbound->lotsToTest() : [];

        if ($testing) {
            $tabs[] = [self::TEST, __('Zu testen', 'taxmod-stock'), count($toTest)];
        }

        // D-1008: «Archiviert» after the working tabs; «Alle» is everything not archived.
        $archived = $counts[InboundStage::Archived->value] ?? 0;
        $tabs[]   = [InboundStage::Archived->value, $this->stageName(InboundStage::Archived), $archived];
        $tabs[]   = [self::ALL, __('Alle', 'taxmod-stock'), count($orders) - $archived];

        if ($tab === self::TEST) {
            return ListPage::tabs($tabs, $tab, static fn (string $slug): string => self::url(['tab' => $slug])) . $this->testList($toTest);
        }

        // His word 2026-11-05: «man klickt auf das item und bekommt eine teilview des listeneintrags mit den postionen».
        if ($open > 0 && isset($orders[$open])) {
            return ListPage::tabs($tabs, $tab, static fn (string $slug): string => self::url(['tab' => $slug]))
                . $this->detailView($orders[$open], $stages[$open], $labels[$open] ?? '#' . $open, $today, $tab);
        }

        $shown  = [];

        foreach ($orders as $id => $order) {
            $inTab = $tab === self::ALL ? $stages[$id] !== InboundStage::Archived : $stages[$id]->value === $tab;

            if (! $inTab) {
                continue;
            }

            if ($filter === self::OVERDUE && ! $order->overdue($s, $today, $archive)) {
                continue;
            }

            if ($search !== '' && ! $this->matches($search, $labels[$id] ?? '', $this->inbound->about($id), $order->tracking)) {
                continue;
            }

            $shown[$id] = $order;
        }

        // Newest order first; one without a date at the end.
        uasort($shown, static fn (InboundOrder $a, InboundOrder $b): int => strcmp((string) $b->orderedOn, (string) $a->orderedOn) ?: $b->id <=> $a->id);

        $html = ListPage::tabs($tabs, $tab, static fn (string $slug): string => self::url(['tab' => $slug]))
            . ListPage::searchBox(self::FILTER_FORM, $search, __('Bestellung, Händler, Sendungsnummer …', 'taxmod-stock'), ['page' => self::PAGE, 'tab' => $tab], __('Suchen', 'taxmod-stock'))
            . $this->bulkBar($tab, $filter, count($shown));
        $rows = '';

        foreach ($shown as $id => $order) {
            $rows .= $this->row($order, $stages[$id], $labels[$id] ?? '#' . $id, $today, $tab);
        }

        $columns = [[__('Bestellung', 'taxmod-stock'), ''], [__('Bestellt am', 'taxmod-stock'), '8em'], [__('Sendung', 'taxmod-stock'), '22%'],
            [__('Angekommen', 'taxmod-stock'), '8em'], [__('Käufe', 'taxmod-stock'), '10em']];

        if ($tab === self::ALL) {
            $columns[] = [__('Stufe', 'taxmod-stock'), '8em'];
        }

        $rows = $rows !== '' ? $rows : '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) (count($columns) + 2)) . '">' . esc_html__('Nichts gefunden.', 'taxmod-stock') . '</td></tr>';

        return $html . ListPage::table($columns, $rows) . (new StockPage($this->inbound->stock()))->script(true);
    }

    /** @return list<string> */
    private static function tabSlugs(): array
    {
        return [...array_map(static fn (InboundStage $st): string => $st->value, InboundStage::tabs()), self::TEST, InboundStage::Archived->value, self::ALL];
    }

    /**
     * D-1002: one row per lot that waits for its test; its details hold the form for the result.
     *
     * @param list<int> $lots
     */
    private function testList(array $lots): string
    {
        $stock     = $this->inbound->stock();
        $s         = $stock->structure();
        $table     = $stock->table('lot');
        $ledger    = $stock->ledger();
        $things    = $stock->things();
        $orderOf   = $this->inbound->orderOfPurchase();
        $orders    = $stock->labels('order');
        $places    = $stock->places();
        $rows      = '';

        foreach ($lots as $lot) {
            $thing    = $ledger->thingOf($lot);
            $purchase = (int) $table->first($lot, $s->field('lot_purchase'));
            $order    = $orderOf[$purchase] ?? 0;
            $rows    .= ListPage::row(self::BULK_FORM, (string) $lot, [
                '<strong>' . esc_html($thing === null ? '#' . $lot : $things->word($thing)) . '</strong> <span class="description">' . esc_html(sprintf(/* translators: %d: material number */ __('Nr. %d', 'taxmod-stock'), (int) $thing)) . '</span>',
                esc_html($order > 0 ? ($orders[$order] ?? '#' . $order) : '—'),
                esc_html(substr((string) $table->first($lot, $s->field('lot_date')), 0, 10)),
                esc_html($places[(int) $table->first($lot, $s->field('lot_location'))] ?? ''),
                esc_html(rtrim(rtrim(number_format($ledger->restOf($lot), 3, ',', '.'), '0'), ',')),
            ], '', $this->testForm($lot), true);
        }

        $rows = $rows !== '' ? $rows : '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__('Nichts zu testen.', 'taxmod-stock') . '</td></tr>';

        return '<p>' . esc_html__('Bestandsposten aus Warengruppen mit Test (Configuration › Lager), noch ohne Ergebnis. Neuware geht ohne Test durch. Ungetestet wird nicht verkauft.', 'taxmod-stock') . '</p>'
            . ListPage::table([[__('Material', 'taxmod-stock'), ''], [__('Bestellung', 'taxmod-stock'), ''], [__('Eingegangen am', 'taxmod-stock'), '9em'],
                [__('Lagerort', 'taxmod-stock'), '10em'], [__('Menge', 'taxmod-stock'), '6em']], $rows);
    }

    private function testForm(int $lot): string
    {
        $choices = [
            TestResult::Ok->value          => __('OK', 'taxmod-stock'),
            TestResult::Partial->value     => __('Teilweise OK', 'taxmod-stock'),
            TestResult::Maintenance->value => __('Wartung nötig', 'taxmod-stock'),
            TestResult::Defect->value      => __('Defekt', 'taxmod-stock'),
        ];
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="test">'
            . '<input type="hidden" name="lot" value="' . esc_attr((string) $lot) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TEST) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false) . '<p>';

        foreach ($choices as $value => $label) {
            $html .= '<label style="margin-right:1.5em"><input type="radio" name="result" value="' . esc_attr($value) . '" required> ' . esc_html($label) . '</label>';
        }

        return $html . '</p><p><label>' . esc_html__('Begründung (bei «Teilweise OK» und «Wartung nötig» Pflicht), z. B. «Kondensatoren tauschen»', 'taxmod-stock')
            . '<br><textarea name="reason" rows="2" class="large-text"></textarea></label></p>' . get_submit_button(__('Ergebnis speichern', 'taxmod-stock'), 'primary', '', false) . '</form>';
    }

    private function bulkBar(string $tab, string $filter, int $count): string
    {
        $actions = ['arrived' => __('Angekommen', 'taxmod-stock')];

        if ($this->inbound->hasTracking()) {
            $actions['refresh'] = __('Sendungsstatus aktualisieren', 'taxmod-stock');
        }

        $bar = ListPage::bulkBar(self::BULK_FORM, self::ACTION, $actions, sprintf(
            /* translators: %d: number of orders */
            _n('%d Bestellung', '%d Bestellungen', $count, 'taxmod-stock'),
            $count
        ), ListPage::filters(self::FILTER_FORM, [['filter', ['' => __('Alle Bestellungen', 'taxmod-stock'), self::OVERDUE => __('Überfällig', 'taxmod-stock')], $filter, __('Filter', 'taxmod-stock')]]), false);

        // The shared bar posts its choice as «action», which admin-post routes on: the handler's name goes in its place,
        // the choice travels as «bulk».
        return str_replace(
            ['name="action" id="' . self::BULK_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::BULK_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"></form>'],
            $bar
        );
    }

    private function row(InboundOrder $order, InboundStage $stage, string $label, string $today, string $tab): string
    {
        $s      = $this->inbound->stock()->structure();
        $counts = $this->inbound->purchaseCounts($order->id);
        $about  = $this->inbound->about($order->id);
        $late   = $order->overdue($s, $today, $this->inbound->stock()->inboundArchive($today));
        $cells  = [
            '<strong><a href="' . esc_url(self::url(['tab' => $tab, 'order' => $order->id])) . '">' . esc_html($label) . '</a></strong>' . ($late ? ' <span style="color:#b32d2e;font-weight:600">' . esc_html__('überfällig', 'taxmod-stock') . '</span>' : '')
                . ($about === '' ? '' : '<br><span class="description">' . esc_html($about) . '</span>'),
            esc_html(substr((string) $order->orderedOn, 0, 10)),
            $this->trackingCell($order),
            esc_html(substr((string) $order->receivedOn, 0, 10)),
            esc_html((string) $counts['total'])
                . ($counts['undetermined'] > 0 ? '<br><span class="description">' . esc_html(sprintf(/* translators: %d: purchases not yet determined */ __('%d unbestimmt', 'taxmod-stock'), $counts['undetermined'])) . '</span>' : '')
                . ($counts['booked'] > 0 ? '<br><span class="description">' . esc_html(sprintf(/* translators: %d: purchases in stock */ __('%d gebucht', 'taxmod-stock'), $counts['booked'])) . '</span>' : ''),
        ];

        if ($tab === self::ALL) {
            $cells[] = esc_html($this->stageName($stage));
        }

        return ListPage::row(self::BULK_FORM, (string) $order->id, $cells, $this->buttons($order, $stage, $counts['booked'], $tab), '');
    }

    /** One order's own view: its head with the buttons, then its purchases — the receipt form once it has arrived. */
    private function detailView(InboundOrder $order, InboundStage $stage, string $label, string $today, string $tab): string
    {
        $this->detail = true;
        $counts = $this->inbound->purchaseCounts($order->id);
        $about  = $this->inbound->about($order->id);
        $late   = $order->overdue($this->inbound->stock()->structure(), $today, $this->inbound->stock()->inboundArchive($today));
        $fact   = static fn (string $name, string $value): string => $value === '' ? '' : '<tr><th scope="row" style="width:10em">' . esc_html($name) . '</th><td>' . $value . '</td></tr>';

        return '<p><a href="' . esc_url(self::url(['tab' => $tab])) . '">← ' . esc_html(sprintf(/* translators: %s: name of the tab */ __('Zurück zu «%s»', 'taxmod-stock'), $this->tabName($tab))) . '</a></p>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1em;flex-wrap:wrap">'
            . '<div><h2 style="margin:.2em 0">' . esc_html($label) . ($late ? ' <span style="color:#b32d2e;font-size:.8em">' . esc_html__('überfällig', 'taxmod-stock') . '</span>' : '') . '</h2>'
            . ($about === '' ? '' : '<p class="description" style="margin-top:0">' . esc_html($about) . '</p>') . '</div>'
            . '<div style="text-align:right">' . $this->buttons($order, $stage, $counts['booked'], $tab) . '</div></div>'
            . '<table class="widefat" style="max-width:40em;margin-bottom:1em"><tbody>'
            . $fact(__('Stufe', 'taxmod-stock'), esc_html($this->stageName($stage)))
            . $fact(__('Bestellt am', 'taxmod-stock'), esc_html(substr((string) $order->orderedOn, 0, 10)))
            . $fact(__('Sendung', 'taxmod-stock'), $this->trackingCell($order))
            . $fact(__('Angekommen', 'taxmod-stock'), esc_html(substr((string) $order->receivedOn, 0, 10)))
            . $fact(__('Käufe', 'taxmod-stock'), esc_html(sprintf(/* translators: 1: purchases, 2: of them in stock */ __('%1$d, davon %2$d gebucht', 'taxmod-stock'), $counts['total'], $counts['booked'])))
            . '</tbody></table>'
            . $this->details($order, $stage, $tab, true)
            . (new StockPage($this->inbound->stock()))->script(true);
    }

    private function tabName(string $tab): string
    {
        $stage = InboundStage::tryFrom($tab);

        return match (true) {
            $tab === self::ALL  => __('Alle', 'taxmod-stock'),
            $tab === self::TEST => __('Zu testen', 'taxmod-stock'),
            $stage !== null     => $this->stageName($stage),
            default             => __('Wareneingang', 'taxmod-stock'),
        };
    }

    /** Each tracking number with its carrier's last stage, where one answered. */
    private function trackingCell(InboundOrder $order): string
    {
        $out = [];

        foreach ($order->tracking as $number) {
            $answer = $this->inbound->trackingAnswer($number);
            $stage  = is_array($answer) ? (string) ($answer['stage'] ?? '') : '';
            $out[]  = '<code>' . esc_html($number) . '</code>' . ($stage === '' ? '' : ' <span class="description">' . esc_html((string) ($answer['carrier'] ?? '') . ' ' . $this->carrierStageName($stage)) . '</span>');
        }

        return implode('<br>', $out);
    }

    private function buttons(InboundOrder $order, InboundStage $stage, int $booked, string $tab): string
    {
        $html = '';

        if (in_array($stage, [InboundStage::Ordered, InboundStage::Shipped], true) || ($stage === InboundStage::Arrived && ($order->receivedOn ?? '') === '' && $booked === 0)) {
            $html .= $this->button($order->id, 'arrived', __('Angekommen', 'taxmod-stock'), $tab, true);
        }

        if ($stage === InboundStage::Arrived && ($order->receivedOn ?? '') !== '' && $booked === 0) {
            $html .= $this->button($order->id, 'back', __('Zurück', 'taxmod-stock'), $tab, false);
        }

        // D-1009: back from «Eingebucht» takes the goods out of stock again — he asked to be told before it happens.
        if (in_array($stage, [InboundStage::Booked, InboundStage::Archived], true) && $booked > 0) {
            $html .= $this->button($order->id, 'unbook', __('Zurück', 'taxmod-stock'), $tab, false,
                __('Der Wareneingang dieser Bestellung wird wieder ausgebucht: Bestandsposten und Buchung werden gelöscht, die Bestellung steht wieder unter «Angekommen». Fortfahren?', 'taxmod-stock'));
        }

        // His wish 2026-11-01: «sendungsnummer hinzufügen mit in den kopf auf nehmen … als button der dialog öffnet» (D-1063).
        if (! in_array($stage, [InboundStage::Booked, InboundStage::Archived], true)) {
            $html .= $this->trackingButton($order->id, $tab);
        }

        if ($order->tracking !== [] && $this->inbound->hasTracking() && ! in_array($stage, [InboundStage::Booked, InboundStage::Archived], true)) {
            $html .= $this->button($order->id, 'refresh', __('Status ↻', 'taxmod-stock'), $tab, false);
        }

        return $html;
    }

    private function trackingButton(int $order, string $tab): string
    {
        $field = '<label style="display:block">' . esc_html__('Sendungsnummer', 'taxmod-stock') . '<br><input type="text" name="number" style="width:100%"></label>';
        $form  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay()
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);

        if (! method_exists(ListPage::class, 'dialogAction')) {
            return $form . '<input type="hidden" name="op" value="tracking"><input type="text" name="number" placeholder="' . esc_attr__('Sendungsnummer', 'taxmod-stock') . '" style="width:12em"> '
                . get_submit_button(__('Hinzufügen', 'taxmod-stock'), 'secondary', '', false) . '</form> ';
        }

        return $form . ListPage::dialogAction('taxmod-stock-track-' . $order, __('Sendungsnummer', 'taxmod-stock'), __('Sendungsnummer hinzufügen', 'taxmod-stock'), $field, 'op', 'tracking', __('Hinzufügen', 'taxmod-stock')) . '</form> ';
    }

    private function button(int $order, string $op, string $label, string $tab, bool $primary, string $confirm = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"'
            . ($confirm === '' ? '' : ListPage::confirm($confirm)) . '>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay()
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form> ';
    }

    /** «Angekommen»: the Lager page's booking form, loaded once it shows; else the purchases, the carrier's events and a tracking field. */
    /** His question of 2026-10-28 on a booked order: «wo befinden die?» — each lot's material, linked to its stock, and its place. */
    private function whereItWent(int $purchase): string
    {
        $stock  = $this->inbound->stock();
        $s      = $stock->structure();
        $lots   = $stock->table('lot');
        $ledger = $this->ledger ??= $stock->ledger();
        $things = $stock->things();
        $places = $stock->places();
        $out    = [];

        foreach ($this->inbound->lotsOf($purchase) as $lot) {
            $thing = $ledger->thingOf($lot);
            $place = $places[(int) $lots->first($lot, $s->field('lot_location'))] ?? '';
            $out[] = ($thing === null ? esc_html('#' . $lot) : '<a href="' . esc_url(StockPage::thingUrl($thing, 'bestand')) . '">' . esc_html($things->word($thing)) . '</a>')
                . ($place === '' ? '' : ' · ' . esc_html($place));
        }

        return $out === [] ? '' : ' → ' . implode(', ', $out);
    }

    private function details(InboundOrder $order, InboundStage $stage, string $tab, bool $open): string
    {
        if ($stage === InboundStage::Arrived) {
            return '<div class="taxmod-stock-positions" data-order="' . esc_attr((string) $order->id) . '" data-back="' . esc_attr(self::BACK) . '"></div>';
        }

        $labels    = $this->inbound->stock()->labels('purchase');
        $s         = $this->inbound->stock()->structure();
        $purchases = $this->inbound->stock()->table('order')->all($order->id, $s->field('order_lines'));
        // His wish 2026-11-01: «wissen wir doch schon anzahl und preis und sowas können wir das nicht schöner anzeigen».
        $html      = '<h4 style="margin-bottom:.3em">' . esc_html__('Käufe', 'taxmod-stock') . '</h4>'
            . '<table class="widefat striped" style="margin-bottom:.8em"><thead><tr><th>' . esc_html__('Kauf', 'taxmod-stock') . '</th>'
            . '<th style="width:5em;text-align:right">' . esc_html__('Menge', 'taxmod-stock') . '</th>'
            . '<th style="width:8em;text-align:right">' . esc_html__('Preis je Stück', 'taxmod-stock') . '</th>'
            . '<th style="width:8em;text-align:right">' . esc_html__('Gesamt', 'taxmod-stock') . '</th>'
            . '<th style="width:16em;text-align:right"></th></tr></thead><tbody>';
        $table     = $this->inbound->stock()->table('purchase');
        $parts     = [];

        foreach ($purchases as $purchase) {
            foreach (['purchase_unit_price', 'purchase_total_price'] as $key) {
                if ($s->hasField($key)) {
                    $parts[] = (int) $table->first((int) $purchase, $s->field($key));
                }
            }
        }

        // Only this order's amounts: the whole amounts node is every price of the installation (Stefan 2026-11-02: «Versendet» slow).
        $money     = $this->inbound->stock()->recordsOf('money', array_values(array_filter($parts)));
        $amountOf  = static function (?string $part) use ($money, $s): ?float {
            $amount = $part === null || $part === '' ? null : $money->first((int) $part, $s->field('money_amount'));

            return $amount === null || $amount === '' ? null : (float) $amount;
        };
        $shown     = static fn (?float $amount): string => $amount === null ? '—' : number_format_i18n($amount, 2);

        $stock   = $this->inbound->stock();
        $closing = $stock->canCloseWithoutStock();
        $booked  = $stock->receivedPurchases();

        foreach ($purchases as $purchase) {
            $purchase = (int) $purchase;
            $reason   = $closing ? $stock->notStockedReason($purchase) : '';
            // D-1060: private purchases are marked here, before anything arrives; a marked one can always be taken back.
            $button   = match (true) {
                ! $closing || isset($booked[$purchase]) => '',
                $reason !== ''                          => ' <em>(' . esc_html($reason) . ')</em> ' . $this->purchaseButton($order->id, $purchase, 'unprivate', __('Zurücknehmen', 'taxmod-stock'), $tab),
                default                                 => ' ' . $this->purchaseButton($order->id, $purchase, 'private', __('Privat', 'taxmod-stock'), $tab),
            };
            $count = (float) ($table->first($purchase, $s->field('purchase_quantity')) ?? 0);
            $each  = $amountOf($table->first($purchase, $s->field('purchase_unit_price')));
            $total = $s->hasField('purchase_total_price') ? $amountOf($table->first($purchase, $s->field('purchase_total_price'))) : null;
            $total ??= $each === null || $count <= 0.0 ? null : $each * $count;
            $html .= '<tr><td style="white-space:normal">' . esc_html($labels[$purchase] ?? '#' . $purchase) . $this->whereItWent($purchase) . '</td>'
                . '<td style="text-align:right">' . esc_html($count > 0.0 ? (string) (0 + $count) : '—') . '</td>'
                . '<td style="text-align:right">' . esc_html($shown($each)) . '</td>'
                . '<td style="text-align:right">' . esc_html($shown($total)) . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $button . '</td></tr>';
        }

        $html .= '</tbody></table>' . $this->events($order);

        if (! in_array($stage, [InboundStage::Booked, InboundStage::Archived], true)) {
            // D-1009: every step can be taken back — a number added by mistake comes off here.
            foreach ($order->tracking as $number) {
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 .3em">'
                    . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="untrack">'
                    . '<input type="hidden" name="order" value="' . esc_attr((string) $order->id) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay()
                    . '<input type="hidden" name="number" value="' . esc_attr($number) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
                    . '<code>' . esc_html($number) . '</code> ' . get_submit_button(__('Entfernen', 'taxmod-stock'), 'small', '', false) . '</form>';
            }

        }

        return $html;
    }

    private function purchaseButton(int $order, int $purchase, string $op, string $label, string $tab): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay() . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<button type="submit" class="button button-small">' . esc_html($label) . '</button></form>';
    }

    /** The carrier's last events per tracking number, newest first. */
    private function events(InboundOrder $order): string
    {
        $html = '';

        foreach ($order->tracking as $number) {
            $answer = $this->inbound->trackingAnswer($number);
            $events = is_array($answer) && is_array($answer['events'] ?? null) ? array_slice($answer['events'], 0, self::EVENTS) : [];

            if ($events === []) {
                continue;
            }

            $html .= '<h4 style="margin-bottom:.3em">' . esc_html((string) ($answer['carrier'] ?? '')) . ' <code>' . esc_html($number) . '</code></h4><ul style="margin-top:0">';

            foreach ($events as $event) {
                $html .= '<li>' . esc_html(trim(substr((string) ($event['at'] ?? ''), 0, 16) . ' · ' . (string) ($event['description'] ?? $event['status'] ?? '')
                    . (($event['place'] ?? '') === '' ? '' : ' · ' . (string) $event['place']), ' ·')) . '</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /** @param list<string> $tracking */
    private function matches(string $search, string $label, string $about, array $tracking): bool
    {
        $haystack = mb_strtolower($label . ' ' . $about . ' ' . implode(' ', $tracking));

        foreach (preg_split('/\s+/', mb_strtolower(trim($search))) ?: [] as $word) {
            if ($word !== '' && ! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $result the modeller's answer; `mixed`: it nests */
    private function applied(array $result): string
    {
        $applied = count((array) ($result['applied'] ?? []));

        return $applied === 0
            ? __('Nichts zu ändern.', 'taxmod-stock')
            /* translators: %d: number of changes the modeller applied */
            : sprintf(__('Gespeichert: %d Änderungen in einem Schritt.', 'taxmod-stock'), $applied);
    }

    private function stageName(InboundStage $stage): string
    {
        return match ($stage) {
            InboundStage::Ordered => __('Bestellt', 'taxmod-stock'),
            InboundStage::Shipped => __('Versendet', 'taxmod-stock'),
            InboundStage::Arrived => __('Angekommen', 'taxmod-stock'),
            InboundStage::Booked  => __('Eingebucht', 'taxmod-stock'),
            InboundStage::Archived => __('Archiviert', 'taxmod-stock'),
            InboundStage::None    => __('Entwurf/storniert', 'taxmod-stock'),
        };
    }

    /** The carrier modules' stages (announced, shipped, delivered, problem), alike for DHL and GLS. */
    private function carrierStageName(string $stage): string
    {
        return match ($stage) {
            'announced' => __('angekündigt', 'taxmod-stock'),
            'shipped'   => __('unterwegs', 'taxmod-stock'),
            'delivered' => __('zugestellt', 'taxmod-stock'),
            'problem'   => __('Problem', 'taxmod-stock'),
            default     => $stage,
        };
    }

    private function remember(string $type, string $text): void
    {
        set_transient(StockPage::NOTICE . get_current_user_id(), ['type' => $type, 'text' => $text], 60);
    }

    private function notice(): string
    {
        $notice = get_transient(StockPage::NOTICE . get_current_user_id());

        if (! is_array($notice)) {
            return '';
        }

        delete_transient(StockPage::NOTICE . get_current_user_id());

        return '<div class="notice notice-' . esc_attr($notice['type'] === 'success' ? 'success' : 'error') . '"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }
}
