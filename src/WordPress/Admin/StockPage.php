<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\LagerInput;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\StockList;
use TaxmodStock\Core\Things;
use TaxmodStock\WordPress\StockService;

/**
 * The page «Lager» under the Taxonomy Modeller: goods receipt, materials, stock count, consumption (stage 1, D-958 F7).
 *
 * D-995: the thing in the tree is the material. D-997: «Material» lists the things the stock knows; a click opens the
 * whole thing — its head fixed, the views as tabs below (Technische Daten, Einkauf, Lager, Bestand, Verkauf).
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
    /** The notice after a booking; the goods receipt cockpit shows it too, when the booking came from there. */
    public const NOTICE     = 'taxmod_stock_notice';
    private const ROWS      = 6;
    private const TABS      = ['wareneingang', 'material', 'inventur', 'verbrauch'];
    private const VIEWS     = ['daten', 'einkauf', 'lager', 'bestand', 'verkauf'];
    private const FILTER_FORM = 'taxmod-stock-filter';
    private const BULK_FORM   = 'taxmod-stock-bulk';

    /** The thing's own fields that its head shows, or that say nothing in «Technische Daten». */
    private const HEAD_FIELDS = ['label', 'description', 'title_image'];

    /** The modeller's search over every record under `Model` (D-958 F3a), reused to pick a thing. */
    public const THING_SEARCH = 'taxmod_stock_thing_search';
    public const THING_NODES  = 'taxmod_stock_thing_nodes';
    public const THING_BRANCH = 'taxmod_stock_thing_branch';

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
            $thing = absint($_GET['thing'] ?? 0);
            $html .= $this->tabBar($tab, $list) . match ($tab) {
                'material'  => $thing > 0 ? $this->thingView($thing) : $this->materialList($list),
                'inventur'  => $this->inventoryForm(),
                'verbrauch' => $this->consumptionForm($thing, absint($_GET['lot'] ?? 0)),
                default     => absint($_GET['split'] ?? 0) > 0
                    ? $this->splitForm(absint($_GET['order'] ?? 0), absint($_GET['split']), sanitize_key((string) ($_GET['back'] ?? '')))
                    : $this->receiptTab($list, absint($_GET['order'] ?? 0)),
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
        // The goods receipt sends «thing» as a list (one per purchase); only the other forms send one thing.
        $thing = is_array($_POST['thing'] ?? null) ? 0 : absint($_POST['thing'] ?? 0);
        $back  = ['material', []];
        // D-1001: a receipt booked from the goods receipt cockpit goes back there.
        $fromCockpit = in_array($kind, ['wareneingang', 'aufteilen', 'zuordnen'], true) && sanitize_key((string) ($_POST['back'] ?? '')) === InboundPage::BACK;

        try {
            $result = match ($kind) {
                'inventur'     => $this->stock->bookInventory($this->inventoryRows(), $today, $this->title(__('Inventur', 'taxmod-stock'), $today)),
                'lager'        => $this->stock->saveLager($thing, $this->lagerInput()),
                'umbuchen'     => $this->stock->moveLot(absint($_POST['lot'] ?? 0), absint($_POST['location'] ?? 0)),
                'wareneingang' => $this->stock->bookReceipt(
                    absint($_POST['order'] ?? 0),
                    $this->receiptChoices(),
                    $today,
                    $this->title(__('Wareneingang', 'taxmod-stock'), $today)
                ),
                'aufteilen'    => $this->stock->splitPurchase(absint($_POST['order'] ?? 0), absint($_POST['purchase'] ?? 0), $this->splitRows()),
                'zuordnen'     => $this->stock->assignPurchases(absint($_POST['order'] ?? 0), $this->assignedThings()),
                'verbrauch'    => $this->stock->bookConsumption(
                    $thing,
                    absint($_POST['lot'] ?? 0) ?: null,
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    absint($_POST['project'] ?? 0) ?: null,
                    $today,
                    $this->title(__('Verbrauch', 'taxmod-stock'), $today)
                ),
                default        => throw StockError::nothingToBook(),
            };
            $this->remember('success', match ($kind) {
                'aufteilen' => __('Aufgeteilt: jede Zeile ist jetzt ein Kauf mit seinem Material und kann eingebucht werden.', 'taxmod-stock'),
                'zuordnen'  => sprintf(
                    /* translators: %d: number of purchases whose material was kept */
                    _n('Zuordnung gespeichert: %d Kauf, noch nichts eingebucht.', 'Zuordnung gespeichert: %d Käufe, noch nichts eingebucht.', count((array) ($result['applied'] ?? [])), 'taxmod-stock'),
                    count((array) ($result['applied'] ?? []))
                ),
                /* translators: %d: number of changes the modeller applied */
                default     => sprintf(__('Gebucht: %d Änderungen in einem Schritt.', 'taxmod-stock'), count((array) ($result['applied'] ?? []))),
            });

            $back = match ($kind) {
                'lager'        => ['material', ['thing' => $thing, 'view' => 'lager']],
                'umbuchen'     => ['material', ['thing' => $thing, 'view' => 'bestand']],
                'wareneingang', 'aufteilen', 'zuordnen' => ['wareneingang', ['order' => absint($_POST['order'] ?? 0)]],
                default        => ['material', $thing > 0 ? ['thing' => $thing, 'view' => 'bestand'] : []],
            };
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
            $back = match ($kind) {
                'wareneingang', 'zuordnen' => ['wareneingang', ['order' => absint($_POST['order'] ?? 0)]],
                'aufteilen'         => ['wareneingang', ['order' => absint($_POST['order'] ?? 0), 'split' => absint($_POST['purchase'] ?? 0)]],
                'lager', 'umbuchen' => ['material', ['thing' => $thing, 'view' => $kind === 'lager' ? 'lager' : 'bestand']],
                'verbrauch'         => ['verbrauch', ['thing' => $thing]],
                default             => [$kind, []],
            };
        }

        if ($fromCockpit) {
            wp_safe_redirect(InboundPage::url(['tab' => 'arrived', 'order' => absint($_POST['order'] ?? 0)]));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => $back[0]] + $back[1], admin_url('admin.php')));
        exit;
    }

    /**
     * D-997: the things the stock knows as one list; a group chosen adds the columns of its technical data, and a
     * row's details are its lots.
     */
    private function materialList(StockList $list): string
    {
        $s      = $this->stock->structure();
        $things = $this->stock->things();
        $all    = $things->all();
        $ledger = $this->stock->ledger();
        $lager  = $this->stock->table('lager');
        $group  = absint($_GET['group'] ?? 0);

        if ($all === []) {
            return '<p>' . esc_html__('Noch kein Material. Eine Inventur oder ein Wareneingang bringt das erste.', 'taxmod-stock') . '</p>';
        }

        $groups = [];
        $rows   = [];
        $mins   = [];

        foreach (array_keys($all) as $id) {
            $node = $things->nodeOf($id);

            if ($node !== null) {
                $groups[$node] ??= $this->stock->path($node);
            }

            if ($group > 0 && $node !== $group) {
                continue;
            }

            $record    = $things->lagerOf($id);
            $min       = $record === null ? null : $lager->first($record, $s->field('lager_min'));
            $stock     = $ledger->stockOf($id);
            $mins[$id] = $min;
            $rows[]    = ['id' => $id, 'name' => $things->word($id), 'stock' => $stock, 'low' => $min !== null && $stock < (float) $min,
                'words' => [$node === null ? '' : $groups[$node]]];
        }

        asort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        $shown   = $list->apply($rows);
        $columns = $group > 0 ? $this->technicalColumns($group) : [];
        $data    = $group > 0 ? $this->stock->tableOf($group) : null;
        $open    = $this->stock->viewSettings()->expanded('material');
        $html    = $this->tools('material', $list, [
            ['filter', $this->materialFilters(), $list->filter, __('Filter', 'taxmod-stock')],
            ['group', ['' => __('Alle Gruppen', 'taxmod-stock')] + $groups, $group > 0 ? (string) $group : '', __('Gruppe', 'taxmod-stock')],
        ], ['group' => $group > 0 ? (string) $group : ''], __('Material, Nummer, Gruppe …', 'taxmod-stock'), sprintf(
            /* translators: %d: number of materials */
            _n('%d Material', '%d Materialien', count($shown), 'taxmod-stock'),
            count($shown)
        ));
        $body    = $shown === [] ? $this->noItems(5 + count($columns)) : '';

        foreach ($shown as $row) {
            $id    = $row['id'];
            $cells = [
                esc_html((string) $id),
                '<strong><a href="' . esc_url($this->thingUrl($id, 'daten')) . '">' . esc_html($row['name'] === '' ? '—' : $row['name']) . '</a></strong>',
                esc_html($row['words'][0] === '' ? '—' : $row['words'][0]),
            ];

            foreach (array_keys($columns) as $relation) {
                $cells[] = esc_html(implode(', ', (array) $data?->all($id, $relation)));
            }

            $cells[] = '<span' . ($row['low'] ? ' style="color:#b32d2e;font-weight:600"' : '') . '>' . esc_html($this->number($row['stock'])) . '</span>';
            $cells[] = esc_html((string) ($mins[$id] ?? '—'));
            $body   .= ListPage::row(self::BULK_FORM, (string) $id, $cells, '<a class="button button-small" href="' . esc_url($this->thingUrl($id, 'daten')) . '">'
                . esc_html__('Öffnen', 'taxmod-stock') . '</a>', $this->lotList($id, $ledger), $open);
        }

        $head = [[__('Nr.', 'taxmod-stock'), '6em'], $this->sortColumn('material', $list, 'name', __('Material', 'taxmod-stock'), ''), [__('Gruppe', 'taxmod-stock'), '']];

        foreach ($columns as $name) {
            $head[] = [$name, ''];
        }

        return $html . ListPage::table([...$head, $this->sortColumn('material', $list, 'stock', __('Bestand', 'taxmod-stock'), '7em'), [__('Mindestbestand', 'taxmod-stock'), '9em']], $body);
    }

    /**
     * The fields of a group that make sense as list columns: plain values, not the head's, at most four.
     *
     * @return array<int, string> relation id → name
     */
    private function technicalColumns(int $group): array
    {
        $out = [];

        foreach ($this->technicalFields($group) as $relation => $field) {
            if (in_array($field['type'], ['text', 'int', 'decimal'], true)) {
                $out[$relation] = $field['name'];
            }
        }

        return array_slice($out, 0, 4, true);
    }

    /**
     * The fields of a thing's node that belong to «Technische Daten»: all but the head's and the tree's own settings.
     *
     * @return array<int, array{name: string, target: int, type: string|null}>
     */
    private function technicalFields(int $node): array
    {
        $s    = $this->stock->structure();
        $skip = [];

        foreach (self::HEAD_FIELDS as $key) {
            if ($s->hasField($key)) {
                $skip[$s->field($key)] = true;
            }
        }

        return array_filter(
            $this->stock->fieldsOf($node),
            static fn (array $field, int $relation): bool => ! isset($skip[$relation]) && $field['name'] !== 'position',
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** The lots of a thing, one line each: date, rest, price, origin, place. */
    private function lotList(int $thing, \TaxmodStock\Core\Ledger $ledger): string
    {
        $s      = $this->stock->structure();
        $lots   = $this->stock->table('lot');
        $money  = $this->stock->table('money');
        $places = $this->stock->labels('location');
        $html   = '';

        foreach ($ledger->lotsOf($thing) as $lot) {
            $value    = $lots->first($lot, $s->field('lot_value'));
            $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
            $purchase = $lots->first($lot, $s->field('lot_purchase'));
            $place    = $lots->first($lot, $s->field('lot_location'));

            $html .= '<li>' . esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10))
                . ' · ' . esc_html__('Rest', 'taxmod-stock') . ' ' . esc_html($this->number($ledger->restOf($lot)))
                . ($amount === null ? '' : ' · ' . esc_html($amount) . ' ' . esc_html__('je Stück', 'taxmod-stock'))
                . ($purchase === null ? ' · ' . esc_html__('Inventur', 'taxmod-stock') : ' · ' . esc_html__('Kauf', 'taxmod-stock') . ' #' . esc_html($purchase))
                . ($place === null ? '' : ' · ' . esc_html($places[(int) $place] ?? '#' . $place)) . '</li>';
        }

        return $html === '' ? '—' : '<ul style="margin:0">' . $html . '</ul>';
    }

    /** D-997: one thing opened whole — the head fixed, the views as tabs below. */
    private function thingView(int $thing): string
    {
        $s     = $this->stock->structure();
        $nodes = $this->stock->nodesOf([$thing]);
        $node  = $nodes[$thing] ?? null;
        $tree  = $this->stock->tree();

        if ($node === null || ! isset($tree[$node])) {
            throw StockError::notAThing($thing);
        }

        $view   = sanitize_key((string) ($_GET['view'] ?? 'daten'));
        $view   = in_array($view, self::VIEWS, true) ? $view : 'daten';
        $table  = $this->stock->tableOf($node);
        $label  = (string) $table->first($thing, $s->field('label'));
        $about  = $s->hasField('description') ? (string) $table->first($thing, $s->field('description')) : '';
        $image  = $s->hasField('title_image') ? (string) $table->first($thing, $s->field('title_image')) : '';
        $media  = str_starts_with($image, 'media:') ? absint(substr($image, 6)) : 0;
        $back   = add_query_arg(['page' => self::PAGE, 'tab' => 'material'], admin_url('admin.php'));
        $titles = [
            'daten'   => __('Technische Daten', 'taxmod-stock'),
            'einkauf' => __('Einkauf', 'taxmod-stock'),
            'lager'   => __('Lager', 'taxmod-stock'),
            'bestand' => __('Bestand', 'taxmod-stock'),
            'verkauf' => __('Verkauf', 'taxmod-stock'),
        ];

        $html = '<p><a href="' . esc_url($back) . '">← ' . esc_html__('Zur Liste', 'taxmod-stock') . '</a></p>'
            . '<div class="taxmod-stock-head" style="display:flex;gap:16px;align-items:flex-start;background:#fff;border:1px solid #c3c4c7;padding:16px;margin:8px 0 0">'
            . ($media > 0 ? (string) wp_get_attachment_image($media, [96, 96], false, ['style' => 'width:96px;height:96px;object-fit:cover;border-radius:4px']) : '<div style="width:96px;height:96px;background:#f0f0f1;border-radius:4px"></div>')
            . '<div><p style="margin:0;color:#50575e">' . esc_html(sprintf(/* translators: %d: record id of the thing */ __('Nr. %d', 'taxmod-stock'), $thing)) . ' · ' . esc_html($this->stock->path($node)) . '</p>'
            . '<h2 style="margin:4px 0;font-size:1.6em">' . esc_html($label === '' ? '—' : $label) . '</h2>'
            . ($about === '' ? '' : '<p style="margin:0">' . esc_html($about) . '</p>') . '</div></div>'
            . '<nav class="nav-tab-wrapper" style="margin-top:0">';

        foreach ($titles as $slug => $title) {
            $html .= '<a href="' . esc_url($this->thingUrl($thing, $slug)) . '" class="nav-tab' . ($slug === $view ? ' nav-tab-active' : '') . '">' . esc_html($title) . '</a>';
        }

        return $html . '</nav><div style="background:#fff;border:1px solid #c3c4c7;border-top:0;padding:16px">' . match ($view) {
            'einkauf' => $this->purchaseView($thing),
            'lager'   => $this->lagerView($thing),
            'bestand' => $this->stockView($thing),
            'verkauf' => '<p>' . esc_html__('Die Verkaufs-Sicht (VK-Preis, Angebote) kommt später.', 'taxmod-stock') . '</p>',
            default   => $this->dataView($thing, $node),
        } . '</div>';
    }

    /** Every field of the thing's node but the head's; references show the name of what they point at. */
    private function dataView(int $thing, int $node): string
    {
        $table  = $this->stock->tableOf($node);
        $fields = $this->technicalFields($node);
        $refs   = [];

        foreach ($fields as $relation => $field) {
            if (! in_array($field['type'], ['text', 'int', 'decimal', 'bool', 'date', 'datetime', 'media'], true)) {
                foreach ($table->all($thing, $relation) as $value) {
                    $refs[] = (int) $value;
                }
            }
        }

        $labels = $this->stock->recordLabels(array_values(array_unique(array_filter($refs))));
        $html   = '<table class="form-table" role="presentation"><tbody>';

        foreach ($fields as $relation => $field) {
            $values = $table->all($thing, $relation);
            $shown  = array_map(static fn (string $v): string => isset($labels[(int) $v]) && $labels[(int) $v] !== '' ? $labels[(int) $v] : $v, $values);
            $html  .= '<tr><th scope="row">' . esc_html($field['name']) . '</th><td>' . esc_html($shown === [] ? '—' : implode(', ', $shown)) . '</td></tr>';
        }

        return $html . '</tbody></table><p class="description">' . esc_html__('Bearbeitet wird das Material im Taxonomy Modeller; hier steht es zum Nachsehen.', 'taxmod-stock') . '</p>';
    }

    /** The purchases whose «Modell» is this thing. ⚠️ «Preisangaben» are not listed yet: no ability finds what points here. */
    private function purchaseView(int $thing): string
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $about     = [];

        foreach ($purchases->ids() as $id) {
            if ((int) $purchases->first($id, $s->field('purchase_model')) === $thing) {
                $about[] = $id;
            }
        }

        if ($about === []) {
            return '<p>' . esc_html__('Noch kein Kauf zeigt auf dieses Material.', 'taxmod-stock') . '</p>';
        }

        $money = $this->stock->table('money');
        $html  = '<table class="widefat striped"><thead><tr><th>' . esc_html__('Kauf', 'taxmod-stock') . '</th><th>' . esc_html__('Menge', 'taxmod-stock') . '</th><th>'
            . esc_html__('Preis je Stück', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ($about as $id) {
            $part   = $purchases->first($id, $s->field('purchase_landed_price')) ?? $purchases->first($id, $s->field('purchase_unit_price'));
            $amount = $part === null ? null : $money->first((int) $part, $s->field('money_amount'));
            $html  .= '<tr><td>' . esc_html((string) $purchases->first($id, $s->field('label'))) . ' <span class="description">#' . esc_html((string) $id) . '</span></td>'
                . '<td>' . esc_html((string) ($purchases->first($id, $s->field('purchase_quantity')) ?? '—')) . '</td>'
                . '<td>' . esc_html($amount ?? '—') . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** D-997, tab «Lager»: Standard-Lagerort, Mindestbestand, Einzelstück (and «Verwendung» where it is set up). */
    private function lagerView(int $thing): string
    {
        $s      = $this->stock->structure();
        $record = $this->stock->things()->lagerOf($thing);
        $lager  = $this->stock->table('lager');
        $value  = static fn (string $key): string => $record === null || ! $s->hasField($key) ? '' : (string) $lager->first($record, $s->field($key));
        $html   = $this->formStart('lager') . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . '<table class="form-table" role="presentation"><tbody>';

        if ($s->hasField('lager_location')) {
            $html .= '<tr><th>' . esc_html__('Standard-Lagerort', 'taxmod-stock') . '</th><td><select name="location">' . $this->options($this->stock->labels('location'), '—', (int) $value('lager_location')) . '</select></td></tr>';
        }

        $html .= '<tr><th>' . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><td><input type="number" min="0" name="minimum" style="width:8em" value="' . esc_attr($value('lager_min')) . '"></td></tr>'
            . '<tr><th>' . esc_html__('Einzelstück', 'taxmod-stock') . '</th><td><input type="checkbox" name="single" value="1"' . checked($value('lager_single'), '1', false) . '></td></tr>';

        if ($s->hasField('lager_use') && $this->stock->uses() !== []) {
            $html .= '<tr><th>' . esc_html__('Verwendung', 'taxmod-stock') . '</th><td><select name="use">' . $this->options($this->stock->uses(), '—', (int) $value('lager_use')) . '</select></td></tr>';
        }

        return $html . '</tbody></table>' . get_submit_button(__('Speichern', 'taxmod-stock')) . '</form>';
    }

    /**
     * D-997, tab «Bestand»: quantity, value and minimum on top, the lots below with their movements, «Umbuchen» and «Ausbuchen».
     */
    private function stockView(int $thing): string
    {
        $s       = $this->stock->structure();
        $ledger  = $this->stock->ledger();
        $lots    = $this->stock->table('lot');
        $money   = $this->stock->table('money');
        $places  = $this->stock->labels('location');
        $record  = $this->stock->things()->lagerOf($thing);
        $min     = $record === null ? null : $this->stock->table('lager')->first($record, $s->field('lager_min'));
        $stock   = $ledger->stockOf($thing);
        $moves   = $this->movements($ledger->lotsOf($thing));
        $worth   = 0.0;
        $rows    = '';

        foreach ($ledger->lotsOf($thing) as $lot) {
            $value    = $lots->first($lot, $s->field('lot_value'));
            $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
            $purchase = $lots->first($lot, $s->field('lot_purchase'));
            $place    = (int) $lots->first($lot, $s->field('lot_location'));
            $rest     = $ledger->restOf($lot);
            $worth   += $amount === null ? 0.0 : $rest * (float) $amount;

            $rows .= '<tr><td>#' . esc_html((string) $lot) . '</td><td>' . esc_html($places[$place] ?? '—') . '</td><td>' . esc_html($this->number($rest)) . '</td>'
                . '<td>' . esc_html($amount ?? '—') . '</td>'
                . '<td>' . esc_html($purchase === null ? __('Inventur', 'taxmod-stock') : '#' . $purchase) . '</td>'
                . '<td>' . esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10)) . '</td>'
                . '<td style="white-space:nowrap">' . $this->formStart('umbuchen') . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '"><input type="hidden" name="lot" value="' . esc_attr((string) $lot) . '">'
                . '<select name="location">' . $this->options($places, '', $place) . '</select> ' . get_submit_button(__('Umbuchen', 'taxmod-stock'), 'small', '', false) . '</form> '
                . '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'verbrauch', 'thing' => $thing, 'lot' => $lot], admin_url('admin.php'))) . '">' . esc_html__('Ausbuchen', 'taxmod-stock') . '</a></td></tr>'
                . '<tr><td></td><td colspan="6"><details><summary>' . esc_html__('Vorgänge', 'taxmod-stock') . '</summary>' . ($moves[$lot] ?? '—') . '</details></td></tr>';
        }

        $tile = static fn (string $label, string $value, string $tone): string => '<div style="flex:1;background:' . $tone . ';border-radius:6px;padding:10px 14px"><div style="color:#50575e">' . esc_html($label) . '</div><div style="font-size:1.6em;font-weight:600">' . esc_html($value) . '</div></div>';

        return '<div style="display:flex;gap:12px;margin-bottom:16px">'
            . $tile(__('Menge', 'taxmod-stock'), $this->number($stock), '#edfaef')
            . $tile(__('Wert', 'taxmod-stock'), $this->number($worth), '#edfaef')
            . $tile(__('Mindestbestand', 'taxmod-stock'), $min === null ? '—' : $min . ' ' . ($stock < (float) $min ? '✗' : '✓'), '#f6f7f7')
            . '</div>'
            . ($rows === '' ? '<p>' . esc_html__('Kein Bestandsposten.', 'taxmod-stock') . '</p>' : '<table class="widefat striped"><thead><tr><th>' . esc_html__('Posten', 'taxmod-stock') . '</th><th>' . esc_html__('Ort', 'taxmod-stock') . '</th><th>'
            . esc_html__('Menge', 'taxmod-stock') . '</th><th>' . esc_html__('Wert je Stück', 'taxmod-stock') . '</th><th>' . esc_html__('Kauf', 'taxmod-stock') . '</th><th>' . esc_html__('Eingang', 'taxmod-stock') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>');
    }

    /**
     * The movements per lot: date, kind and quantity of each line, read from the Vorgänge in one go (CD-7).
     *
     * @param list<int> $lots
     * @return array<int, string> lot → its movements as a list
     */
    private function movements(array $lots): array
    {
        $s       = $this->stock->structure();
        $lines   = $this->stock->table('line');
        $deals   = $this->stock->table('transaction');
        $kinds   = [
            $s->value('kind_receipt')     => __('Wareneingang', 'taxmod-stock'),
            $s->value('kind_consumption') => __('Verbrauch', 'taxmod-stock'),
            $s->value('kind_count')       => __('Inventur', 'taxmod-stock'),
        ];
        $wanted  = array_flip($lots);
        $dealOf  = [];
        $out     = [];

        foreach ($deals->ids() as $deal) {
            foreach ($deals->all($deal, $s->field('transaction_lines')) as $line) {
                $dealOf[(int) $line] = $deal;
            }
        }

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $s->field('line_lot'));

            if (! isset($wanted[$lot])) {
                continue;
            }

            $deal       = $dealOf[$line] ?? null;
            $out[$lot]  = ($out[$lot] ?? '') . '<li>' . esc_html($deal === null ? '—' : substr((string) $deals->first($deal, $s->field('transaction_date')), 0, 10))
                . ' · ' . esc_html($deal === null ? '' : ($kinds[(int) $deals->first($deal, $s->field('transaction_kind'))] ?? ''))
                . ' · ' . esc_html((string) $lines->first($line, $s->field('line_quantity'))) . '</li>';
        }

        return array_map(static fn (string $html): string => '<ul style="margin:4px 0 0">' . $html . '</ul>', $out);
    }

    public static function thingUrl(int $thing, string $view): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'thing' => $thing, 'view' => $view], admin_url('admin.php'));
    }

    /**
     * Concept R3: a lot purchase («noch nicht näher bestimmt») told apart. The first row rewrites the purchase, every
     * further row becomes a purchase of the same order; nothing is in stock until the receipt books them.
     */
    private function splitForm(int $order, int $purchase, string $back): string
    {
        $labels = $this->stock->labels('purchase');
        $about  = $this->purchaseAbout([$purchase]);
        $groups = $this->groupOptions();
        $html   = $this->formStart('aufteilen')
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . ($back === InboundPage::BACK ? '<input type="hidden" name="back" value="' . esc_attr($back) . '">' : '')
            . '<h2>' . esc_html(sprintf(
                /* translators: %s: the lot purchase */
                __('Konvolut aufteilen: %s', 'taxmod-stock'),
                $labels[$purchase] ?? '#' . $purchase
            )) . '</h2>'
            . (($about[$purchase] ?? '') === '' ? '' : '<p class="description">' . esc_html($about[$purchase]) . '</p>')
            . '<p>' . esc_html__('Je Teil eine Zeile: Material suchen oder neu in einer Materialgruppe anlegen, Anzahl, wahlweise Preis je Stück. Die erste Zeile übernimmt den Konvolut-Kauf (ohne Preise bleibt der ganze Preis dort), jede weitere wird ein eigener Kauf der Bestellung. Eingelagert wird erst mit dem Wareneingang.', 'taxmod-stock') . '</p>'
            . '<table class="wp-list-table widefat striped table-view-list"><thead><tr><th scope="col" class="manage-column">' . esc_html__('Material', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('oder neu: Gruppe', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column">' . esc_html__('Bezeichnung', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Anzahl', 'taxmod-stock') . '</th><th scope="col" class="manage-column">'
            . esc_html__('Preis je Stück', 'taxmod-stock') . '</th></tr></thead><tbody>';

        for ($i = 0; $i < self::ROWS; $i++) {
            $html .= '<tr><td>' . $this->thingField('rows[' . $i . '][thing]', 'taxmod-stock-split-' . $i, null) . '</td>'
                . '<td><select name="rows[' . $i . '][group]" style="max-width:18em">' . $groups . '</select></td>'
                . '<td><input type="text" name="rows[' . $i . '][name]"></td>'
                . '<td><input type="number" min="1" name="rows[' . $i . '][quantity]" style="width:5em"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][price]" style="width:6em"></td></tr>';
        }

        $cancel = $back === InboundPage::BACK
            ? InboundPage::url(['order' => $order])
            : add_query_arg(['page' => self::PAGE, 'tab' => 'wareneingang', 'order' => $order], admin_url('admin.php'));

        return $html . '</tbody></table><p class="submit">' . get_submit_button(__('Aufteilen', 'taxmod-stock'), 'primary', 'submit', false)
            . ' <a class="button" href="' . esc_url($cancel) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a></p></form>';
    }

    /** @return list<SplitRow> the filled rows; a row without a material and without a name is no row */
    private function splitRows(): array
    {
        $rows = [];

        foreach ((array) ($_POST['rows'] ?? []) as $row) {
            $row   = is_array($row) ? wp_unslash($row) : [];
            $thing = absint($row['thing'] ?? 0) ?: null;
            $name  = sanitize_text_field((string) ($row['name'] ?? ''));

            if ($thing === null && $name === '') {
                continue;
            }

            if ($name === '' && $thing !== null) {
                $name = $this->stock->recordLabels([$thing])[$thing] ?? '#' . $thing;
            }

            $rows[] = new SplitRow(
                $thing,
                $thing === null ? new NewThing(absint($row['group'] ?? 0), $name) : null,
                $name,
                sanitize_text_field((string) ($row['quantity'] ?? '')) ?: '1',
                sanitize_text_field((string) ($row['price'] ?? '')) ?: null
            );
        }

        return $rows;
    }

    private function inventoryForm(): string
    {
        $groups = $this->groupOptions();
        $html   = $this->formStart('inventur')
            . '<p>' . esc_html__('Gezählter Bestand ohne Kauf. Jede Zeile wird ein Bestandsposten. Das Material steht im Baum: eines suchen, oder ein neues in einer Materialgruppe anlegen.', 'taxmod-stock') . '</p>'
            . '<table class="wp-list-table widefat striped table-view-list"><thead><tr><th scope="col" class="manage-column">' . esc_html__('Material', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('oder neu: Gruppe', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column">' . esc_html__('und Bezeichnung', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Menge', 'taxmod-stock') . '</th><th scope="col" class="manage-column">'
            . esc_html__('Wert je Stück', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Hinweis', 'taxmod-stock') . '</th></tr></thead><tbody>';

        for ($i = 0; $i < self::ROWS; $i++) {
            $html .= '<tr><td>' . $this->thingField('rows[' . $i . '][thing]', 'taxmod-stock-thing-' . $i, null) . '</td>'
                . '<td><select name="rows[' . $i . '][group]" style="max-width:18em">' . $groups . '</select></td>'
                . '<td><input type="text" name="rows[' . $i . '][name]"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][quantity]" style="width:6em"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][value]" style="width:6em"></td>'
                . '<td><input type="text" name="rows[' . $i . '][note]"></td></tr>';
        }

        return $html . '</tbody></table>' . get_submit_button(__('Inventur buchen', 'taxmod-stock')) . '</form>';
    }

    /** The groups a new thing can go into, by their path in the tree; the empty choice first. */
    private function groupOptions(): string
    {
        $paths = [];

        foreach ($this->stock->tree() as $node => $row) {
            if ($row['class'] === 'Category') {
                $paths[$node] = $this->stock->path($node);
            }
        }

        asort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->options(array_filter($paths), '—');
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
        $html  = $this->tools('wareneingang', $list, [['status', [
            'open'     => __('Nicht eingegangen', 'taxmod-stock'),
            'partial'  => __('Teilweise eingegangen', 'taxmod-stock'),
            'received' => __('Eingegangen', 'taxmod-stock'),
            ''         => __('Alle Bestellungen', 'taxmod-stock'),
        ], $status, __('Filter', 'taxmod-stock')]], [], __('Bestellung, Nummer …', 'taxmod-stock'), sprintf(
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

    /** admin-post handler for the material search of {@see self::recordPicker()}: capability → nonce → validate → act. */
    public function thingSearch(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        check_ajax_referer(self::THING_SEARCH);

        try {
            wp_send_json_success($this->stock->searchThings(sanitize_text_field(wp_unslash((string) ($_GET['term'] ?? '')))));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** admin-post handler for the groups of the tree beside the material search (D-1011): capability → nonce → act. */
    public function thingNodes(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        check_ajax_referer(self::THING_NODES);

        try {
            wp_send_json_success($this->stock->treeRows());
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** admin-post handler for the things of one group in that tree: capability → nonce → validate → act. */
    public function thingBranch(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        check_ajax_referer(self::THING_BRANCH);

        try {
            wp_send_json_success($this->stock->branchThings(absint($_GET['node'] ?? 0)));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** admin-post handler for one order's purchases, fetched when he opens the order: capability → nonce → validate → act. */
    public function positions(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['html' => esc_html__('Keine Berechtigung.', 'taxmod-stock')], 403);
        }

        check_ajax_referer(self::POSITIONS);
        $order = absint($_GET['order'] ?? 0);
        $back  = sanitize_key((string) ($_GET['back'] ?? ''));

        try {
            wp_send_json_success(['html' => $this->receiptPositions($order, $back === InboundPage::BACK ? $back : '')]);
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

    /**
     * The purchases of one order with their thing and place, as one booking form (D-995).
     *
     * @param string $back where the booking returns to: '' the Lager page, {@see InboundPage::BACK} the cockpit (D-1001)
     */
    private function receiptPositions(int $order, string $back = ''): string
    {
        $orders = $this->stock->labels('order');

        if (! isset($orders[$order])) {
            throw StockError::nothingToBook();
        }

        $plan   = $this->stock->receiptPlan($order);
        $labels = $this->stock->labels('purchase');
        $about  = $this->purchaseAbout(array_keys($plan));
        $models = $this->stock->recordLabels(array_values(array_filter(array_column($plan, 'model'))));
        $places = $this->stock->labels('location');
        $where  = $this->stock->receiptLocations($plan);
        $html  = $this->formStart('wareneingang') . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . ($back === '' ? '' : '<input type="hidden" name="back" value="' . esc_attr($back) . '">')
            . '<p>' . esc_html__('Je Kauf das Material: vorgeschlagen ist das «Modell» des Kaufs; der Mülleimer gibt die Suche frei. Der Lagerort ist vorgeschlagen: der Standard-Lagerort des Materials, sonst der Ort seiner Gruppe.', 'taxmod-stock') . '</p>'
            . '<div class="tablenav top"><div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html(sprintf(
                /* translators: %d: number of purchases */
                _n('%d Kauf', '%d Käufe', count($plan), 'taxmod-stock'),
                count($plan)
            )) . '</span></div><br class="clear"></div>';
        $head  = '<tr><td class="manage-column column-cb check-column"><input type="checkbox" aria-label="' . esc_attr__('Alle wählen', 'taxmod-stock') . '"></td>'
            . '<th scope="col" class="manage-column">' . esc_html__('Kauf', 'taxmod-stock') . '</th><th scope="col" class="manage-column" style="width:40%">' . esc_html__('Material', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column">' . esc_html__('Lagerort', 'taxmod-stock') . '</th></tr>';
        $html .= '<table class="wp-list-table widefat fixed striped table-view-list"><thead>' . $head . '</thead><tbody>' . ($plan === [] ? $this->noItems(3) : '');

        foreach ($plan as $purchase => $row) {
            $lock     = $row['lock'];
            $model    = $row['model'];
            $select   = 'taxmod-stock-choice-' . $purchase;
            $html    .= '<tr><th scope="row" class="check-column"><input type="checkbox" name="purchases[]" value="' . esc_attr((string) $purchase) . '"'
                . ($lock === null && $model !== null ? ' checked' : '') . ($lock === null ? '' : ' disabled') . '></th>'
                . '<td>' . esc_html($labels[$purchase] ?? '#' . $purchase) . ($lock === null ? '' : ' <em>(' . esc_html(Messages::lockReason($lock)) . ')</em>')
                . (($about[$purchase] ?? '') === '' ? '' : '<br><span class="description">' . esc_html($about[$purchase]) . '</span>') . '</td>';

            if ($lock !== null) {
                // Concept R3: a lot purchase is told apart first; its parts are then received like any purchase.
                $split = $lock === 'purchase_undetermined'
                    ? '<a class="button button-small" href="' . esc_url(add_query_arg(array_filter(['page' => self::PAGE, 'tab' => 'wareneingang', 'order' => $order, 'split' => $purchase, 'back' => $back]), admin_url('admin.php'))) . '">' . esc_html__('Aufteilen', 'taxmod-stock') . '</a>'
                    : '';
                $html .= '<td>' . esc_html($model === null ? '—' : '#' . $model . ' · ' . ($models[$model] ?? '')) . ' ' . $split . '</td><td></td></tr>';
                continue;
            }

            // The modeller's own record chooser on the purchase's «Modell» (his word 2026-10-28: «eigentlich haben wir doch den tax chooser»).
            $html .= '<td><input type="hidden" name="model[' . esc_attr((string) $purchase) . ']" value="' . esc_attr((string) ($model ?? '')) . '">'
                . $this->recordPicker('thing[' . $purchase . ']', $model, $model === null ? '' : ($models[$model] ?? '#' . $model)) . '</td>'
                . '<td><select name="location[' . esc_attr((string) $purchase) . ']">' . $this->options($places, '—', $where[$purchase] ?? 0) . '</select></td></tr>';
        }

        // His word 2026-10-28: «zwischenstände speichern … ohne we» — the second button keeps the chosen material, books nothing.
        return $html . '</tbody></table><p class="submit">' . get_submit_button(__('Wareneingang buchen', 'taxmod-stock'), 'primary', 'submit', false)
            . ' <button type="submit" class="button" name="booking" value="zuordnen">' . esc_html__('Zuordnung speichern', 'taxmod-stock') . '</button></p></form>';
    }

    private function consumptionForm(int $preset, int $presetLot): string
    {
        $ledger = $this->stock->ledger();
        $labels = $this->stock->things()->all();
        $lots   = $this->stock->table('lot');
        $date   = $this->stock->structure()->field('lot_date');
        $have   = array_intersect_key($labels, array_flip($ledger->things()));
        $choice = '<option value="0">' . esc_html__('automatisch: ältester Posten mit Rest', 'taxmod-stock') . '</option>';

        foreach ($have as $thing => $label) {
            foreach ($ledger->lotsOf($thing) as $lot) {
                $choice .= '<option value="' . esc_attr((string) $lot) . '"' . selected($lot, $presetLot, false) . '>' . esc_html($label . ' · ' . substr((string) $lots->first($lot, $date), 0, 10)
                    . ' · ' . __('Rest', 'taxmod-stock') . ' ' . $this->number($ledger->restOf($lot))) . '</option>';
            }
        }

        return $this->formStart('verbrauch')
            . '<p><label>' . esc_html__('Material', 'taxmod-stock') . ' <select name="thing" required>' . $this->options($have, '', $preset) . '</select></label></p>'
            . '<p><label>' . esc_html__('Menge', 'taxmod-stock') . ' <input type="text" inputmode="decimal" name="quantity" required style="width:6em"></label></p>'
            . '<p><label>' . esc_html__('Bestandsposten', 'taxmod-stock') . ' <select name="lot">' . $choice . '</select></label></p>'
            . '<p><label>' . esc_html__('Projekt (Satz-Id, optional)', 'taxmod-stock') . ' <input type="number" min="1" name="project" style="width:8em"></label></p>'
            . get_submit_button(__('Verbrauch buchen', 'taxmod-stock')) . '</form>';
    }

    /**
     * The ticked purchases with their thing — «keep» when the chooser still holds the Modell, else the record chosen — and their place.
     *
     * @return array<int, ReceiptChoice>
     */
    private function receiptChoices(): array
    {
        $things = (array) ($_POST['thing'] ?? []);
        $models = (array) ($_POST['model'] ?? []);
        $places = (array) ($_POST['location'] ?? []);
        $out    = [];

        $ticked = array_filter(array_map('absint', (array) ($_POST['purchases'] ?? [])));

        // A purchase whose thing was changed by hand is meant to go in, ticked or not (his report 2026-10-28: «nix passiert»).
        foreach ($things as $purchase => $thing) {
            if (absint($thing) > 0 && absint($thing) !== absint($models[$purchase] ?? 0)) {
                $ticked[] = absint($purchase);
            }
        }

        foreach (array_unique(array_filter($ticked)) as $purchase) {
            $thing = absint($things[$purchase] ?? 0);
            $model = absint($models[$purchase] ?? 0);

            $out[$purchase] = (match (true) {
                $thing === 0                        => throw StockError::purchaseWithoutThing($purchase),
                $model !== 0 && $thing === $model   => ReceiptChoice::keep(),
                default                             => ReceiptChoice::existing($thing),
            })->at(absint($places[$purchase] ?? 0) ?: null);
        }

        return $out;
    }

    /**
     * The material chosen per purchase in the goods receipt form, whatever is ticked — the interim state he keeps without a receipt.
     *
     * @return array<int, int> purchase id → thing
     */
    private function assignedThings(): array
    {
        $out = [];

        foreach ((array) ($_POST['thing'] ?? []) as $purchase => $thing) {
            if (absint($purchase) > 0 && absint($thing) > 0) {
                $out[absint($purchase)] = absint($thing);
            }
        }

        return $out;
    }

    private function lagerInput(): LagerInput
    {
        $minimum = sanitize_text_field(wp_unslash((string) ($_POST['minimum'] ?? '')));

        return new LagerInput(
            ! empty($_POST['single']),
            absint($_POST['use'] ?? 0) ?: null,
            absint($_POST['location'] ?? 0) ?: null,
            $minimum === '' ? null : absint($minimum)
        );
    }

    /**
     * The modeller's chooser for «every record under Model» (D-958 F3a, D-1011): the chosen word with a bin, or a search field and
     * a tree button. Hits and tree come from the material endpoints, kept to the groups of things. Same markup as the modeller's
     * summary field, so its script and stylesheet drive it.
     */
    private function recordPicker(string $name, ?int $value, string $word): string
    {
        $radio = static fn (string $choice, bool $on): string => '<label class="taxmod-record-choice"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($choice) . '"'
            . ($on ? ' checked' : '') . '></label>';
        $none  = $value === null;
        $clear = __('Entfernen', 'taxmod-stock');
        $tree  = __('Im Baum wählen', 'taxmod-stock');

        return '<span class="taxmod-record-pick taxmod-record-remote' . ($none ? '' : ' taxmod-record-taken') . '">'
            . '<span class="taxmod-record-choices" hidden>' . $radio('', $none) . ($none ? '' : $radio((string) $value, true)) . '</span>'
            . '<input type="search" class="taxmod-record-quick" autocomplete="off" placeholder="' . esc_attr__('Material suchen …', 'taxmod-stock') . '"' . ($none ? '' : ' hidden') . '>'
            . '<button type="button" class="button taxmod-icon-button taxmod-record-browse"' . ($none ? '' : ' hidden') . ' title="' . esc_attr($tree) . '">'
                . '<span class="dashicons dashicons-networking" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($tree) . '</span></button>'
            . '<span class="taxmod-record-dialog-open taxmod-record-word"' . ($none ? ' hidden' : '') . '>' . esc_html($word) . '</span>'
            . '<button type="button" class="button taxmod-icon-button taxmod-record-clear"' . ($none ? ' hidden' : '') . ' style="color:#b32d2e" title="' . esc_attr($clear) . '">'
                . '<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($clear) . '</span></button>'
            . '<span class="taxmod-record-hits" hidden></span>'
            . '</span>';
    }

    /**
     * The modeller's stylesheet and script for {@see self::recordPicker()}, and the one element its script reads the material
     * endpoints from: search, the tree's groups and a group's things.
     */
    private function modellerAssets(): string
    {
        if (class_exists(\Taxmod\WordPress\Plugin::class)) {
            $root = dirname((string) (new \ReflectionClass(\Taxmod\WordPress\Plugin::class))->getFileName(), 3);
            $base = WP_PLUGIN_DIR . '/' . basename($root) . '/x.php';
            wp_enqueue_style('taxmod-admin', plugins_url('assets/admin.css', $base), ['dashicons', 'buttons'], (string) \Taxmod\WordPress\Plugin::VERSION);
            wp_enqueue_script('taxmod-admin', plugins_url('assets/admin.js', $base), [], (string) \Taxmod\WordPress\Plugin::VERSION, true);
        }

        $address = static fn (string $action): string => esc_url(add_query_arg(['action' => $action, '_ajax_nonce' => wp_create_nonce($action)], admin_url('admin-post.php')));

        return '<span hidden id="taxmod-words" data-record-search="' . $address(self::THING_SEARCH) . '"'
            . ' data-record-nodes="' . $address(self::THING_NODES) . '" data-record-branch="' . $address(self::THING_BRANCH) . '"'
            . ' data-browse-title="' . esc_attr__('Material im Baum wählen', 'taxmod-stock') . '"'
            . ' data-no-hits="' . esc_attr__('Nichts gefunden.', 'taxmod-stock') . '"></span>';
    }

    /**
     * A thing as one search field that shows the record's name; its id travels in a hidden field beside it
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
            . esc_attr__('Material suchen …', 'taxmod-stock') . '" style="width:18em"><datalist id="' . esc_attr($id . '-hits') . '"></datalist>';
    }

    /**
     * Filters a choice list as one types, and asks the material search for a thing; loads an order's purchases
     * once its details show. Public because the goods receipt cockpit shows the same booking form (D-1001).
     */
    public function script(): string
    {
        $search    = add_query_arg(['action' => self::THING_SEARCH, '_ajax_nonce' => wp_create_nonce(self::THING_SEARCH)], admin_url('admin-post.php'));
        $positions = add_query_arg(['action' => self::POSITIONS, '_ajax_nonce' => wp_create_nonce(self::POSITIONS)], admin_url('admin-post.php'));

        return $this->modellerAssets() . '<script>(function(){'
            . 'var url=' . wp_json_encode($search) . ',pos=' . wp_json_encode($positions) . ';'
            . 'function bind(root){'
            . 'root.querySelectorAll(".taxmod-stock-filter").forEach(function(f){var s=document.getElementById(f.dataset.for);if(!s){return;}var o=Array.prototype.slice.call(s.options);'
            . 'f.addEventListener("input",function(){var q=f.value.toLowerCase(),first=null;o.forEach(function(x){var fix=x.value===""||x.value==="new"||x.value==="keep"||x.value==="search";x.hidden=q!==""&&!fix&&x.text.toLowerCase().indexOf(q)<0;if(!fix&&!x.hidden&&!first){first=x;}});if(q!==""&&first){s.value=first.value;}});});'
            . 'root.querySelectorAll(".taxmod-stock-thing-search").forEach(function(f){var t=document.getElementById(f.dataset.for),l=document.getElementById(f.getAttribute("list")),w;'
            . 'f.addEventListener("input",function(){var m=f.value.match(/^#(\\d+)/);if(m){t.value=m[1];return;}t.value="";clearTimeout(w);if(f.value.trim().length<2){return;}'
            . 'w=setTimeout(function(){fetch(url+"&term="+encodeURIComponent(f.value),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){l.innerHTML="";(j.data||[]).forEach(function(h){var x=document.createElement("option");x.value="#"+h.id+" · "+h.word+(h.node?" ("+h.node+")":"");l.appendChild(x);});});},250);});});'
            . 'root.querySelectorAll("tr").forEach(function(tr){var cb=tr.querySelector("input[name=\\"purchases[]\\"]");if(!cb||cb.disabled){return;}tr.querySelectorAll("select,input[type=text],input[type=search]").forEach(function(f){f.addEventListener("change",function(){cb.checked=true;});});});'
            . '}bind(document);'
            . 'function load(box){if(box.dataset.loaded){return;}box.dataset.loaded="1";var cell=box;cell.textContent="…";'
            . 'fetch(pos+"&order="+encodeURIComponent(box.dataset.order)+(box.dataset.back?"&back="+encodeURIComponent(box.dataset.back):""),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){cell.innerHTML=(j.data&&j.data.html)||"";bind(cell);})'
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
            $thing    = absint($row['thing'] ?? 0) ?: null;
            $group    = absint($row['group'] ?? 0);
            $name     = sanitize_text_field((string) ($row['name'] ?? ''));
            $quantity = sanitize_text_field((string) ($row['quantity'] ?? ''));

            // An empty row is no row; a row with neither a thing nor a name for a new one is left out the same way.
            if ($quantity === '' || ($thing === null && $name === '')) {
                continue;
            }

            $rows[] = new InventoryRow(
                $thing,
                $thing === null ? new NewThing($group, $name) : null,
                $name,
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
        $s         = $this->stock->structure();
        $orderRows = $this->stock->table('order');

        return ListPage::tabs([
            ['wareneingang', __('Wareneingang', 'taxmod-stock'), count(array_filter(array_keys($this->stock->labels('order')), fn (int $id): bool => $this->orderState($orderRows->first($id, $s->field('order_status'))) !== 'received'))],
            ['material', __('Material', 'taxmod-stock'), count($this->stock->things()->all())],
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
            'group'   => $tab === 'material' ? sanitize_key((string) ($_GET['group'] ?? '')) : '',
        ]);

        return admin_url('admin.php?' . http_build_query(array_filter(array_merge($query, $change)), '', '&'));
    }

    /**
     * Search box, the filters, bulk bar with «Expand all» and the count, all from the shared list page (D-989).
     *
     * @param list<array{0: string, 1: array<string, string>, 2: string, 3: string}> $filters
     * @param array<string, string>                                                $keep    query values the search keeps
     */
    private function tools(string $tab, StockList $list, array $filters, array $keep, string $placeholder, string $count): string
    {
        $hidden = array_filter([
            'page'    => self::PAGE,
            'tab'     => $tab,
            'orderby' => $list->orderBy === 'name' ? '' : $list->orderBy,
            'order'   => $list->descending ? 'desc' : '',
        ] + $keep);

        return ListPage::searchBox(self::FILTER_FORM, $list->search, $placeholder, $hidden, __('Suchen', 'taxmod-stock'))
            . ListPage::bulkBar(self::BULK_FORM, self::ACTION, [], $count, ListPage::filters(self::FILTER_FORM, $filters));
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
