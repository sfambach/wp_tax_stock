<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestResult;
use TaxmodStock\WordPress\InboundService;

/**
 * The page «Wareneingang», first under the menu «Logistik» (D-1004): every incoming order in one stage, a tab per stage (D-1001) —
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
    /** The cockpit is the first page of the menu «Logistik» and shares its slug (D-1004). */
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

    /** DHL events shown in a row's details, newest first. */
    private const EVENTS = 5;

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
        if (! class_exists(ListPage::class) || ! method_exists(ListPage::class, 'tabs')) {
            return '<div class="wrap"><h1>' . esc_html__('Wareneingang', 'taxmod-stock') . '</h1><div class="notice notice-error"><p>'
                . esc_html__('Die Seite braucht den Taxonomy Modeller ab Version 0.1.65 (gemeinsame Listenseite).', 'taxmod-stock') . '</p></div></div>';
        }

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
                'tracking' => $this->applied($this->inbound->addTracking((int) ($ids[0] ?? 0), sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? ''))))),
                'test'    => $this->applied($this->inbound->recordTest(
                    absint($_POST['lot'] ?? 0),
                    TestResult::tryFrom(sanitize_key((string) ($_POST['result'] ?? ''))) ?? throw StockError::nothingToBook(),
                    sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? ''))),
                    $today
                )),
                'dhl'     => sprintf(
                    /* translators: %d: number of tracking numbers DHL answered for */
                    _n('DHL hat für %d Sendungsnummer geantwortet.', 'DHL hat für %d Sendungsnummern geantwortet.', $n = $this->inbound->refreshDhl(array_values($ids)), 'taxmod-stock'),
                    $n
                ),
                default   => throw StockError::nothingToBook(),
            };
            $this->remember('success', $text);
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
        }

        wp_safe_redirect(self::url(['tab' => in_array($tab, self::tabSlugs(), true) ? $tab : '']));
        exit;
    }

    private function body(): string
    {
        $s       = $this->inbound->stock()->structure();
        $today   = current_time('Y-m-d');
        $orders  = $this->inbound->orders();
        $labels  = $this->inbound->stock()->labels('order');
        $since   = $this->inbound->stock()->inboundSince();
        $stages  = array_map(static fn (InboundOrder $o): InboundStage => $o->stage($s, $since), $orders);
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

        $tabs[] = [self::ALL, __('Alle', 'taxmod-stock'), count($orders)];

        if ($tab === self::TEST) {
            return ListPage::tabs($tabs, $tab, static fn (string $slug): string => self::url(['tab' => $slug])) . $this->testList($toTest);
        }

        $shown  = [];

        foreach ($orders as $id => $order) {
            if ($tab !== self::ALL && $stages[$id]->value !== $tab && $id !== $open) {
                continue;
            }

            if ($filter === self::OVERDUE && ! $order->overdue($s, $today, $since)) {
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
            $rows .= $this->row($order, $stages[$id], $labels[$id] ?? '#' . $id, $today, $tab, $id === $open);
        }

        $columns = [[__('Bestellung', 'taxmod-stock'), ''], [__('Bestellt am', 'taxmod-stock'), '8em'], [__('Sendung', 'taxmod-stock'), '22%'],
            [__('Angekommen', 'taxmod-stock'), '8em'], [__('Käufe', 'taxmod-stock'), '10em']];

        if ($tab === self::ALL) {
            $columns[] = [__('Stufe', 'taxmod-stock'), '8em'];
        }

        $rows = $rows !== '' ? $rows : '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) (count($columns) + 2)) . '">' . esc_html__('Nichts gefunden.', 'taxmod-stock') . '</td></tr>';

        return $html . ListPage::table($columns, $rows) . (new StockPage($this->inbound->stock()))->script();
    }

    /** @return list<string> */
    private static function tabSlugs(): array
    {
        return [...array_map(static fn (InboundStage $st): string => $st->value, InboundStage::tabs()), self::TEST, self::ALL];
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
        $places    = $stock->labels('location');
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

        if ($this->inbound->hasDhl()) {
            $actions['dhl'] = __('DHL aktualisieren', 'taxmod-stock');
        }

        $bar = ListPage::bulkBar(self::BULK_FORM, self::ACTION, $actions, sprintf(
            /* translators: %d: number of orders */
            _n('%d Bestellung', '%d Bestellungen', $count, 'taxmod-stock'),
            $count
        ), ListPage::filters(self::FILTER_FORM, [['filter', ['' => __('Alle Bestellungen', 'taxmod-stock'), self::OVERDUE => __('Überfällig', 'taxmod-stock')], $filter, __('Filter', 'taxmod-stock')]]));

        // The shared bar posts its choice as «action», which admin-post routes on: the handler's name goes in its place,
        // the choice travels as «bulk».
        return str_replace(
            ['name="action" id="' . self::BULK_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::BULK_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"></form>'],
            $bar
        );
    }

    private function row(InboundOrder $order, InboundStage $stage, string $label, string $today, string $tab, bool $open): string
    {
        $s      = $this->inbound->stock()->structure();
        $counts = $this->inbound->purchaseCounts($order->id);
        $about  = $this->inbound->about($order->id);
        $late   = $order->overdue($s, $today, $this->inbound->stock()->inboundSince());
        $cells  = [
            '<strong>' . esc_html($label) . '</strong>' . ($late ? ' <span style="color:#b32d2e;font-weight:600">' . esc_html__('überfällig', 'taxmod-stock') . '</span>' : '')
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

        return ListPage::row(self::BULK_FORM, (string) $order->id, $cells, $this->buttons($order, $stage, $counts['booked'], $tab), $this->details($order, $stage, $tab, $open), $open || $stage === InboundStage::Arrived);
    }

    /** Each tracking number with the DHL module's last stage, where it has one. */
    private function trackingCell(InboundOrder $order): string
    {
        $out = [];

        foreach ($order->tracking as $number) {
            $answer = $this->inbound->dhlAnswer($number);
            $stage  = is_array($answer) ? (string) ($answer['stage'] ?? '') : '';
            $out[]  = '<code>' . esc_html($number) . '</code>' . ($stage === '' ? '' : ' <span class="description">' . esc_html($this->dhlStageName($stage)) . '</span>');
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

        if ($order->tracking !== [] && $this->inbound->hasDhl() && $stage !== InboundStage::Booked) {
            $html .= $this->button($order->id, 'dhl', __('DHL ↻', 'taxmod-stock'), $tab, false);
        }

        return $html;
    }

    private function button(int $order, string $op, string $label, string $tab, bool $primary): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form> ';
    }

    /** «Angekommen»: the Lager page's booking form, loaded once it shows; else the purchases, DHL's events and a tracking field. */
    private function details(InboundOrder $order, InboundStage $stage, string $tab, bool $open): string
    {
        if ($stage === InboundStage::Arrived) {
            return '<div class="taxmod-stock-positions" data-order="' . esc_attr((string) $order->id) . '" data-back="' . esc_attr(self::BACK) . '"></div>';
        }

        $labels    = $this->inbound->stock()->labels('purchase');
        $s         = $this->inbound->stock()->structure();
        $purchases = $this->inbound->stock()->table('order')->all($order->id, $s->field('order_lines'));
        $html      = '<h4 style="margin-bottom:.3em">' . esc_html__('Käufe', 'taxmod-stock') . '</h4><ul style="margin-top:0">';

        foreach ($purchases as $purchase) {
            $html .= '<li>' . esc_html($labels[(int) $purchase] ?? '#' . $purchase) . '</li>';
        }

        $html .= '</ul>' . $this->events($order);

        if ($stage !== InboundStage::Booked) {
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="tracking">'
                . '<input type="hidden" name="order" value="' . esc_attr((string) $order->id) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
                . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
                . '<label>' . esc_html__('Sendungsnummer', 'taxmod-stock') . ' <input type="text" name="number" style="width:16em"></label> '
                . get_submit_button(__('Hinzufügen', 'taxmod-stock'), 'secondary', '', false) . '</form>';
        }

        return $html;
    }

    /** The DHL module's last events per tracking number, newest first. */
    private function events(InboundOrder $order): string
    {
        $html = '';

        foreach ($order->tracking as $number) {
            $answer = $this->inbound->dhlAnswer($number);
            $events = is_array($answer) && is_array($answer['events'] ?? null) ? array_slice($answer['events'], 0, self::EVENTS) : [];

            if ($events === []) {
                continue;
            }

            $html .= '<h4 style="margin-bottom:.3em">DHL <code>' . esc_html($number) . '</code></h4><ul style="margin-top:0">';

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
            InboundStage::None    => __('Entwurf/storniert', 'taxmod-stock'),
        };
    }

    /** The DHL module's stages (announced, shipped, delivered, problem). */
    private function dhlStageName(string $stage): string
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
