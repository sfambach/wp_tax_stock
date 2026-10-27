<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\MaterialInput;
use TaxmodStock\Core\MaterialNames;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\StockList;
use TaxmodStock\WordPress\StockService;

/**
 * The page «Lager» under the Taxonomy Modeller: stock list, materials, stock count, goods receipt, consumption (stage 1, D-958 F7).
 *
 * A material is shown by the name of its «Bezug», its number in a column of its own (D-967, D-988).
 *
 * Drawn with the modeller's shared list page (D-989): heading, tabs with counts, search, filters, bulk bar with
 * «Expand all», sortable columns, the row's buttons on the right, its details in a row below.
 * «Wareneingang» comes first, as the daily task.
 *
 * Every form posts to one handler, which works in the order of CD-5 and books in one write (all or nothing).
 */
final class StockPage
{
    public const PAGE       = 'taxmod-stock';
    public const ACTION     = 'taxmod_stock_book';
    public const POSITIONS  = 'taxmod_stock_positions';
    private const NOTICE    = 'taxmod_stock_notice';
    private const ROWS      = 6;
    private const TABS      = ['wareneingang', 'bestand', 'material', 'inventur', 'verbrauch'];
    private const FILTER_FORM = 'taxmod-stock-filter';
    private const BULK_FORM   = 'taxmod-stock-bulk';

