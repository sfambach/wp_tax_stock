<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * The page «Lager» under the Taxonomy Modeller: stock list, stock count, goods receipt, consumption (stage 1, D-958 F7).
 *
 * Every form posts to one handler, which works in the order of CD-5 and books in one write (all or nothing).
 */
final class StockPage
{
    public const PAGE       = 'taxmod-stock';
    public const ACTION     = 'taxmod_stock_book';
    private const NOTICE    = 'taxmod_stock_notice';
    private const ROWS      = 6;
    private const TABS      = ['bestand', 'inventur', 'wareneingang', 'verbrauch'];

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
                'inventur'     => $this->inventoryForm(),
                'wareneingang' => $this->receiptForm(absint($_GET['order'] ?? 0)),
                'verbrauch'    => $this->consumptionForm(),
                default        => $this->stockList(),
            };
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . '</div>';
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
        $back  = ['bestand'];

        try {
            $result = match ($kind) {
                'inventur'     => $this->stock->bookInventory($this->inventoryRows(), $today, $this->title(__('Inventur', 'taxmod-stock'), $today)),
                'wareneingang' => $this->stock->bookReceipt(
                    absint($_POST['order'] ?? 0),
                    array_values(array_filter(array_map('absint', (array) ($_POST['purchases'] ?? [])))),
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
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
            $back = [$kind];
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => $back[0]], admin_url('admin.php')));
        exit;
    }

    private function stockList(): string
    {
        $s         = $this->stock->structure();
        $ledger    = $this->stock->ledger();
        $materials = $this->stock->labels('material');
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
        $options = $this->options($this->stock->labels('material'), __('— neues Material —', 'taxmod-stock'));
        $html    = $this->formStart('inventur')
            . '<p>' . esc_html__('Gezählter Bestand ohne Kauf. Jede Zeile wird ein Bestandsposten. Einem späteren Kauf zuordnen kommt mit Stufe 2 (F10).', 'taxmod-stock') . '</p>'
            . '<table class="widefat"><thead><tr><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th>' . esc_html__('oder neu: Name', 'taxmod-stock')
            . '</th><th>' . esc_html__('Bezug Ding (Satz-Id)', 'taxmod-stock') . '</th><th>' . esc_html__('Menge', 'taxmod-stock') . '</th><th>'
            . esc_html__('Wert je Stück', 'taxmod-stock') . '</th><th>' . esc_html__('Hinweis', 'taxmod-stock') . '</th></tr></thead><tbody>';

        for ($i = 0; $i < self::ROWS; $i++) {
            $html .= '<tr><td><select name="rows[' . $i . '][material]">' . $options . '</select></td>'
                . '<td><input type="text" name="rows[' . $i . '][name]"></td>'
                . '<td><input type="number" min="1" name="rows[' . $i . '][thing]" style="width:8em"></td>'
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

        $labels = $this->stock->labels('purchase');
        $html  .= $this->formStart('wareneingang') . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><ul>';

        foreach ($this->stock->purchasesOf($order) as $purchase => $lock) {
            $html .= '<li><label><input type="checkbox" name="purchases[]" value="' . esc_attr((string) $purchase) . '"'
                . ($lock === null ? ' checked' : ' disabled') . '> ' . esc_html($labels[$purchase] ?? '#' . $purchase)
                . ($lock === null ? '' : ' <em>(' . esc_html(Messages::lockReason($lock)) . ')</em>') . '</label></li>';
        }

        return $html . '</ul>' . get_submit_button(__('Wareneingang buchen', 'taxmod-stock')) . '</form>';
    }

    private function consumptionForm(): string
    {
        $ledger = $this->stock->ledger();
        $labels = $this->stock->labels('material');
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

    /** @return list<InventoryRow> */
    private function inventoryRows(): array
    {
        $rows = [];

        foreach ((array) ($_POST['rows'] ?? []) as $row) {
            $row      = is_array($row) ? wp_unslash($row) : [];
            $material = absint($row['material'] ?? 0) ?: null;
            $name     = sanitize_text_field((string) ($row['name'] ?? ''));
            $quantity = sanitize_text_field((string) ($row['quantity'] ?? ''));

            // An empty row is no row; a row with neither material nor name cannot be booked and is left out the same way.
            if ($quantity === '' || ($material === null && $name === '')) {
                continue;
            }

            $rows[] = new InventoryRow(
                $material,
                $name,
                absint($row['thing'] ?? 0) ?: null,
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
