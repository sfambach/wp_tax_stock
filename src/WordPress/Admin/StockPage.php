<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\MaterialInput;
use TaxmodStock\Core\MaterialNames;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * The page «Lager» under the Taxonomy Modeller: stock list, materials, stock count, goods receipt, consumption (stage 1, D-958 F7).
 *
 * A material is shown as «#id · name», the name taken from «Bezug Ding» (D-967).
 *
 * Every form posts to one handler, which works in the order of CD-5 and books in one write (all or nothing).
 */
final class StockPage
{
    public const PAGE       = 'taxmod-stock';
    public const ACTION     = 'taxmod_stock_book';
    private const NOTICE    = 'taxmod_stock_notice';
    private const ROWS      = 6;
    private const TABS      = ['bestand', 'material', 'inventur', 'wareneingang', 'verbrauch'];

    /** The modeller's search over every record under `Model` (D-958 F3a), reused for «Bezug Ding». */
    private const RECORD_SEARCH = 'taxmod_record_search';

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    public function render(): string
    {
        $tab  = sanitize_key((string) ($_GET['tab'] ?? 'bestand'));
        $tab  = in_array($tab, self::TABS, true) ? $tab : 'bestand';
        $html = '<div class="wrap"><h1>' . esc_html__('Lager', 'taxmod-stock') . '</h1>' . $this->notice() . $this->tabBar($tab);

        try {
            $html .= match ($tab) {
                'material'     => $this->materialTab(absint($_GET['material'] ?? 0)),
                'inventur'     => $this->inventoryForm(),
                'wareneingang' => $this->receiptForm(absint($_GET['order'] ?? 0)),
                'verbrauch'    => $this->consumptionForm(),
                default        => $this->stockList(),
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

    private function stockList(): string
    {
        $s         = $this->stock->structure();
        $ledger    = $this->stock->ledger();
        $materials = $this->stock->materialNames()->all();
        $places    = $this->stock->labels('location');
        $lots      = $this->stock->table('lot');
        $money     = $this->stock->table('money');
        $matTable  = $this->stock->table('material');

        if ($materials === []) {
            return '<p>' . esc_html__('Noch kein Material. Eine Inventur oder ein Wareneingang legt es an.', 'taxmod-stock') . '</p>';
        }

        $html = '<table class="widefat striped"><thead><tr><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th>'
            . esc_html__('Bestand', 'taxmod-stock') . '</th><th>' . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><th>'
            . esc_html__('Bestandsposten', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ($materials as $id => $label) {
            $stock = $ledger->stockOf($id);
            $min   = $matTable->first($id, $s->field('material_min'));
            $low   = $min !== null && $stock < (float) $min;
            $rows  = '';

            foreach ($ledger->lotsOf($id) as $lot) {
                $value    = $lots->first($lot, $s->field('lot_value'));
                $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
                $purchase = $lots->first($lot, $s->field('lot_purchase'));
                $place    = $lots->first($lot, $s->field('lot_location'));

                $rows .= '<li>' . esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10))
                    . ' · ' . esc_html__('Rest', 'taxmod-stock') . ' ' . esc_html($this->number($ledger->restOf($lot)))
                    . ($amount === null ? '' : ' · ' . esc_html($amount) . ' ' . esc_html__('je Stück', 'taxmod-stock'))
                    . ($purchase === null ? ' · ' . esc_html__('Inventur', 'taxmod-stock') : ' · ' . esc_html__('Kauf', 'taxmod-stock') . ' #' . esc_html($purchase))
                    . ($place === null ? '' : ' · ' . esc_html($places[(int) $place] ?? '#' . $place)) . '</li>';
            }

            $html .= '<tr><td>' . esc_html($label) . '</td><td' . ($low ? ' style="color:#b32d2e;font-weight:600"' : '') . '>'
                . esc_html($this->number($stock)) . '</td><td>' . esc_html((string) ($min ?? '—')) . '</td><td>'
                . ($rows === '' ? '—' : '<details><summary>' . count($ledger->lotsOf($id)) . '</summary><ul>' . $rows . '</ul></details>')
                . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function inventoryForm(): string
    {
        $options = $this->options($this->stock->materialNames()->all(), __('— neues Material —', 'taxmod-stock'));
        $html    = $this->formStart('inventur')
            . '<p>' . esc_html__('Gezählter Bestand ohne Kauf. Jede Zeile wird ein Bestandsposten. Einem späteren Kauf zuordnen kommt mit Stufe 2 (F10).', 'taxmod-stock') . '</p>'
            . '<p>' . esc_html__('Ein neues Material mit «Bezug Ding» heisst wie das Ding; einen eigenen Namen braucht nur ein Material ohne Bezug.', 'taxmod-stock') . '</p>'
            . '<table class="widefat"><thead><tr><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th>' . esc_html__('oder neu: Name', 'taxmod-stock')
            . '</th><th>' . esc_html__('Bezug Ding (Satz-Id)', 'taxmod-stock') . '</th><th>' . esc_html__('Menge', 'taxmod-stock') . '</th><th>'
            . esc_html__('Wert je Stück', 'taxmod-stock') . '</th><th>' . esc_html__('Hinweis', 'taxmod-stock') . '</th></tr></thead><tbody>';

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

    private function receiptForm(int $order): string
    {
        $orders = $this->stock->labels('order');
        $html   = '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="tab" value="wareneingang">'
            . '<select name="order">' . $this->options($orders, __('— Bestellung wählen —', 'taxmod-stock'), $order) . '</select> '
            . get_submit_button(__('Positionen zeigen', 'taxmod-stock'), 'secondary', '', false) . '</form>';

        if ($order === 0 || ! isset($orders[$order])) {
            return $html;
        }

        $names  = $this->stock->materialNames();
        $plan   = $this->stock->receiptPlan($order, $names);
        $labels = $this->stock->labels('purchase');
        $models = $this->stock->recordLabels(array_values(array_filter(array_column($plan, 'model'))));
        $all    = $names->all();

        $html .= $this->formStart('wareneingang') . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . '<p>' . esc_html__('Je Kauf ein Material: vorgeschlagen ist das Material, das schon auf das «Modell» des Kaufs verweist, sonst ein neues mit diesem Bezug. Suchen filtert die Liste; «neu anlegen» nimmt den angegebenen Bezug, ohne Angabe das Modell.', 'taxmod-stock') . '</p>'
            . '<table class="widefat striped"><thead><tr><th></th><th>' . esc_html__('Kauf', 'taxmod-stock') . '</th><th>' . esc_html__('Modell', 'taxmod-stock')
            . '</th><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th>' . esc_html__('Bezug Ding für «neu anlegen»', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ($plan as $purchase => $row) {
            $lock     = $row['lock'];
            $proposal = $row['proposal'];
            $model    = $row['model'];
            $select   = 'taxmod-stock-choice-' . $purchase;
            $html    .= '<tr><td><input type="checkbox" name="purchases[]" value="' . esc_attr((string) $purchase) . '"'
                . ($lock === null && $proposal !== null ? ' checked' : '') . ($lock === null ? '' : ' disabled') . '></td>'
                . '<td>' . esc_html($labels[$purchase] ?? '#' . $purchase) . ($lock === null ? '' : ' <em>(' . esc_html(Messages::lockReason($lock)) . ')</em>') . '</td>'
                . '<td>' . ($model === null ? '—' : esc_html('#' . $model . (($models[$model] ?? '') === '' ? '' : ' · ' . $models[$model]))) . '</td>';

            if ($lock !== null) {
                $html .= '<td>' . esc_html(implode(', ', array_map($names->name(...), array_filter($row['materials'], $names->has(...))))) . '</td><td></td></tr>';
                continue;
            }

            $html .= '<td><input type="search" class="taxmod-stock-filter" data-for="' . esc_attr($select) . '" placeholder="' . esc_attr__('suchen …', 'taxmod-stock') . '" style="width:8em"> '
                . '<select id="' . esc_attr($select) . '" name="choice[' . esc_attr((string) $purchase) . ']">'
                . $this->choiceOptions($row['materials'], $proposal, $all, $names) . '</select></td>'
                . '<td>' . $this->thingField('thing[' . $purchase . ']', 'taxmod-stock-thing-p' . $purchase, null) . '</td></tr>';
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

    private function materialTab(int $edit): string
    {
        $s      = $this->stock->structure();
        $names  = $this->stock->materialNames();
        $table  = $this->stock->table('material');
        $ledger = $this->stock->ledger();
        $uses   = $this->stock->uses();
        $places = $this->stock->labels('location');
        $field  = static fn (string $key): ?int => $s->hasField($key) ? $s->field($key) : null;
        $html   = '<table class="widefat striped"><thead><tr><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th>' . esc_html__('Bezug Ding', 'taxmod-stock')
            . '</th><th>' . esc_html__('Einzelstück', 'taxmod-stock') . '</th><th>' . esc_html__('Verwendung', 'taxmod-stock') . '</th><th>'
            . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><th>' . esc_html__('Bestand', 'taxmod-stock') . '</th><th></th></tr></thead><tbody>';

        foreach ($names->all() as $id => $label) {
            $thing = $names->thingOf($id);
            $use   = $field('material_use') === null ? null : $table->first($id, (int) $field('material_use'));

            $html .= '<tr><td>' . esc_html($label) . '</td><td>' . ($thing === null ? '—' : esc_html('#' . $thing)) . '</td><td>'
                . esc_html($table->first($id, $s->field('material_single')) === '1' ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')) . '</td><td>'
                . esc_html($use === null ? '—' : ($uses[(int) $use] ?? '#' . $use)) . '</td><td>'
                . esc_html((string) ($table->first($id, $s->field('material_min')) ?? '—')) . '</td><td>' . esc_html($this->number($ledger->stockOf($id))) . '</td><td>'
                . '<a href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'material' => $id], admin_url('admin.php'))) . '">' . esc_html__('bearbeiten', 'taxmod-stock') . '</a></td></tr>';
        }

        $html .= '</tbody></table>';

        $edit  = $table->has($edit) ? $edit : 0;
        $value = static fn (?int $relation): string => $edit === 0 || $relation === null ? '' : (string) $table->first($edit, $relation);
        $html .= '<h2>' . esc_html($edit === 0 ? __('Neues Material', 'taxmod-stock') : sprintf(
            /* translators: %s: «#id · name» of the material */
            __('Material %s bearbeiten', 'taxmod-stock'),
            $names->name($edit)
        )) . '</h2>' . $this->formStart('material') . '<input type="hidden" name="material" value="' . esc_attr((string) $edit) . '">'
            . '<p>' . esc_html__('Mit «Bezug Ding» heisst das Material wie das Ding, eine eigene Bezeichnung wird dann geleert. Ohne Bezug braucht es eine Bezeichnung.', 'taxmod-stock') . '</p>'
            . '<table class="form-table"><tbody>'
            . '<tr><th>' . esc_html__('Bezug Ding', 'taxmod-stock') . '</th><td>' . $this->thingField('thing', 'taxmod-stock-thing-m', $edit === 0 ? null : $names->thingOf($edit)) . '</td></tr>'
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
     * The ticked purchases with their material: «keep», «new» (with the Bezug typed, else the Modell) or a material id.
     *
     * @return array<int, ReceiptChoice>
     */
    private function receiptChoices(): array
    {
        $choices = (array) ($_POST['choice'] ?? []);
        $things  = (array) ($_POST['thing'] ?? []);
        $out     = [];

        foreach (array_filter(array_map('absint', (array) ($_POST['purchases'] ?? []))) as $purchase) {
            $choice = sanitize_key((string) wp_unslash($choices[$purchase] ?? ''));

            $out[$purchase] = match (true) {
                $choice === 'keep'     => ReceiptChoice::keep(),
                $choice === 'new'      => ReceiptChoice::create(absint($things[$purchase] ?? 0) ?: null),
                absint($choice) > 0    => ReceiptChoice::existing(absint($choice)),
                default                => throw StockError::purchaseWithoutMaterial($purchase),
            };
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

    /** A record id field for «Bezug Ding», with the modeller's search over every record under Model beside it. */
    private function thingField(string $name, string $id, ?int $value): string
    {
        return '<input type="number" min="1" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) ($value ?? '')) . '" style="width:8em"> '
            . '<input type="search" class="taxmod-stock-thing-search" data-for="' . esc_attr($id) . '" list="' . esc_attr($id . '-hits') . '" placeholder="'
            . esc_attr__('Ding suchen …', 'taxmod-stock') . '" style="width:12em"><datalist id="' . esc_attr($id . '-hits') . '"></datalist>';
    }

    /** Filters a material list as one types, and asks the modeller's record search for «Bezug Ding». */
    private function script(): string
    {
        $search = add_query_arg(['action' => self::RECORD_SEARCH, '_taxmod_nonce' => wp_create_nonce(self::RECORD_SEARCH)], admin_url('admin-post.php'));

        return '<script>(function(){'
            . 'var url=' . wp_json_encode($search) . ';'
            . 'document.querySelectorAll(".taxmod-stock-filter").forEach(function(f){var s=document.getElementById(f.dataset.for);if(!s){return;}var o=Array.prototype.slice.call(s.options);'
            . 'f.addEventListener("input",function(){var q=f.value.toLowerCase(),first=null;o.forEach(function(x){var fix=x.value===""||x.value==="new"||x.value==="keep";x.hidden=q!==""&&!fix&&x.text.toLowerCase().indexOf(q)<0;if(!fix&&!x.hidden&&!first){first=x;}});if(q!==""&&first){s.value=first.value;}});});'
            . 'document.querySelectorAll(".taxmod-stock-thing-search").forEach(function(f){var t=document.getElementById(f.dataset.for),l=document.getElementById(f.getAttribute("list")),w;'
            . 'f.addEventListener("input",function(){var m=f.value.match(/^#(\\d+)/);if(m){t.value=m[1];return;}clearTimeout(w);if(f.value.trim().length<2){return;}'
            . 'w=setTimeout(function(){fetch(url+"&term="+encodeURIComponent(f.value),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){l.innerHTML="";(j.data||[]).forEach(function(h){var x=document.createElement("option");x.value="#"+h.id+" · "+h.word+(h.node?" ("+h.node+")":"");l.appendChild(x);});});},250);});});'
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

    private function tabBar(string $active): string
    {
        $titles = [
            'bestand'      => __('Bestand', 'taxmod-stock'),
            'material'     => __('Material', 'taxmod-stock'),
            'inventur'     => __('Inventur', 'taxmod-stock'),
            'wareneingang' => __('Wareneingang', 'taxmod-stock'),
            'verbrauch'    => __('Verbrauch', 'taxmod-stock'),
        ];
        $html = '<nav class="nav-tab-wrapper">';

        foreach ($titles as $slug => $title) {
            $html .= '<a class="nav-tab' . ($slug === $active ? ' nav-tab-active' : '') . '" href="'
                . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => $slug], admin_url('admin.php'))) . '">' . esc_html($title) . '</a>';
        }

        return $html . '</nav>';
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