    /** The modeller's search over every record under `Model` (D-958 F3a), reused for «Bezug». */
    private const RECORD_SEARCH = 'taxmod_record_search';

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    public function render(): string
    {
        $tab  = sanitize_key((string) ($_GET['tab'] ?? self::TABS[0]));
        $tab  = in_array($tab, self::TABS, true) ? $tab : self::TABS[0];
        $list = $this->listRequest();
        if (! class_exists(ListPage::class) || ! method_exists(ListPage::class, 'tabs')) {
            return '<div class="wrap"><h1>' . esc_html__('Lager', 'taxmod-stock') . '</h1><div class="notice notice-error"><p>'
                . esc_html__('Die Lagerseite braucht den Taxonomy Modeller ab Version 0.1.65 (gemeinsame Listenseite).', 'taxmod-stock') . '</p></div></div>';
        }

        $html = '<div class="wrap">' . ListPage::heading(__('Lager', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice();

        try {
            $html .= $this->tabBar($tab, $list) . match ($tab) {
                'bestand'      => $this->stockList($list),
                'material'     => $this->materialTab(absint($_GET['material'] ?? 0), $list),
                'inventur'     => $this->inventoryForm(),
                'verbrauch'    => $this->consumptionForm(),
                default        => $this->receiptTab($list, absint($_GET['order'] ?? 0)),
            };
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . $this->script() . '</div>';
    }

    /** admin-post handler: capability → nonce → validate → sanitize → act → back to the page with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'taxmod-stock'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        $kind  = sanitize_key((string) ($_POST['booking'] ?? ''));
        $today = current_time('Y-m-d');
        $back  = ['bestand', []];

        try {
            $result = match ($kind) {
                'inventur'     => $this->stock->bookInventory($this->inventoryRows(), $today, $this->title(__('Inventur', 'taxmod-stock'), $today)),
                'material'     => $this->stock->saveMaterial(absint($_POST['material'] ?? 0) ?: null, $this->materialInput()),
                'wareneingang' => $this->stock->bookReceipt(
                    absint($_POST['order'] ?? 0),
                    $this->receiptChoices(),
                    $today,
                    $this->title(__('Wareneingang', 'taxmod-stock'), $today)
                ),
                'verbrauch'    => $this->stock->bookConsumption(
                    absint($_POST['material'] ?? 0),
                    absint($_POST['lot'] ?? 0) ?: null,
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    absint($_POST['project'] ?? 0) ?: null,
                    $today,
                    $this->title(__('Verbrauch', 'taxmod-stock'), $today)
                ),
                default        => throw StockError::nothingToBook(),
            };
            /* translators: %d: number of changes the modeller applied */
            $this->remember('success', sprintf(__('Gebucht: %d Änderungen in einem Schritt.', 'taxmod-stock'), count((array) ($result['applied'] ?? []))));

            if ($kind === 'material') {
                $back = ['material', []];
            }
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
            $back = [$kind, $kind === 'wareneingang' ? ['order' => absint($_POST['order'] ?? 0)] : ($kind === 'material' ? ['material' => absint($_POST['material'] ?? 0)] : [])];
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => $back[0]] + $back[1], admin_url('admin.php')));
        exit;
    }

    private function stockList(StockList $list): string
    {
        $s         = $this->stock->structure();
        $ledger    = $this->stock->ledger();
        $names     = $this->stock->materialNames();
        $materials = $names->all();
        $places    = $this->stock->labels('location');
        $lots      = $this->stock->table('lot');
        $money     = $this->stock->table('money');
        $matTable  = $this->stock->table('material');

        if ($materials === []) {
            return '<p>' . esc_html__('Noch kein Material. Eine Inventur oder ein Wareneingang legt es an.', 'taxmod-stock') . '</p>';
        }

        $rows = [];
        $mins = [];
        $lis  = [];

        foreach (array_keys($materials) as $id) {
            $stock      = $ledger->stockOf($id);
            $min        = $matTable->first($id, $s->field('material_min'));
            $mins[$id]  = $min;
            $lis[$id]   = '';
            $words      = [];

            foreach ($ledger->lotsOf($id) as $lot) {
                $value    = $lots->first($lot, $s->field('lot_value'));
                $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
                $purchase = $lots->first($lot, $s->field('lot_purchase'));
                $place    = $lots->first($lot, $s->field('lot_location'));
                $where    = $place === null ? null : ($places[(int) $place] ?? '#' . $place);
                $words[]  = (string) $where;
                $words[]  = (string) $purchase;

                $lis[$id] .= '<li>' . esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10))
                    . ' · ' . esc_html__('Rest', 'taxmod-stock') . ' ' . esc_html($this->number($ledger->restOf($lot)))
                    . ($amount === null ? '' : ' · ' . esc_html($amount) . ' ' . esc_html__('je Stück', 'taxmod-stock'))
                    . ($purchase === null ? ' · ' . esc_html__('Inventur', 'taxmod-stock') : ' · ' . esc_html__('Kauf', 'taxmod-stock') . ' #' . esc_html($purchase))
                    . ($where === null ? '' : ' · ' . esc_html($where)) . '</li>';
            }

            $rows[] = ['id' => $id, 'name' => $names->word($id), 'stock' => $stock, 'low' => $min !== null && $stock < (float) $min, 'words' => array_values(array_filter($words))];
        }

        $shown = $list->apply($rows);
        $open  = $this->stock->viewSettings()->expanded('bestand');
        $html  = $this->tools('bestand', $list, 'filter', $list->filter, $this->materialFilters(), __('Material, Nummer, Lagerort …', 'taxmod-stock'), sprintf(
            /* translators: %d: number of materials */
            _n('%d Material', '%d Materialien', count($shown), 'taxmod-stock'),
            count($shown)
        ));
        $body  = $shown === [] ? $this->noItems(5) : '';

        foreach ($shown as $row) {
            $id    = $row['id'];
            $body .= ListPage::row(self::BULK_FORM, (string) $id, [
                esc_html((string) $id),
                '<strong>' . esc_html($row['name'] === '' ? '—' : $row['name']) . '</strong>',
                '<span' . ($row['low'] ? ' style="color:#b32d2e;font-weight:600"' : '') . '>' . esc_html($this->number($row['stock'])) . '</span>',
                esc_html((string) ($mins[$id] ?? '—')),
                esc_html((string) count($ledger->lotsOf($id))),
            ], $this->editButton($id), $lis[$id] === '' ? '—' : '<ul style="margin:0">' . $lis[$id] . '</ul>', $open);
        }

        return $html . ListPage::table([[__('Nr.', 'taxmod-stock'), '6em'], $this->sortColumn('bestand', $list, 'name', __('Material', 'taxmod-stock'), ''), $this->sortColumn('bestand', $list, 'stock', __('Bestand', 'taxmod-stock'), '8em'),
            [__('Mindestbestand', 'taxmod-stock'), '10em'], [__('Bestandsposten', 'taxmod-stock'), '10em']], $body);
    }

    private function inventoryForm(): string
    {
        $options = $this->options($this->stock->materialNames()->all(), __('— neues Material —', 'taxmod-stock'));
        $html    = $this->formStart('inventur')
            . '<p>' . esc_html__('Gezählter Bestand ohne Kauf. Jede Zeile wird ein Bestandsposten. Einem späteren Kauf zuordnen kommt mit Stufe 2 (F10).', 'taxmod-stock') . '</p>'
            . '<p>' . esc_html__('Ein neues Material mit «Bezug» heisst wie sein Bezug; einen eigenen Namen braucht nur ein Material ohne Bezug.', 'taxmod-stock') . '</p>'
            . '<table class="wp-list-table widefat striped table-view-list"><thead><tr><th scope="col" class="manage-column">' . esc_html__('Material', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('oder neu: Name', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column">' . esc_html__('Bezug (Satz-Id)', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Menge', 'taxmod-stock') . '</th><th scope="col" class="manage-column">'
            . esc_html__('Wert je Stück', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Hinweis', 'taxmod-stock') . '</th></tr></thead><tbody>';

        for ($i = 0; $i < self::ROWS; $i++) {
            $html .= '<tr><td><select name="rows[' . $i . '][material]">' . $options . '</select></td>'
                . '<td><input type="text" name="rows[' . $i . '][name]"></td>'
                . '<td>' . $this->thingField('rows[' . $i . '][thing]', 'taxmod-stock-thing-' . $i, null) . '</td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][quantity]" style="width:6em"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][value]" style="width:6em"></td>'
                . '<td><input type="text" name="rows[' . $i . '][note]"></td></tr>';
        }

        return $html . '</tbody></table>' . get_submit_button(__('Inventur buchen', 'taxmod-stock')) . '</form>';
    }

    /**
     * The orders as a list; each one opens to its purchases below, read only when he opens it.
     *
     * An order handed in with `order` (back from a refused booking) is open and loaded, whatever the filter says.
     */
    private function receiptTab(StockList $list, int $open): string
    {
        $s       = $this->stock->structure();
        $orders  = $this->stock->labels('order');
        $table   = $this->stock->table('order');
        $status  = sanitize_key((string) ($_GET['status'] ?? 'open'));
        $rows    = [];
        $states  = [];
        $words   = $this->purchaseWords();

        foreach ($orders as $id => $label) {
            $state       = $this->orderState($table->first($id, $s->field('order_status')));
            $states[$id] = $state;

            if ($id !== $open && $status !== '' && ($status === 'open' ? $state === 'received' : $state !== $status)) {
                continue;
            }

            $rows[] = ['id' => $id, 'name' => $label, 'stock' => (float) count($table->all($id, $s->field('order_lines'))), 'low' => false,
                'words' => array_merge(...array_map(static fn (string $purchase): array => $words[(int) $purchase] ?? [], $table->all($id, $s->field('order_lines'))))];
        }

        $shown = (new StockList($list->search, '', $list->orderBy, $list->descending))->apply($rows);
        $auto  = $this->stock->viewSettings()->expanded('wareneingang');
        $html  = $this->tools('wareneingang', $list, 'status', $status, [
            'open'     => __('Nicht eingegangen', 'taxmod-stock'),
            'partial'  => __('Teilweise eingegangen', 'taxmod-stock'),
            'received' => __('Eingegangen', 'taxmod-stock'),
            ''         => __('Alle Bestellungen', 'taxmod-stock'),
        ], __('Bestellung, Nummer …', 'taxmod-stock'), sprintf(
            /* translators: %d: number of orders */
            _n('%d Bestellung', '%d Bestellungen', count($shown), 'taxmod-stock'),
            count($shown)
        ));
        $body  = $shown === [] ? $this->noItems(3) : '';

        foreach ($shown as $row) {
            $id     = $row['id'];
            $loaded = $id === $open;
            $body  .= ListPage::row(self::BULK_FORM, (string) $id, [
                '<strong>' . esc_html($row['name']) . '</strong>',
                esc_html($this->stateName($states[$id])),
                esc_html((string) (int) $row['stock']),
            ], '', '<div class="taxmod-stock-positions" data-order="' . esc_attr((string) $id) . '"' . ($loaded ? ' data-loaded="1"' : '') . '>'
                . ($loaded ? $this->receiptPositions($id) : '') . '</div>', $loaded || $auto);
        }

        return $html . ListPage::table([$this->sortColumn('wareneingang', $list, 'name', __('Bestellung', 'taxmod-stock'), ''), [__('Status', 'taxmod-stock'), '12em'],
            $this->sortColumn('wareneingang', $list, 'stock', __('Positionen', 'taxmod-stock'), '8em')], $body);
    }

    /**
     * What the search finds an order by besides its name: each purchase's name, description and the name of its
     * «Modell» — «555» finds the order that holds an NE555 (his wish 2026-10-25). One read per node (CD-7).
     *
     * @return array<int, list<string>> purchase id → words
     */
    private function purchaseWords(): array
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $model     = $s->hasField('purchase_model') ? $s->field('purchase_model') : null;
        $models    = $model === null ? [] : $this->stock->recordLabels(array_values(array_unique(array_filter(array_map(
            static fn (int $id): int => (int) $purchases->first($id, $model),
            $purchases->ids()
        )))));
        $out       = [];

        foreach ($purchases->ids() as $id) {
            $out[$id] = array_values(array_filter([
                (string) $purchases->first($id, $s->field('label')),
                $s->hasField('description') ? (string) $purchases->first($id, $s->field('description')) : '',
                $model === null ? '' : ($models[(int) $purchases->first($id, $model)] ?? ''),
            ]));
        }

        return $out;
    }

    /**
     * What tells a purchase apart beyond its short label: the seller's full title and the quantity.
     *
     * A label from a mail is often only the variant («DR15 Female»); the description holds the article's title.
     *
     * @param list<int> $purchases
     * @return array<int, string>
     */
    private function purchaseAbout(array $purchases): array
    {
        $s     = $this->stock->structure();
        $table = $this->stock->table('purchase');
        $out   = [];

        foreach ($purchases as $id) {
            $label       = (string) $table->first($id, $s->field('label'));
            $description = $s->hasField('description') ? trim((string) $table->first($id, $s->field('description'))) : '';
            $quantity    = (int) $table->first($id, $s->field('purchase_quantity'));
            $parts       = array_filter([
                $description === $label ? '' : $description,
                $quantity > 1 ? sprintf(/* translators: %d: quantity bought */ __('%d Stück', 'taxmod-stock'), $quantity) : '',
            ]);
            $out[$id] = implode(' · ', $parts);
        }

        return $out;
    }

    /** admin-post handler for one order's purchases, fetched when he opens the order: capability → nonce → validate → act. */
    public function positions(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['html' => esc_html__('Keine Berechtigung.', 'taxmod-stock')], 403);
        }

        check_ajax_referer(self::POSITIONS);
        $order = absint($_GET['order'] ?? 0);

        try {
            wp_send_json_success(['html' => $this->receiptPositions($order)]);
        } catch (StockError $e) {
            wp_send_json_error(['html' => '<div class="notice notice-error inline"><p>' . esc_html(Messages::of($e)) . '</p></div>']);
        }
    }

    /** «open», «partial» or «received», read from the order's status value. */
    private function orderState(?string $value): string
    {
        $s = $this->stock->structure();

        return match ((int) $value) {
            $s->value('status_received') => 'received',
            $s->value('status_partial')  => 'partial',
            default                      => 'open',
        };
    }

    private function stateName(string $state): string
    {
        return match ($state) {
            'received' => __('eingegangen', 'taxmod-stock'),
            'partial'  => __('teilweise eingegangen', 'taxmod-stock'),
            default    => __('offen', 'taxmod-stock'),
        };
    }

    /** The purchases of one order with their material, Bezug and place, as one booking form. */
    private function receiptPositions(int $order): string
    {
        $orders = $this->stock->labels('order');

        if (! isset($orders[$order])) {
            throw StockError::nothingToBook();
        }

        $names  = $this->stock->materialNames();
        $plan   = $this->stock->receiptPlan($order, $names);
        $labels = $this->stock->labels('purchase');
        $about  = $this->purchaseAbout(array_keys($plan));
        $models = $this->stock->recordLabels(array_values(array_filter(array_column($plan, 'model'))));
        $all    = $names->all();
        $places = $this->stock->labels('location');
        $where  = $this->stock->receiptLocations($plan, $names);

        $html  = $this->formStart('wareneingang') . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . '<p>' . esc_html__('Je Kauf ein Material: vorgeschlagen ist das Material, das schon auf das «Modell» des Kaufs verweist, sonst ein neues mit diesem Bezug. Suchen filtert die Liste; «neu anlegen» nimmt den angegebenen Bezug, ohne Angabe das Modell. Der Lagerort ist vorgeschlagen: der Standard-Lagerort des Materials, sonst der Ort seines Zweigs (Reiter «Lager»).', 'taxmod-stock') . '</p>'
            . '<div class="tablenav top"><div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html(sprintf(
                /* translators: %d: number of purchases */
                _n('%d Kauf', '%d Käufe', count($plan), 'taxmod-stock'),
                count($plan)
            )) . '</span></div><br class="clear"></div>';
        $head  = '<tr><td class="manage-column column-cb check-column"><input type="checkbox" aria-label="' . esc_attr__('Alle wählen', 'taxmod-stock') . '"></td>'
            . '<th scope="col" class="manage-column">' . esc_html__('Kauf', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Modell', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column" style="width:25%">' . esc_html__('Material', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Bezug für «neu anlegen»', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column">' . esc_html__('Lagerort', 'taxmod-stock') . '</th></tr>';
        $html .= '<table class="wp-list-table widefat fixed striped table-view-list"><thead>' . $head . '</thead><tbody>' . ($plan === [] ? $this->noItems(4) : '');

        foreach ($plan as $purchase => $row) {
            $lock     = $row['lock'];
            $proposal = $row['proposal'];
            $model    = $row['model'];
            $select   = 'taxmod-stock-choice-' . $purchase;
            $html    .= '<tr><th scope="row" class="check-column"><input type="checkbox" name="purchases[]" value="' . esc_attr((string) $purchase) . '"'
                . ($lock === null && $proposal !== null ? ' checked' : '') . ($lock === null ? '' : ' disabled') . '></th>'
                . '<td>' . esc_html($labels[$purchase] ?? '#' . $purchase) . ($lock === null ? '' : ' <em>(' . esc_html(Messages::lockReason($lock)) . ')</em>')
                . (($about[$purchase] ?? '') === '' ? '' : '<br><span class="description">' . esc_html($about[$purchase]) . '</span>') . '</td>'
                . '<td>' . ($model === null ? '—' : esc_html('#' . $model . (($models[$model] ?? '') === '' ? '' : ' · ' . $models[$model]))) . '</td>';

            if ($lock !== null) {
                $html .= '<td>' . esc_html(implode(', ', array_map($names->name(...), array_filter($row['materials'], $names->has(...))))) . '</td><td></td><td></td></tr>';
                continue;
            }

            $html .= '<td><input type="search" class="taxmod-stock-filter" data-for="' . esc_attr($select) . '" placeholder="' . esc_attr__('suchen …', 'taxmod-stock') . '" style="width:8em"> '
                . '<select id="' . esc_attr($select) . '" name="choice[' . esc_attr((string) $purchase) . ']">'
                . $this->choiceOptions($row['materials'], $proposal, $all, $names) . '</select></td>'
                . '<td>' . $this->thingField('thing[' . $purchase . ']', 'taxmod-stock-thing-p' . $purchase, null) . '</td>'
                . '<td><select name="location[' . esc_attr((string) $purchase) . ']">' . $this->options($places, '—', $where[$purchase] ?? 0) . '</select></td></tr>';
        }

        return $html . '</tbody></table>' . get_submit_button(__('Wareneingang buchen', 'taxmod-stock')) . '</form>';
    }

    /**
     * @param list<int>          $kept the materials the purchase already names
     * @param array<int, string> $all  every material, «#id · name»
     */
    private function choiceOptions(array $kept, ?ReceiptChoice $proposal, array $all, MaterialNames $names): string
    {
        $html = $proposal === null ? '<option value="">' . esc_html__('— Material wählen —', 'taxmod-stock') . '</option>' : '';

        if ($kept !== []) {
            $html .= '<option value="keep"' . selected($proposal?->keep ?? false, true, false) . '>' . esc_html(
                /* translators: %s: the materials the purchase names already */
                sprintf(__('wie zugeordnet: %s', 'taxmod-stock'), implode(', ', array_map(static fn (int $m): string => $names->has($m) ? $names->name($m) : '#' . $m, $kept)))
            ) . '</option>';
        }

        $html .= '<option value="new"' . selected($proposal !== null && $proposal->isNew(), true, false) . '>' . esc_html__('neu anlegen', 'taxmod-stock') . '</option>';

        foreach ($all as $id => $label) {
            $html .= '<option value="' . esc_attr((string) $id) . '"' . selected($proposal?->material, $id, false) . '>' . esc_html($label) . '</option>';
        }

        return $html;
    }

    private function materialTab(int $edit, StockList $list): string
    {
        $s      = $this->stock->structure();
        $names  = $this->stock->materialNames();
        $table  = $this->stock->table('material');
        $ledger = $this->stock->ledger();
        $uses   = $this->stock->uses();
        $places = $this->stock->labels('location');
        $field  = static fn (string $key): ?int => $s->hasField($key) ? $s->field($key) : null;
        $rows   = [];

        foreach (array_keys($names->all()) as $id) {
            $use    = $field('material_use') === null ? null : $table->first($id, (int) $field('material_use'));
            $min    = $table->first($id, $s->field('material_min'));
            $stock  = $ledger->stockOf($id);
            $rows[] = ['id' => $id, 'name' => $names->word($id), 'stock' => $stock, 'low' => $min !== null && $stock < (float) $min,
                'words' => array_values(array_filter([(string) $names->thingOf($id), $use === null ? '' : ($uses[(int) $use] ?? '')]))];
        }

        $shown = $list->apply($rows);
        $html  = $this->tools('material', $list, 'filter', $list->filter, $this->materialFilters(), __('Material, Nummer, Bezug …', 'taxmod-stock'), sprintf(
            /* translators: %d: number of materials */
            _n('%d Material', '%d Materialien', count($shown), 'taxmod-stock'),
            count($shown)
        ));
        $body  = $shown === [] ? $this->noItems(7) : '';

        foreach ($shown as $row) {
            $id    = $row['id'];
            $thing = $names->thingOf($id);
            $use   = $field('material_use') === null ? null : $table->first($id, (int) $field('material_use'));
            $body .= ListPage::row(self::BULK_FORM, (string) $id, [
                esc_html((string) $id),
                '<strong>' . esc_html($row['name'] === '' ? '—' : $row['name']) . '</strong>',
                $thing === null ? '—' : esc_html('#' . $thing),
                esc_html($table->first($id, $s->field('material_single')) === '1' ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')),
                esc_html($use === null ? '—' : ($uses[(int) $use] ?? '#' . $use)),
                esc_html((string) ($table->first($id, $s->field('material_min')) ?? '—')),
                '<span' . ($row['low'] ? ' style="color:#b32d2e;font-weight:600"' : '') . '>' . esc_html($this->number($row['stock'])) . '</span>',
            ], $this->editButton($id), esc_html(sprintf(
                /* translators: %d: number of stock lots */
                _n('%d Bestandsposten, Einzelheiten im Reiter «Bestand».', '%d Bestandsposten, Einzelheiten im Reiter «Bestand».', count($ledger->lotsOf($id)), 'taxmod-stock'),
                count($ledger->lotsOf($id))
            )));
        }

        $html .= ListPage::table([[__('Nr.', 'taxmod-stock'), '6em'], $this->sortColumn('material', $list, 'name', __('Material', 'taxmod-stock'), ''), [__('Bezug', 'taxmod-stock'), '8em'], [__('Einzelstück', 'taxmod-stock'), '7em'],
            [__('Verwendung', 'taxmod-stock')], [__('Mindestbestand', 'taxmod-stock'), '9em'], $this->sortColumn('material', $list, 'stock', __('Bestand', 'taxmod-stock'), '7em')], $body);

        $edit  = $table->has($edit) ? $edit : 0;
        $value = static fn (?int $relation): string => $edit === 0 || $relation === null ? '' : (string) $table->first($edit, $relation);
        $html .= '<h2 id="taxmod-stock-edit">' . esc_html($edit === 0 ? __('Neues Material', 'taxmod-stock') : sprintf(
            /* translators: %s: «#id · name» of the material */
            __('Material %s bearbeiten', 'taxmod-stock'),
            $names->name($edit)
        )) . '</h2>' . $this->formStart('material') . '<input type="hidden" name="material" value="' . esc_attr((string) $edit) . '">'
            . '<p>' . esc_html__('Mit «Bezug» heisst das Material wie sein Bezug, eine eigene Bezeichnung wird dann geleert. Ohne Bezug braucht es eine Bezeichnung.', 'taxmod-stock') . '</p>'
            . '<table class="form-table"><tbody>'
            . '<tr><th>' . esc_html__('Bezug', 'taxmod-stock') . '</th><td>' . $this->thingField('thing', 'taxmod-stock-thing-m', $edit === 0 ? null : $names->thingOf($edit)) . '</td></tr>'
            . '<tr><th>' . esc_html__('Bezeichnung (nur ohne Bezug)', 'taxmod-stock') . '</th><td><input type="text" name="name" class="regular-text" value="' . esc_attr($names->thingOf($edit) === null ? $value($s->field('label')) : '') . '"></td></tr>'
            . '<tr><th>' . esc_html__('Einzelstück', 'taxmod-stock') . '</th><td><input type="checkbox" name="single" value="1"' . checked($value($s->field('material_single')), '1', false) . '></td></tr>';

        if ($field('material_use') !== null) {
            $html .= '<tr><th>' . esc_html__('Verwendung', 'taxmod-stock') . '</th><td><select name="use">' . $this->options($uses, '—', (int) $value($field('material_use'))) . '</select></td></tr>';
        }

        if ($field('material_location') !== null) {
            $html .= '<tr><th>' . esc_html__('Standard-Lagerort', 'taxmod-stock') . '</th><td><select name="location">' . $this->options($places, '—', (int) $value($field('material_location'))) . '</select></td></tr>';
        }

        return $html . '<tr><th>' . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><td><input type="number" min="0" name="minimum" style="width:8em" value="' . esc_attr($value($s->field('material_min'))) . '"></td></tr>'
            . '</tbody></table>' . get_submit_button($edit === 0 ? __('Material anlegen', 'taxmod-stock') : __('Material speichern', 'taxmod-stock')) . '</form>';
    }

    private function consumptionForm(): string
    {
        $ledger = $this->stock->ledger();
        $labels = $this->stock->materialNames()->all();
        $lots   = $this->stock->table('lot');
        $date   = $this->stock->structure()->field('lot_date');
        $have   = array_intersect_key($labels, array_flip($ledger->materials()));
        $choice = '<option value="0">' . esc_html__('automatisch: ältester Posten mit Rest', 'taxmod-stock') . '</option>';

        foreach ($have as $material => $label) {
            foreach ($ledger->lotsOf($material) as $lot) {
                $choice .= '<option value="' . esc_attr((string) $lot) . '">' . esc_html($label . ' · ' . substr((string) $lots->first($lot, $date), 0, 10)
                    . ' · ' . __('Rest', 'taxmod-stock') . ' ' . $this->number($ledger->restOf($lot))) . '</option>';
            }
        }

        return $this->formStart('verbrauch')
            . '<p><label>' . esc_html__('Material', 'taxmod-stock') . ' <select name="material" required>' . $this->options($have, '') . '</select></label></p>'
            . '<p><label>' . esc_html__('Menge', 'taxmod-stock') . ' <input type="text" inputmode="decimal" name="quantity" required style="width:6em"></label></p>'
            . '<p><label>' . esc_html__('Bestandsposten', 'taxmod-stock') . ' <select name="lot">' . $choice . '</select></label></p>'
            . '<p><label>' . esc_html__('Projekt (Satz-Id, optional)', 'taxmod-stock') . ' <input type="number" min="1" name="project" style="width:8em"></label></p>'
            . get_submit_button(__('Verbrauch buchen', 'taxmod-stock')) . '</form>';
    }

    /**
     * The ticked purchases with their material — «keep», «new» (with the Bezug typed, else the Modell) or a material id — and their place.
     *
     * @return array<int, ReceiptChoice>
     */
    private function receiptChoices(): array
    {
        $choices = (array) ($_POST['choice'] ?? []);
        $things  = (array) ($_POST['thing'] ?? []);
        $places  = (array) ($_POST['location'] ?? []);
        $out     = [];

        foreach (array_filter(array_map('absint', (array) ($_POST['purchases'] ?? []))) as $purchase) {
            $choice = sanitize_key((string) wp_unslash($choices[$purchase] ?? ''));

            $out[$purchase] = (match (true) {
                $choice === 'keep'     => ReceiptChoice::keep(),
                $choice === 'new'      => ReceiptChoice::create(absint($things[$purchase] ?? 0) ?: null),
                absint($choice) > 0    => ReceiptChoice::existing(absint($choice)),
                default                => throw StockError::purchaseWithoutMaterial($purchase),
            })->at(absint($places[$purchase] ?? 0) ?: null);
        }

        return $out;
    }

    private function materialInput(): MaterialInput
    {
        $minimum = sanitize_text_field(wp_unslash((string) ($_POST['minimum'] ?? '')));

        return new MaterialInput(
            absint($_POST['thing'] ?? 0) ?: null,
            sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
            ! empty($_POST['single']),
            absint($_POST['use'] ?? 0) ?: null,
            absint($_POST['location'] ?? 0) ?: null,
            $minimum === '' ? null : absint($minimum)
        );
    }

    /**
     * «Bezug» as one search field that shows the record's name; its id travels in a hidden field beside it
     * (his question 2026-10-25: «was hält uns davon ab den einfach aufzulösen ?»). Typing «#123» sets the id directly.
     */
    private function thingField(string $name, string $id, ?int $value): string
    {
        $shown = '';

        if ($value !== null) {
            $label = $this->stock->recordLabels([$value])[$value] ?? '';
            $shown = '#' . $value . ($label === '' ? '' : ' · ' . $label);
        }

        return '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) ($value ?? '')) . '">'
            . '<input type="search" class="taxmod-stock-thing-search" data-for="' . esc_attr($id) . '" list="' . esc_attr($id . '-hits') . '" value="' . esc_attr($shown) . '" placeholder="'
            . esc_attr__('Bezug suchen …', 'taxmod-stock') . '" style="width:18em"><datalist id="' . esc_attr($id . '-hits') . '"></datalist>';
    }

    /** Filters a material list as one types, and asks the modeller's record search for «Bezug». */
    private function script(): string
    {
        $search    = add_query_arg(['action' => self::RECORD_SEARCH, '_taxmod_nonce' => wp_create_nonce(self::RECORD_SEARCH)], admin_url('admin-post.php'));
        $positions = add_query_arg(['action' => self::POSITIONS, '_ajax_nonce' => wp_create_nonce(self::POSITIONS)], admin_url('admin-post.php'));

        return '<script>(function(){'
            . 'var url=' . wp_json_encode($search) . ',pos=' . wp_json_encode($positions) . ';'
            . 'function bind(root){'
            . 'root.querySelectorAll(".taxmod-stock-filter").forEach(function(f){var s=document.getElementById(f.dataset.for);if(!s){return;}var o=Array.prototype.slice.call(s.options);'
            . 'f.addEventListener("input",function(){var q=f.value.toLowerCase(),first=null;o.forEach(function(x){var fix=x.value===""||x.value==="new"||x.value==="keep";x.hidden=q!==""&&!fix&&x.text.toLowerCase().indexOf(q)<0;if(!fix&&!x.hidden&&!first){first=x;}});if(q!==""&&first){s.value=first.value;}});});'
            . 'document.querySelectorAll(".taxmod-stock-thing-search").forEach(function(f){var t=document.getElementById(f.dataset.for),l=document.getElementById(f.getAttribute("list")),w;'
            . 'f.addEventListener("input",function(){var m=f.value.match(/^#(\\d+)/);if(m){t.value=m[1];return;}t.value="";clearTimeout(w);if(f.value.trim().length<2){return;}'
            . 'w=setTimeout(function(){fetch(url+"&term="+encodeURIComponent(f.value),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){l.innerHTML="";(j.data||[]).forEach(function(h){var x=document.createElement("option");x.value="#"+h.id+" · "+h.word+(h.node?" ("+h.node+")":"");l.appendChild(x);});});},250);});});'
            . '}bind(document);'
            . 'function load(box){if(box.dataset.loaded){return;}box.dataset.loaded="1";var cell=box;cell.textContent="…";'
            . 'fetch(pos+"&order="+encodeURIComponent(box.dataset.order),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){cell.innerHTML=(j.data&&j.data.html)||"";bind(cell);})'
            . '.catch(function(){delete box.dataset.loaded;cell.textContent=' . wp_json_encode(__('Positionen konnten nicht geladen werden.', 'taxmod-stock')) . ';});}'
            // An order's purchases load once its details row shows: opened by hand, by «Expand all», or open from the start.
            . 'var seen="IntersectionObserver" in window?new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){seen.unobserve(e.target);load(e.target);}});},{rootMargin:"200px"}):null;'
            . 'document.querySelectorAll(".taxmod-stock-positions:not([data-loaded])").forEach(function(box){if(seen){seen.observe(box);}else{load(box);}});'
            . '})();</script>';
    }

    /** @return list<InventoryRow> */
    private function inventoryRows(): array
    {
        $rows = [];

        foreach ((array) ($_POST['rows'] ?? []) as $row) {
            $row      = is_array($row) ? wp_unslash($row) : [];
            $material = absint($row['material'] ?? 0) ?: null;
            $name     = sanitize_text_field((string) ($row['name'] ?? ''));
            $quantity = sanitize_text_field((string) ($row['quantity'] ?? ''));

            $thing    = absint($row['thing'] ?? 0) ?: null;

            // An empty row is no row; a row with neither material, name nor Bezug cannot be booked and is left out the same way.
            if ($quantity === '' || ($material === null && $name === '' && $thing === null)) {
                continue;
            }

            $rows[] = new InventoryRow(
                $material,
                $name,
                $thing,
                $quantity,
                sanitize_text_field((string) ($row['value'] ?? '')),
                sanitize_text_field((string) ($row['note'] ?? ''))
            );
        }

        return $rows;
    }

    private function formStart(string $booking): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="booking" value="' . esc_attr($booking) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
    }

    /** @param array<int, string> $labels */
    private function options(array $labels, string $empty, int $selected = 0): string
    {
        $html = $empty === '' ? '' : '<option value="0">' . esc_html($empty) . '</option>';

        foreach ($labels as $id => $label) {
            $html .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $selected, false) . '>' . esc_html($label) . '</option>';
        }

        return $html;
    }

    private function tabBar(string $active, StockList $list): string
    {
        $materials = $this->stock->materialNames()->all();
        $ledger    = $this->stock->ledger();
        $s         = $this->stock->structure();
        $orderRows = $this->stock->table('order');

        return ListPage::tabs([
            ['wareneingang', __('Wareneingang', 'taxmod-stock'), count(array_filter(array_keys($this->stock->labels('order')), fn (int $id): bool => $this->orderState($orderRows->first($id, $s->field('order_status'))) !== 'received'))],
            ['bestand', __('Bestand', 'taxmod-stock'), count(array_filter(array_keys($materials), static fn (int $id): bool => $ledger->stockOf($id) > 0.0))],
            ['material', __('Material', 'taxmod-stock'), count($materials)],
            ['inventur', __('Inventur', 'taxmod-stock')],
            ['verbrauch', __('Verbrauch', 'taxmod-stock')],
        ], $active, fn (string $slug): string => $this->listUrl($slug, $slug === $active ? $list : new StockList(), []));
    }

    private function listRequest(): StockList
    {
        $orderBy = sanitize_key((string) ($_GET['orderby'] ?? ''));

        return new StockList(
            sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
            sanitize_key((string) ($_GET['filter'] ?? '')),
            in_array($orderBy, StockList::ORDER_BY, true) ? $orderBy : 'name',
            sanitize_key((string) ($_GET['order'] ?? '')) === 'desc'
        );
    }

    /**
     * The page address of a tab with the current search, filter and order, changed by `$change`.
     *
     * @param array<string, string> $change
     */
    private function listUrl(string $tab, StockList $list, array $change): string
    {
        $query = array_filter([
            'page'    => self::PAGE,
            'tab'     => $tab,
            's'       => trim($list->search),
            'filter'  => $list->filter,
            'orderby' => $list->orderBy === 'name' ? '' : $list->orderBy,
            'order'   => $list->descending ? 'desc' : '',
        ]);

        return admin_url('admin.php?' . http_build_query(array_filter(array_merge($query, $change)), '', '&'));
    }

    /**
     * Search box, one filter, bulk bar with «Expand all» and the count, all from the shared list page (D-989).
     *
     * @param array<string, string> $filters
     */
    private function tools(string $tab, StockList $list, string $filterName, string $current, array $filters, string $placeholder, string $count): string
    {
        $hidden = array_filter([
            'page'    => self::PAGE,
            'tab'     => $tab,
            'orderby' => $list->orderBy === 'name' ? '' : $list->orderBy,
            'order'   => $list->descending ? 'desc' : '',
        ]);

        return ListPage::searchBox(self::FILTER_FORM, $list->search, $placeholder, $hidden, __('Suchen', 'taxmod-stock'))
            . ListPage::bulkBar(self::BULK_FORM, self::ACTION, [], $count, ListPage::filters(self::FILTER_FORM, [[$filterName, $filters, $current, __('Filter', 'taxmod-stock')]]));
    }

    /** @return array<string, string> */
    private function materialFilters(): array
    {
        return [
            ''                  => __('Alle Materialien', 'taxmod-stock'),
            StockList::IN_STOCK => __('Nur mit Bestand', 'taxmod-stock'),
            StockList::LOW      => __('Unter Mindestbestand', 'taxmod-stock'),
        ];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ''|'asc'|'desc'} a sortable column for the shared list page */
    private function sortColumn(string $tab, StockList $list, string $key, string $label, string $width): array
    {
        $current = $list->orderBy === $key;
        $next    = $current && ! $list->descending ? 'desc' : 'asc';

        return [$label, $width, $this->listUrl($tab, $list, ['orderby' => $key === 'name' ? '' : $key, 'order' => $next === 'desc' ? 'desc' : '']),
            $current ? ($list->descending ? 'desc' : 'asc') : ''];
    }

    private function editButton(int $material): string
    {
        return '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'material' => $material], admin_url('admin.php')) . '#taxmod-stock-edit') . '">'
            . esc_html__('Bearbeiten', 'taxmod-stock') . '</a>';
    }

    /** @param int $columns the module's columns; the list page adds the checkbox and the actions column */
    private function noItems(int $columns): string
    {
        return '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) ($columns + 2)) . '">' . esc_html__('Nichts gefunden.', 'taxmod-stock') . '</td></tr>';
    }

    private function title(string $kind, string $today): string
    {
        $given = sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? '')));

        return $given !== '' ? $given : $kind . ' ' . $today;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    private function remember(string $type, string $text): void
    {
        set_transient(self::NOTICE . get_current_user_id(), ['type' => $type, 'text' => $text], 60);
    }

    private function notice(): string
    {
        $notice = get_transient(self::NOTICE . get_current_user_id());

        if (! is_array($notice)) {
            return '';
        }

        delete_transient(self::NOTICE . get_current_user_id());

        return '<div class="notice notice-' . esc_attr($notice['type'] === 'success' ? 'success' : 'error') . '"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }
}
