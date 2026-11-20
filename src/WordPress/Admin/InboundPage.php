<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Listing\Wildcard;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\PurchasePictures;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;
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
    public const PAGE   = Pages::INBOUND;
    public const ACTION = 'taxmod_stock_inbound';

    /** The value of `back` with which a receipt booked here returns here. */
    public const BACK = 'inbound';

    private const ALL         = 'all';

    /** D-1448, D-1452: the stages shown as one list of purchases, each naming its order — no order view of their own. */
    private const POSITION_STAGES = [InboundStage::Arrived, InboundStage::Booked];
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

    /**
     * His word 2026-11-09 «sollte dann auf eingebucht oben springen wenn einbuchen button gedrück wurde … sollte generell so sein»:
     * after an action the order's own view opens at the stage it stands in now — arrow, way back and menu alike; read afresh.
     */
    public static function orderUrl(int $order, string $tab): string
    {
        $inbound = new InboundService();
        $stock   = $inbound->stock();

        try {
            $one = $inbound->orders()[$order] ?? null;
            $now = $one?->stage($stock->structure(), $stock->inboundArchive(current_time('Y-m-d')));
            $all = $one === null ? [] : array_map(static fn (InboundStage $st): string => $st->value, $one->stages($stock->structure(), $stock->inboundArchive(current_time('Y-m-d'))));
        } catch (StockError) {
            $now = null;
            $all = [];
        }

        // D-1411: an order partly here stands in several tabs; the one asked for stays while the order is still in it.
        $tab = in_array($tab, $all, true) ? $tab : ($now !== null && in_array($now->value, self::tabSlugs(), true) ? $now->value : $tab);

        return self::url(['tab' => $tab, 'order' => $order]);
    }

    public function render(): string
    {
        $html = Notice::take();

        try {
            $html .= $this->body();
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        // D-1373: the page in the modeller's template.
        return ListPage::page(__('Wareneingang', 'taxmod-stock'), [], [['html' => $html]], (string) TAXMOD_STOCK_VERSION);
    }

    /** admin-post handler: capability → nonce → validate → sanitize → act → back to the tab with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

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
                // D-1264: the purchase's picture behind its material's pictures.
                'take_picture' => $this->applied($this->inbound->stock()->takePurchasePicture(absint($_POST['purchase'] ?? 0))),
                'tracking' => $this->applied($this->inbound->addTracking((int) ($ids[0] ?? 0), sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? ''))))),
                'untrack' => $this->applied($this->inbound->removeTracking((int) ($ids[0] ?? 0), sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? ''))))),
                'test'    => $this->applied($this->inbound->recordTest(
                    absint($_POST['lot'] ?? 0),
                    TestResult::tryFrom(sanitize_key((string) ($_POST['result'] ?? ''))) ?? throw StockError::nothingToBook(),
                    sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? ''))),
                    $today
                )),
                TestResult::Ok->value     => $this->applied($this->inbound->recordTests(array_values($ids), TestResult::Ok, $today)),
                TestResult::Defect->value => $this->applied($this->inbound->recordTests(array_values($ids), TestResult::Defect, $today)),
                'refresh' => sprintf(
                    /* translators: %d: number of tracking numbers a carrier answered for */
                    _n('Für %d Sendungsnummer kam eine Antwort.', 'Für %d Sendungsnummern kam eine Antwort.', $n = $this->inbound->refreshTracking(array_values($ids)), 'taxmod-stock'),
                    $n
                ),
                default   => throw StockError::nothingToBook(),
            };
            Notice::remember('success', $text);
        } catch (StockError $e) {
            Notice::remember('error', Messages::of($e));
        }

        $tab = in_array($tab, self::tabSlugs(), true) ? $tab : '';
        wp_safe_redirect(empty($_POST['detail']) ? self::url(['tab' => $tab]) : self::orderUrl((int) ($ids[0] ?? 0), $tab));
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
        // D-1411: every tab an order stands in through one of its purchases.
        $tabsOf  = array_map(static fn (InboundOrder $o): array => $o->stages($s, $archive), $orders);
        $tab     = sanitize_key((string) ($_GET['tab'] ?? InboundStage::Arrived->value));
        $tab     = in_array($tab, self::tabSlugs(), true) ? $tab : InboundStage::Arrived->value;
        $search  = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $filter  = sanitize_key((string) ($_GET['filter'] ?? ''));
        $open    = absint($_GET['order'] ?? 0);
        $counts  = array_count_values(array_merge([], ...array_values(array_map(static fn (array $sts): array => array_map(static fn (InboundStage $st): string => $st->value, $sts), $tabsOf))));
        $testing = $this->inbound->tests()->isConfigured();
        $toTest  = $testing ? $this->inbound->lotsToTest() : [];

        // His word 2026-11-09: «oben ist der blaue Pfeil ist immer noch bei bestellt» — an opened order stands at its own stage.
        if ($open > 0 && isset($stages[$open]) && in_array($stages[$open]->value, self::tabSlugs(), true) && ! in_array(InboundStage::tryFrom($tab), $tabsOf[$open], true)) {
            $tab = $stages[$open]->value;
        }

        $bar = self::processBar($counts, $testing ? count($toTest) : null, $tab);

        if ($tab === self::TEST) {
            return $bar . $this->testList($toTest);
        }

        // His word 2026-11-05: «man klickt auf das item und bekommt eine teilview des listeneintrags mit den postionen».
        if ($open > 0 && isset($orders[$open])) {
            $here  = InboundStage::tryFrom($tab);
            $stage = $here !== null && in_array($here, $tabsOf[$open], true) ? $here : $stages[$open];

            // D-1448, his word 2026-11-18 17:29: «Angekommen» has no order view; an old link to one lands on the list, at its rows.
            // D-1452: nor has «Eingebucht».
            if (! in_array($stage, self::POSITION_STAGES, true)) {
                return $bar . $this->detailView($orders[$open], $stage, $labels[$open] ?? '#' . $open, $today, $tab);
            }

            $tab = $stage->value;
        }

        $shown  = [];

        foreach ($orders as $id => $order) {
            $inTab = $tab === self::ALL ? $stages[$id] !== InboundStage::Archived : in_array(InboundStage::tryFrom($tab), $tabsOf[$id], true);

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

        // D-1500 (D-1462): the search stands in the list's one bar, on the right — not in a row of its own above it.
        $searchBox = ListPage::searchBox(self::FILTER_FORM, $search, __('Bestellung, Händler, Sendungsnummer …', 'taxmod-stock'), ['page' => self::PAGE, 'tab' => $tab], __('Suchen', 'taxmod-stock'));
        $html      = $bar;

        // D-1440, his words 2026-11-18 11:33/11:36: «oder ich zeige gleich die Position», «einbuchen … sollte das Teil des
        // angekommenen Bildschirms sein» — every purchase that arrived as a row under its order's line, one bar, one form.
        // D-1452, his word 2026-11-18 17:57: «ab angekommen würde ich die Positionen anzeigen … auch bei eingebucht, zu testen, und bei
        // archiviert würde ich dann wieder nur die Aufträge anzeigen» — «Eingebucht» is the same list of purchases.
        if (in_array(InboundStage::tryFrom($tab), self::POSITION_STAGES, true)) {
            $heads = [];

            foreach ($shown as $id => $order) {
                $heads[$id] = $this->orderCell($order, $labels[$id] ?? '#' . $id);
            }

            $went = $tab === InboundStage::Booked->value ? $this->wentWithPictures(array_keys($heads)) : null;

            return $html . (new StockPage($this->inbound->stock()))->receiptTable($heads, self::BACK, $tab, $went, $searchBox) . (new StockPage($this->inbound->stock()))->script(true);
        }

        $html .= $this->bulkBar($tab, $filter, count($shown), $searchBox);
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
        $inbound   = $this->inbound->orders();
        $places    = $stock->places();
        $steps     = $stock->testStepsOf($lots);
        // D-1461, his wish 2026-11-18 18:41: the lot's label from «Test» too, read once for all lots.
        $labels    = (new StockPage($stock))->lotLabelButtons(array_map('intval', $lots));
        $rows      = '';

        foreach ($lots as $lot) {
            $thing    = $ledger->thingOf($lot);
            $purchase = (int) $table->first($lot, $s->field('lot_purchase'));
            $order    = $orderOf[$purchase] ?? 0;
            $rows    .= ListPage::row(self::BULK_FORM, (string) $lot, [
                // His words 2026-11-20 «link zum bestand fehlt bei test auch», «link zum material um umstellen das test nicht nötig ist»:
                // the material opens its page, «Bestand» its lot, the group the setting «Nach Wareneingang testen».
                '<strong>' . ($thing === null ? esc_html('#' . $lot) : '<a href="' . esc_url(StockPage::thingUrl($thing, '')) . '">' . esc_html($things->word($thing)) . '</a>') . '</strong> <span class="description">' . esc_html(sprintf(/* translators: %d: material number */ __('Nr. %d', 'taxmod-stock'), (int) $thing)) . '</span>'
                    . ($thing === null ? '' : '<br><span class="description"><a href="' . esc_url(StockPage::lotUrl($thing, $lot)) . '">' . esc_html__('Bestand', 'taxmod-stock') . '</a>'
                    . (($group = $things->nodeOf($thing)) === null ? '' : ' · <a href="' . esc_url(add_query_arg(['page' => StockPage::PAGE, 'tab' => 'material', 'group' => $group], admin_url('admin.php'))) . '" title="' . esc_attr__('Dort steht «Nach Wareneingang testen».', 'taxmod-stock') . '">' . esc_html($stock->path($group)) . '</a>')
                    . '</span>'),
                // D-1452: the order named as on «Angekommen» — platform, number with its link, tracking.
                $order > 0 && isset($inbound[$order]) ? $this->orderCell($inbound[$order], $orders[$order] ?? '#' . $order) : esc_html($order > 0 ? ($orders[$order] ?? '#' . $order) : '—'),
                ListPage::dateCell(substr((string) $table->first($lot, $s->field('lot_date')), 0, 10)),
                esc_html(($at = (int) $table->first($lot, $s->field('lot_location'))) > 0 ? ($places[$at] ?? $this->inbound->stock()->locationTree()->pathOf($at)) : ''),
                esc_html(ListPage::number($ledger->restOf($lot))),
            ], '', $this->testForm($lot, $steps[$lot] ?? []) . (($labels[$lot] ?? '') === '' ? '' : ' <span class="taxmod-row-actions">' . $labels[$lot] . '</span>'), true);
        }

        $rows = $rows !== '' ? $rows : '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__('Nichts zu testen.', 'taxmod-stock') . '</td></tr>';

        return '<p>' . esc_html__('Bestandsposten aus Warengruppen mit Test (Configuration › Lager), noch ohne Ergebnis. Neuware geht ohne Test durch. Ungetestet wird nicht verkauft.', 'taxmod-stock') . '</p>'
            // His word 2026-11-20 «massen aktionen fehlen»: the ticked lots get one result together (D-1403: in the bulk choice).
            . $this->testBulkBar(count($lots))
            . ListPage::table([[__('Material', 'taxmod-stock'), ''], [__('Bestellung', 'taxmod-stock'), ''], [__('Eingegangen am', 'taxmod-stock'), '9em'],
                [__('Lagerort', 'taxmod-stock'), '10em'], [__('Menge', 'taxmod-stock'), '6em']], $rows);
    }

    /** The bulk bar of «Test»: the results that need no reason, for every ticked lot (Stefan 2026-11-20). */
    private function testBulkBar(int $count): string
    {
        $bar = ListPage::bulkBar(self::BULK_FORM, self::ACTION, [
            TestResult::Ok->value     => __('Ergebnis: OK', 'taxmod-stock'),
            TestResult::Defect->value => __('Ergebnis: Defekt', 'taxmod-stock'),
        ], sprintf(/* translators: %d: number of lots */ _n('%d Posten', '%d Posten', $count, 'taxmod-stock'), $count));

        // As {@see bulkBar()}: admin-post routes on «action», the choice travels as «bulk».
        return str_replace(
            ['name="action" id="' . self::BULK_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::BULK_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="tab" value="' . esc_attr(self::TEST) . '"></form>'],
            $bar
        );
    }

    /** @param list<array{instruction: string, firmware: string}> $steps D-1396: the revision's test steps, in order */
    private function testForm(int $lot, array $steps = []): string
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
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
        $radios = '';

        foreach ($choices as $value => $label) {
            $radios .= '<label style="margin-right:1.5em"><input type="radio" name="result" value="' . esc_attr($value) . '" required> ' . esc_html($label) . '</label>';
        }

        // D-1396: the revision's test steps to tick off while testing; the ticks guide, the result is what is stored.
        $list = '';

        foreach ($steps as $n => $step) {
            $list .= '<li><label><input type="checkbox" name="steps[]" value="' . esc_attr((string) ($n + 1)) . '"> ' . esc_html($step['instruction']) . '</label>'
                . ($step['firmware'] === '' ? '' : ' <span class="description">' . esc_html(sprintf(/* translators: %s: firmware name and version */ __('Firmware: %s', 'taxmod-stock'), $step['firmware'])) . '</span>') . '</li>';
        }

        return $html . StockPage::actForm([
            ...($list === '' ? [] : [['label' => __('Prüfschritte', 'taxmod-stock'), 'control' => '<ol style="margin:0 0 0 1.5em">' . $list . '</ol>']]),
            ['label' => __('Ergebnis', 'taxmod-stock'), 'control' => $radios],
            ['label' => __('Begründung', 'taxmod-stock'), 'for' => 'taxmod-stock-test-reason-' . $lot, 'control' => ListPage::field(InputKind::LongText, 'reason', '', ['id' => 'taxmod-stock-test-reason-' . $lot, 'rows' => 2, 'columns' => 60]),
                'hint' => __('Bei «Teilweise OK» und «Wartung nötig» Pflicht, z. B. «Kondensatoren tauschen».', 'taxmod-stock')],
        ], StockPage::saveButton(__('Ergebnis speichern', 'taxmod-stock'))) . '</form>';
    }

    private function bulkBar(string $tab, string $filter, int $count, string $search = ''): string
    {
        $actions = ['arrived' => __('Angekommen', 'taxmod-stock')];

        if ($this->inbound->hasTracking()) {
            $actions['refresh'] = __('Sendungsstatus aktualisieren', 'taxmod-stock');
        }

        $bar = ListPage::bulkBar(self::BULK_FORM, self::ACTION, $actions, sprintf(
            /* translators: %d: number of orders */
            _n('%d Bestellung', '%d Bestellungen', $count, 'taxmod-stock'),
            $count
        ), ListPage::filters(self::FILTER_FORM, [['filter', ['' => __('Alle Bestellungen', 'taxmod-stock'), self::OVERDUE => __('Überfällig', 'taxmod-stock')], $filter, __('Filter', 'taxmod-stock')]]), false, [], $search);

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
            ListPage::dateCell(substr((string) $order->orderedOn, 0, 10)),
            $this->trackingCell($order),
            ListPage::dateCell(substr((string) $order->receivedOn, 0, 10)),
            esc_html((string) $counts['total'])
                . ($counts['undetermined'] > 0 ? '<br><span class="description">' . esc_html(sprintf(/* translators: %d: purchases not yet determined */ __('%d unbestimmt', 'taxmod-stock'), $counts['undetermined'])) . '</span>' : '')
                . ($counts['booked'] > 0 ? '<br><span class="description">' . esc_html(sprintf(/* translators: %d: purchases in stock */ __('%d gebucht', 'taxmod-stock'), $counts['booked'])) . '</span>' : ''),
        ];

        if ($tab === self::ALL) {
            $cells[] = esc_html(self::stageName($stage));
        }

        return ListPage::row(self::BULK_FORM, (string) $order->id, $cells, $this->buttons($order, $stage, $counts['booked'], $tab), '');
    }

    /** D-1440: an order's line above its purchases on «Angekommen» — its name opens its own view with its acts. */
    /**
     * D-1448, his word 2026-11-18 17:57 «bei Bestellungen würde reichen, wo bestellt und die Sendungsnummer … Absprungpunkt
     * zur Amazon … maximal 2 Zeilen»: platform and number, the number linking to the order at the platform, then the tracking
     * numbers. Without platform and number the order's label stands (escaped).
     */
    private function orderCell(InboundOrder $order, string $label): string
    {
        $facts  = $this->inbound->orderFacts($order->id);
        $number = $facts['number'] === '' ? '' : ($facts['link'] === null ? esc_html($facts['number'])
            : '<a href="' . esc_url($facts['link']) . '" target="_blank" rel="noopener">' . esc_html($facts['number']) . '</a>');
        $first  = $facts['platform'] === '' && $number === '' ? esc_html($label) : implode(' · ', array_filter([esc_html($facts['platform']), $number]));

        return $first . ($order->tracking === [] ? '' : '<br><span class="description">' . esc_html(implode(', ', $order->tracking)) . '</span>');
    }

    private function orderLine(InboundOrder $order, string $label, string $today, string $tab): string
    {
        $about = $this->inbound->about($order->id);
        $late  = $order->overdue($this->inbound->stock()->structure(), $today, $this->inbound->stock()->inboundArchive($today));
        $facts = array_filter([
            ($order->orderedOn ?? '') === '' ? '' : esc_html(sprintf(/* translators: %s: date */ __('bestellt %s', 'taxmod-stock'), ListPage::date(substr((string) $order->orderedOn, 0, 10)))),
            ($order->receivedOn ?? '') === '' ? '' : esc_html(sprintf(/* translators: %s: date */ __('angekommen %s', 'taxmod-stock'), ListPage::date(substr((string) $order->receivedOn, 0, 10)))),
            $this->trackingCell($order),
            // R1: what is still to be told apart, and what is already in stock.
            ($counts = $this->inbound->purchaseCounts($order->id))['undetermined'] > 0 ? esc_html(sprintf(/* translators: %d: purchases not yet determined */ __('%d unbestimmt', 'taxmod-stock'), $counts['undetermined'])) : '',
            $counts['booked'] > 0 ? esc_html(sprintf(/* translators: %d: purchases in stock */ __('%d gebucht', 'taxmod-stock'), $counts['booked'])) : '',
        ]);

        return '<strong><a href="' . esc_url(self::url(['tab' => $tab, 'order' => $order->id])) . '">' . esc_html($label) . '</a></strong>'
            . ($late ? ' <span style="color:#b32d2e;font-weight:600">' . esc_html__('überfällig', 'taxmod-stock') . '</span>' : '')
            . ($about === '' ? '' : ' <span class="description">' . esc_html($about) . '</span>')
            . ($facts === [] ? '' : '<br><span class="description">' . implode(' · ', $facts) . '</span>');
    }

    /** One order's own view: its head with the buttons, then its purchases — the receipt form once it has arrived. */
    private function detailView(InboundOrder $order, InboundStage $stage, string $label, string $today, string $tab): string
    {
        $this->detail = true;
        $counts = $this->inbound->purchaseCounts($order->id);
        $about  = $this->inbound->about($order->id);
        $late   = $order->overdue($this->inbound->stock()->structure(), $today, $this->inbound->stock()->inboundArchive($today));
        $orderLink = $this->inbound->orderLink($order->id);
        $fact   = static fn (string $name, string $value): string => $value === '' ? '' : '<tr><th scope="row" style="width:10em">' . esc_html($name) . '</th><td>' . $value . '</td></tr>';

        // His mark 2026-11-09: no empty line between the arrows and the way back — the arrows keep their own margin.
        return '<p style="margin-top:0"><a href="' . esc_url(self::url(['tab' => $tab])) . '">← ' . esc_html(sprintf(/* translators: %s: name of the tab */ __('Zurück zu «%s»', 'taxmod-stock'), $this->tabName($tab))) . '</a></p>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1em;flex-wrap:wrap">'
            . '<div><h2 style="margin:.2em 0">' . esc_html($label) . ($late ? ' <span style="color:#b32d2e;font-size:.8em">' . esc_html__('überfällig', 'taxmod-stock') . '</span>' : '') . '</h2>'
            . ($about === '' ? '' : '<p class="description" style="margin-top:0">' . esc_html($about) . '</p>') . '</div></div>'
            // D-1308: the order's acts as the toolbar, top left under its name — unless the purchases come as the one table,
            // whose bar carries them (D-1403, his word 2026-11-16 «was ist aus der einen toolbar geworden»).
            . (self::oneTable($stage, $counts['total']) ? '' : ListPage::toolbar([$this->buttons($order, $stage, $counts['booked'], $tab)]))
            . '<div style="display:flex;gap:1em;align-items:flex-start;flex-wrap:wrap;margin-bottom:1em">'
            . '<table class="widefat" style="max-width:40em"><tbody>'
            . $fact(__('Stufe', 'taxmod-stock'), esc_html(self::stageName($stage)))
            . $fact(__('Bestellt am', 'taxmod-stock'), esc_html(ListPage::date(substr((string) $order->orderedOn, 0, 10))))
            // D-1249: the order at its platform, from the firm's template — built here, never stored.
            . $fact(__('Bestellung', 'taxmod-stock'), $orderLink === null ? '' : '<a href="' . esc_url($orderLink) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Bei der Plattform öffnen', 'taxmod-stock') . '</a>')
            . $fact(__('Sendung', 'taxmod-stock'), $this->trackingCell($order))
            . $fact(__('Angekommen', 'taxmod-stock'), esc_html(ListPage::date(substr((string) $order->receivedOn, 0, 10))))
            . $fact(__('Käufe', 'taxmod-stock'), esc_html(sprintf(/* translators: 1: purchases, 2: of them in stock */ __('%1$d, davon %2$d gebucht', 'taxmod-stock'), $counts['total'], $counts['booked'])))
            . '</tbody></table>'
            . (in_array($stage, [InboundStage::Booked, InboundStage::Archived], true) ? $this->materialPictures($order->id) : '') . '</div>'
            . $this->details($order, $stage, $tab, true)
            . (new StockPage($this->inbound->stock()))->script(true);
    }

    /** D-1403: the stages whose purchases stand in the one table of the Lager page, with the order's acts in its bar. */
    private static function oneTable(InboundStage $stage, int $purchases): bool
    {
        return $stage === InboundStage::Arrived || ($purchases > 0 && in_array($stage, [InboundStage::Ordered, InboundStage::Shipped], true));
    }

    /** D-1403: the order's own acts for the bar of the one table, as its view would show them. */
    public function orderActs(int $id): string
    {
        $stock = $this->inbound->stock();
        $order = $this->inbound->orders()[$id] ?? null;

        if ($order === null) {
            return '';
        }

        $stage        = $order->stage($stock->structure(), $stock->inboundArchive(current_time('Y-m-d')));
        $this->detail = true;

        return $this->buttons($order, $stage, $this->inbound->purchaseCounts($id)['booked'], $stage->value);
    }

    private function tabName(string $tab): string
    {
        $stage = InboundStage::tryFrom($tab);

        return match (true) {
            $tab === self::ALL  => __('Alle', 'taxmod-stock'),
            $tab === self::TEST => __('Test', 'taxmod-stock'),
            $stage !== null     => self::stageName($stage),
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
            $html .= $this->button($order->id, 'refresh', __('Sendungsstatus aktualisieren', 'taxmod-stock'), $tab, false);
        }

        return $html;
    }

    private function trackingButton(int $order, string $tab): string
    {
        $field = StockPage::actForm([['label' => __('Sendungsnummer', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Line, 'number', '', ['size' => 40])]]);
        $form  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay()
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);

        return $form . ListPage::dialogAction('taxmod-stock-track-' . $order, __('Sendungsnummer', 'taxmod-stock'), __('Sendungsnummer hinzufügen', 'taxmod-stock'), $field, 'op', 'tracking', __('Hinzufügen', 'taxmod-stock'), icon: 'location') . '</form> ';
    }

    private function button(int $order, string $op, string $label, string $tab, bool $primary, string $confirm = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"'
            . ($confirm === '' ? '' : ListPage::confirm($confirm)) . '>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay()
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            // D-1500: a row's action is an icon square, its word the tooltip.
            . ListPage::iconButton(self::ICONS[$op] ?? 'admin-generic', $label) . '</form> ';
    }

    /** D-1500: the icon of each row action of an order or a purchase. */
    private const ICONS = [
        'arrived'      => 'yes-alt',
        'back'         => 'undo',
        'unbook'       => 'undo',
        'refresh'      => 'update',
        'private'      => 'hidden',
        'unprivate'    => 'undo',
        'take_picture' => 'format-image',
    ];

    /** «Angekommen»: the Lager page's booking form, loaded once it shows; else the purchases, the carrier's events and a tracking field. */
    /**
     * His wish 2026-11-09: «bei eingebucht auch die materialbilder anzeigen … rechts neben der box oben» — the title picture of
     * each material the order went in as, once each, linked to the material; none when lists show no pictures (one switch).
     */
    /**
     * D-1452: «Eingebucht» as a list of purchases — each row where its purchase went ({@see whereItWent()}), the material's title
     * picture before it (his wish 2026-11-09, D-1204), the pictures read once for every order shown.
     *
     * @param list<int> $orders
     * @return \Closure(int): array{material: string, place: string, date: string}
     */
    private function wentWithPictures(array $orders): \Closure
    {
        $stock  = $this->inbound->stock();
        $s      = $stock->structure();
        $ledger = $this->ledger ??= $stock->ledger();
        $thingOf = [];

        foreach ($orders as $order) {
            foreach (array_map('intval', $stock->table('order')->all($order, $s->field('order_lines'))) as $purchase) {
                foreach ($this->inbound->lotsOf($purchase) as $lot) {
                    $thing = $ledger->thingOf($lot);
                    $thing === null ? null : $thingOf[$purchase][$thing] = true;
                }
            }
        }

        $pictures = array_filter(ListPage::thumbnails($stock->titleImages(array_keys(array_replace([], ...array_values($thingOf))))));
        $names    = $stock->things();
        // D-1461, his wish 2026-11-18 18:41: «bei eingebucht … ein Bestandsetikett» — each lot's label button, read once for all.
        $lotsOf   = [];

        foreach ($orders as $order) {
            foreach (array_map('intval', $stock->table('order')->all($order, $s->field('order_lines'))) as $purchase) {
                $lotsOf[$purchase] = array_map('intval', $this->inbound->lotsOf($purchase));
            }
        }

        $labels = (new StockPage($stock))->lotLabelButtons(array_values(array_unique(array_merge([], ...array_values($lotsOf)))));

        return function (int $purchase) use ($thingOf, $pictures, $names, $lotsOf, $labels): array {
            $cells = $this->whereItWent($purchase);
            $print = implode(' ', array_filter(array_map(static fn (int $lot): string => $labels[$lot] ?? '', $lotsOf[$purchase] ?? [])));
            $cells['date'] .= $print === '' ? '' : ' <span class="taxmod-row-actions">' . $print . '</span>';
            $shown = '';

            foreach (array_keys($thingOf[$purchase] ?? []) as $thing) {
                if (isset($pictures[$thing])) {
                    $name   = $names->word($thing);
                    $shown .= '<a href="' . esc_url(StockPage::thingUrl($thing, 'daten')) . '" title="' . esc_attr($name) . '" aria-label="' . esc_attr($name) . '">' . $pictures[$thing] . '</a>';
                }
            }

            $cells['material'] = ($shown === '' ? '' : '<span class="taxmod-stock-material-pictures" style="float:left;margin:0 8px 4px 0">' . $shown . '</span>') . $cells['material'];

            return $cells;
        };
    }

    private function materialPictures(int $order): string
    {
        $stock  = $this->inbound->stock();
        $s      = $stock->structure();
        $ledger = $this->ledger ??= $stock->ledger();
        $things = [];

        foreach (array_map('intval', $stock->table('order')->all($order, $s->field('order_lines'))) as $purchase) {
            foreach ($this->inbound->lotsOf($purchase) as $lot) {
                $thing = $ledger->thingOf($lot);
                $thing === null ? null : $things[$thing] = true;
            }
        }

        $pictures = array_filter(ListPage::thumbnails($stock->titleImages(array_keys($things))));
        $names    = $stock->things();
        $out      = '';

        foreach ($pictures as $thing => $img) {
            $name = $names->word($thing);
            $out .= '<a href="' . esc_url(StockPage::thingUrl($thing, 'daten')) . '" title="' . esc_attr($name) . '" aria-label="' . esc_attr($name) . '">' . $img . '</a>';
        }

        return $out === '' ? '' : '<div class="taxmod-stock-material-pictures" style="display:flex;gap:.5em;flex-wrap:wrap">' . $out . '</div>';
    }

    /**
     * His question of 2026-10-28 on a booked order: «wo befinden die?» — per lot its material, its place and the day it came
     * in, each a link (his wish 2026-11-09, his choice «Als Spalten in der Käufe-Tabelle»): the material to the material, the
     * place to its stock, the day to the lot.
     *
     * @return array{material: string, place: string, date: string} cells, one line per lot
     */
    private function whereItWent(int $purchase): array
    {
        $stock  = $this->inbound->stock();
        $s      = $stock->structure();
        $lots   = $stock->table('lot');
        $ledger = $this->ledger ??= $stock->ledger();
        $things = $stock->things();
        $places = $stock->places();
        $out    = ['material' => [], 'place' => [], 'date' => []];

        foreach ($this->inbound->lotsOf($purchase) as $lot) {
            $thing = $ledger->thingOf($lot);
            $at    = (int) $lots->first($lot, $s->field('lot_location'));
            $place = $at > 0 ? ($places[$at] ?? $stock->locationTree()->pathOf($at)) : '';
            $date  = substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10);
            $out['material'][] = $thing === null ? esc_html('#' . $lot) : '<a href="' . esc_url(StockPage::thingUrl($thing, 'daten')) . '">' . esc_html($things->word($thing)) . '</a>';
            // His word 2026-11-09: «weiterhin fehlt der platz auf dem es jetzt liegt mit absprunglink zu platz bestand».
            $out['place'][]    = $place === '' ? '—' : '<a href="' . esc_url(add_query_arg(['page' => StockPage::PAGE, 'tab' => 'lagerorte', 'ort' => $at], admin_url('admin.php'))) . '">' . esc_html($place) . '</a>';
            $out['date'][]     = $thing === null || $date === '' ? esc_html($date === '' ? '—' : ListPage::date($date)) : '<a href="' . esc_url(StockPage::lotUrl($thing, $lot)) . '">' . esc_html(ListPage::date($date)) . '</a>';
        }

        return array_map(static fn (array $cells): string => implode('<br>', $cells), $out);
    }

    /**
     * INF-116: what tells two purchases apart before they arrive — the description (variant, pack) and the article number,
     * in full under the name; the number links to the product at the platform where its firm has a template (D-1249).
     */
    public static function purchaseFacts(InboundService $inbound, Table $table, int $order, int $purchase): string
    {
        $s     = $inbound->stock()->structure();
        $facts = [];

        foreach (['description' => '', 'purchase_number' => __('Artikelnummer', 'taxmod-stock')] as $key => $name) {
            $value = $s->hasField($key) ? trim((string) ($table->first($purchase, $s->field($key)) ?? '')) : '';

            if ($value === '') {
                continue;
            }

            $link    = $key === 'purchase_number' ? $inbound->productLink($order, $value) : null;
            $facts[] = ($name === '' ? '' : esc_html($name) . ': ')
                . ($link === null ? esc_html($value) : '<a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a>');
        }

        return $facts === [] ? '' : '<br><span class="description">' . implode(' · ', $facts) . '</span>';
    }

    /**
     * The order number, linked to the order at the platform (D-1249) — his word 2026-11-09 «auf den kauf soll nur noch
     * bestellnummer mit link eingefügt werden», and again «anstatt auf die bestellung geht der link auf das material ?»: the
     * article number linked to the product was a misreading (D-1283).
     */
    private function purchaseNumber(int $order): string
    {
        $value = $this->inbound->orderNumber($order);
        $link  = $value === '' ? null : $this->inbound->orderLink($order);

        return match (true) {
            $value === ''   => '',
            $link === null  => '<br><span class="description">' . esc_html($value) . '</span>',
            default         => '<br><span class="description"><a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a></span>',
        };
    }

    /**
     * D-1375: the warning beside a unit price — how far above the last purchase, and a link to that one.
     *
     * @param array{previous: int, previous_unit: float, unit: float, percent: float, previous_order: ?int}|null $warning
     */
    public static function priceWarning(?array $warning): string
    {
        if ($warning === null) {
            return '';
        }

        $order   = $warning['previous_order'];
        $text    = sprintf(
            /* translators: 1: percent above, 2: the earlier unit price with its currency */
            __('%1$s %% über dem letzten Kauf (%2$s)', 'taxmod-stock'),
            ListPage::number(round($warning['percent']), 0),
            ListPage::money($warning['previous_unit'], 'EUR')
        );
        $shown   = $order === null ? esc_html($text) : '<a href="' . esc_url(self::url(['order' => $order]) . '#taxmod-stock-purchase-' . $warning['previous']) . '">' . esc_html($text) . '</a>';

        return '<br><span class="taxmod-stock-price-warning" style="color:#b32d2e;white-space:nowrap" title="' . esc_attr__('Bitte prüfen: Packungspreis statt Stückpreis?', 'taxmod-stock') . '">⚠ ' . $shown . '</span>';
    }

    private function details(InboundOrder $order, InboundStage $stage, string $tab, bool $open): string
    {
        if ($stage === InboundStage::Arrived) {
            $pick = implode(',', array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) ($_GET['pick'] ?? '')))))));

            return '<div class="taxmod-stock-positions" data-order="' . esc_attr((string) $order->id) . '" data-back="' . esc_attr(self::BACK) . '" data-stage="' . esc_attr($stage->value) . '"' . ($pick === '' ? '' : ' data-pick="' . esc_attr($pick) . '"') . '></div>';
        }

        $labels    = $this->inbound->stock()->labels('purchase');
        $s         = $this->inbound->stock()->structure();
        // D-1411: a tab shows the purchases that stand in it.
        $purchases = $order->purchasesIn($stage, array_map('intval', $this->inbound->stock()->table('order')->all($order->id, $s->field('order_lines'))));
        $done      = in_array($stage, [InboundStage::Booked, InboundStage::Archived], true);
        $merged    = $purchases !== [] && in_array($stage, [InboundStage::Ordered, InboundStage::Shipped], true);
        $rows      = '';
        $table     = $this->inbound->stock()->table('purchase');
        $parts     = [];

        foreach ($merged ? [] : $purchases as $purchase) {
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
        $shown     = static fn (?float $amount): string => $amount === null ? '—' : ListPage::number(round($amount, 2), 2);

        $stock   = $this->inbound->stock();
        $closing = $stock->canCloseWithoutStock();
        $booked  = $stock->receivedPurchases();
        // His wish 2026-11-09 (INF-116): «um zu entscheiden ob etwas angekommen ist ist das wenig info» — the picture (D-1204),
        // and what the purchase already says, read from the one table of this order's purchases (CD-7).
        $ids      = $merged ? [] : array_map('intval', $purchases);
        $pictures = ListPage::thumbnails($stock->titleImages($ids));
        // D-1264, «Bild übernehmen»: offered where the purchase has a picture its material does not hold yet — one read for both.
        $models   = array_filter(array_combine($ids, array_map(static fn (int $p): int => (int) $table->first($p, $s->field('purchase_model')), $ids)) ?: []);
        $held     = $stock->picturesOf([...$ids, ...array_values($models)]);

        // D-1291, his word 2026-11-09 «auch fehlt die einheit (€)»: the prices as the modeller words them, with their unit.
        $words = $stock->fieldWords($ids, ['purchase_unit_price', 'purchase_total_price']);
        // D-1375: a unit price far above the last purchase of the same material is marked, before anything is booked.
        $warned = $done || $merged ? [] : $this->inbound->priceWarnings($ids);

        foreach ($merged ? [] : $purchases as $purchase) {
            $purchase = (int) $purchase;
            $reason   = $closing ? $stock->notStockedReason($purchase) : '';
            // D-1060: private purchases are marked here, before anything arrives; a marked one can always be taken back.
            $button   = match (true) {
                ! $closing || isset($booked[$purchase]) => '',
                $reason !== ''                          => ' <em>(' . esc_html($reason) . ')</em> ' . $this->purchaseButton($order->id, $purchase, 'unprivate', __('Zurücknehmen', 'taxmod-stock'), $tab),
                default                                 => ' ' . $this->purchaseButton($order->id, $purchase, 'private', __('Privat', 'taxmod-stock'), $tab),
            };
            $picture = PurchasePictures::titleOf($held[$purchase] ?? null);

            if ($picture > 0 && isset($models[$purchase]) && ! PurchasePictures::holds($held[$models[$purchase]] ?? ['title' => null, 'pictures' => []], $picture)) {
                $button .= ' ' . $this->purchaseButton($order->id, $purchase, 'take_picture', __('Bild übernehmen', 'taxmod-stock'), $tab);
            }

            $count = (float) ($table->first($purchase, $s->field('purchase_quantity')) ?? 0);
            $each  = $amountOf($table->first($purchase, $s->field('purchase_unit_price')));
            $total = $s->hasField('purchase_total_price') ? $amountOf($table->first($purchase, $s->field('purchase_total_price'))) : null;
            $total ??= $each === null || $count <= 0.0 ? null : $each * $count;
            // His wish 2026-11-09: once booked, the purchase is named by its number only; where it went stands in its own columns.
            $went  = $done ? $this->whereItWent($purchase) : null;
            // D-1310: a link to one purchase jumps to its row.
            $rows .= '<tr id="taxmod-stock-purchase-' . esc_attr((string) $purchase) . '"><th scope="row" class="check-column"></th><td style="white-space:normal">' . ($pictures[$purchase] ?? '') . ' ' . esc_html($labels[$purchase] ?? '#' . $purchase)
                . ($done ? $this->purchaseNumber($order->id) : self::purchaseFacts($this->inbound, $table, $order->id, $purchase)) . '</td>'
                . ($went === null ? '' : '<td>' . $went['material'] . '</td><td>' . $went['place'] . '</td><td>' . $went['date'] . '</td>')
                . '<td style="text-align:right">' . esc_html($count > 0.0 ? (string) (0 + $count) : '—') . '</td>'
                . '<td style="text-align:right">' . esc_html($words['purchase_unit_price'][$purchase] ?? $shown($each)) . self::priceWarning($warned[$purchase] ?? null) . '</td>'
                . '<td style="text-align:right">' . esc_html($words['purchase_total_price'][$purchase] ?? $shown($total)) . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $button . '</td></tr>';
        }

        // His wish 2026-11-01: «wissen wir doch schon anzahl und preis und sowas können wir das nicht schöner anzeigen».
        $columns = array_merge(
            [[__('Kauf', 'taxmod-stock')]],
            $done ? [[__('Material', 'taxmod-stock'), '14em'], [__('Lagerort', 'taxmod-stock'), '12em'], [__('Eingebucht am', 'taxmod-stock'), '8em']] : [],
            [[__('Menge', 'taxmod-stock'), '5em'], [__('Preis je Stück', 'taxmod-stock'), '7em'], [__('Gesamt', 'taxmod-stock'), '7em']]
        );
        // His choice 2026-11-09 (INF-116): «Material schon am Kauf wählen» — the receipt's own choosing, before anything arrives;
        // it keeps the choice and books nothing. His word 2026-11-16 «eine bitte nur mit beiden funktionen»: then it is the one table.
        $html = '<h4 style="margin-bottom:.3em">' . esc_html__('Käufe', 'taxmod-stock') . '</h4>'
            . ($merged ? '<div class="taxmod-stock-positions" data-order="' . esc_attr((string) $order->id) . '" data-back="' . esc_attr(self::BACK) . '" data-assign="1" data-stage="' . esc_attr($stage->value) . '"></div>' : ListPage::table($columns, $rows));

        $html .= $this->events($order);

        if (! in_array($stage, [InboundStage::Booked, InboundStage::Archived], true)) {
            // D-1009: every step can be taken back — a number added by mistake comes off here.
            foreach ($order->tracking as $number) {
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 .3em">'
                    . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="untrack">'
                    . '<input type="hidden" name="order" value="' . esc_attr((string) $order->id) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . $this->stay()
                    . '<input type="hidden" name="number" value="' . esc_attr($number) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
                    . '<code>' . esc_html($number) . '</code> ' . ListPage::iconButton('trash', __('Entfernen', 'taxmod-stock')) . '</form>';
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
            . ListPage::iconButton(self::ICONS[$op] ?? 'admin-generic', $label) . '</form>';
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
        $haystack = $label . ' ' . $about . ' ' . implode(' ', $tracking);

        // D-1357: every word must match, each by the one rule with «*».
        foreach (preg_split('/\s+/', trim($search)) ?: [] as $word) {
            if ($word !== '' && ! Wildcard::matches($haystack, $word)) {
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

    /**
     * D-1197, D-1258: the stages of the goods receipt as arrows with their counts, each opening its list; the same bar on the
     * list and on the Wareneingang overview of the cockpit. «Alle» is no arrow (his word 2026-11-09), only a view by address.
     *
     * @param array<string, int> $counts orders per stage, by the stage's tab
     * @param int|null           $toTest the lots waiting for their test, null when testing is not set up
     */
    public static function processBar(array $counts, ?int $toTest, string $active): string
    {
        $tabs = array_map(static fn (InboundStage $stage): array => [$stage->value, self::stageName($stage), $counts[$stage->value] ?? 0], InboundStage::tabs());

        if ($toTest !== null) {
            $tabs[] = [self::TEST, __('Test', 'taxmod-stock'), $toTest];
        }

        // D-1008: «Archiviert» after the working stages.
        $tabs[] = [InboundStage::Archived->value, self::stageName(InboundStage::Archived), $counts[InboundStage::Archived->value] ?? 0];
        $url    = static fn (string $slug): string => self::url(['tab' => $slug]);

        return ListPage::steps($tabs, $active, $url);
    }

    private static function stageName(InboundStage $stage): string
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


}
