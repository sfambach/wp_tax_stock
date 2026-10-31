<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\Purchasing;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;
use TaxmodStock\WordPress\InboundService;
use TaxmodStock\WordPress\StockService;

/**
 * The page «Einkauf» under the Taxmod menu (D-1027, D-1028): what to reorder, the demands without a supplier, the supplier's drafts to
 * order, and what is ordered. He orders at the shop himself; the page prepares the list and records what he did.
 *
 * ```mermaid
 * flowchart LR
 *   N["Nachbestellen"] -->|In Bedarf| B["Bedarf"] -->|Bezugsquelle| E["Bestellen (je Händler)"] -->|bestellt| O["Bestellt → Wareneingang"]
 * ```
 *
 * Drawn with the modeller's shared list page (D-989). Every button posts to one handler, in the order of CD-5, and writes
 * in one call; every step has its way back.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/einkauf.md
 */
final class PurchasePage
{
    public const PAGE   = 'taxmod-stock-einkauf';
    public const ACTION = 'taxmod_stock_purchase';

    private const TABS      = ['nachbestellen', 'bedarf', 'bestellen', 'bestellt', 'verworfen'];
    private const BULK_FORM = 'taxmod-stock-purchase-bulk';

    /**
     * D-1027 E6: supplier modules answer a search with offers — `['list' => [offer, …], 'errors' => [merchant => text]]`,
     * an offer being merchant, number, maker, maker_number, description, stock, price, currency, min, mult, url.
     */
    public const OFFERS = 'taxmod_supplier_offers';

    private readonly InboundService $inbound;

    public function __construct(private readonly StockService $stock = new StockService())
    {
        $this->inbound = new InboundService($this->stock);
    }

    /** @param array<string, int|string> $query */
    public static function url(array $query = []): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE] + $query), admin_url('admin.php'));
    }

    /** The address of the reorder dialog for these things — the button «Nachbestellen» of a material. */
    public static function reorderUrl(int ...$things): string
    {
        return self::url(['tab' => 'neu', 'things' => implode(',', $things)]);
    }

    public function render(): string
    {
        if (! class_exists(ListPage::class) || ! method_exists(ListPage::class, 'tabs')) {
            return '<div class="wrap"><h1>' . esc_html__('Einkauf', 'taxmod-stock') . '</h1><div class="notice notice-error"><p>'
                . esc_html__('Die Seite braucht den Taxonomy Modeller ab Version 0.1.65 (gemeinsame Listenseite).', 'taxmod-stock') . '</p></div></div>';
        }

        $html = '<div class="wrap">' . ListPage::heading(__('Einkauf', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice();

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

        $op       = sanitize_key((string) ($_POST['op'] ?? '')) ?: sanitize_key((string) ($_POST['bulk'] ?? ''));
        $tab      = sanitize_key((string) ($_POST['tab'] ?? ''));
        $order    = absint($_POST['order'] ?? 0);
        $purchase = absint($_POST['purchase'] ?? 0);
        $text     = static fn (string $key): string => sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? '')));
        $back     = ['tab' => in_array($tab, self::TABS, true) ? $tab : '', 'order' => $order];

        // The bulk action of «Nachbestellen» only chooses: the dialog asks for quantities and the demand.
        if ($op === 'pick') {
            $things = array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))));
            wp_safe_redirect($things === [] ? self::url(['tab' => 'nachbestellen']) : self::reorderUrl(...$things));
            exit;
        }

        try {
            $result = match ($op) {
                'reorder'   => $this->stock->reorder($this->quantities(), absint($_POST['demand'] ?? 0) ?: null, $text('name')),
                'source'    => $this->stock->sourcePosition($order, $purchase, absint($_POST['firm'] ?? 0) ?: null, $text('merchant'), $text('number'), $text('price') === '' ? null : $text('price')),
                'unsource'  => $this->stock->unsourcePosition($order, $purchase),
                'quantity'  => $this->stock->setPositionQuantity($order, $purchase, $text('quantity')),
                'remove'    => $this->stock->removePosition($order, $purchase),
                'ordered'   => $this->stock->markOrdered($order, $text('number')),
                'back'      => $this->stock->orderBackToDraft($order, (string) (($this->inbound->orders()[$order] ?? null)?->receivedOn ?? '')),
                'discard'   => $this->stock->discardOrder($order),
                'restore'   => $this->stock->restoreOrder($order),
                default     => throw StockError::nothingToBook(),
            };
            /* translators: %d: number of changes the modeller applied */
            $this->remember('success', sprintf(__('Gespeichert: %d Änderungen in einem Schritt.', 'taxmod-stock'), count((array) ($result['applied'] ?? []))));
            $back = match ($op) {
                'reorder' => ['tab' => 'bedarf'],
                'ordered' => ['tab' => 'bestellt'],
                'source'  => ['tab' => 'bedarf', 'order' => $order],
                'unsource', 'restore' => ['tab' => 'bedarf'],
                'back'    => ['tab' => 'bestellen', 'order' => $order],
                default   => $back,
            };
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));

            if ($op === 'reorder') {
                $back = ['tab' => 'neu', 'things' => implode(',', array_keys($this->quantities()))];
            }
        }

        wp_safe_redirect(self::url($back));
        exit;
    }

    private function body(): string
    {
        $s         = $this->stock->structure();
        $p         = $this->stock->purchasing();
        $orders    = $this->stock->table('order');
        $purchases = $this->stock->table('purchase');
        $tab       = sanitize_key((string) ($_GET['tab'] ?? self::TABS[0]));
        $open      = absint($_GET['order'] ?? 0);

        if (! $s->hasValue('status_draft') || ! $s->hasValue('status_ordered') || ! $s->hasField('purchase_demand') || ! $s->hasField('order_merchant')) {
            throw StockError::notConfigured(! $s->hasField('purchase_demand') ? 'fields.purchase_demand' : 'values.status_ordered');
        }

        $groups = ['bedarf' => [], 'bestellen' => [], 'bestellt' => [], 'verworfen' => []];

        foreach ($orders->ids() as $id) {
            $group = match (true) {
                $p->isDemand($id, $orders)                                                           => 'bedarf',
                $p->isSupplierDraft($id, $orders)                                                    => 'bestellen',
                $p->isOrdered($id, $orders) && ($this->inbound->orders()[$id]->receivedOn ?? '') === '' => 'bestellt',
                $p->isCancelled($id, $orders) && $this->madeHere($id, $orders, $purchases)            => 'verworfen',
                default                                                                              => null,
            };

            if ($group !== null) {
                $groups[$group][] = $id;
            }
        }

        $reorder = $this->reorderRows($orders, $purchases);
        $tabs    = [
            ['nachbestellen', __('Nachbestellen', 'taxmod-stock'), count($reorder)],
            ['bedarf', __('Bedarf', 'taxmod-stock'), count($groups['bedarf'])],
            ['bestellen', __('Bestellen', 'taxmod-stock'), count($groups['bestellen'])],
            ['bestellt', __('Bestellt', 'taxmod-stock'), count($groups['bestellt'])],
            ['verworfen', __('Verworfen', 'taxmod-stock'), count($groups['verworfen'])],
        ];
        $bar     = ListPage::tabs($tabs, match ($tab) { 'neu' => 'nachbestellen', 'angebote' => 'bedarf', default => $tab }, static fn (string $slug): string => self::url(['tab' => $slug]));

        if ($tab === 'angebote') {
            return $bar . $this->offersView(absint($_GET['order'] ?? 0), absint($_GET['purchase'] ?? 0), $orders, $purchases);
        }

        if ($tab === 'neu') {
            return $bar . $this->reorderDialog($groups['bedarf'], $orders, $purchases);
        }

        if ($tab === 'nachbestellen' || ! in_array($tab, self::TABS, true)) {
            return $bar . $this->reorderList($reorder);
        }

        $rows = '';

        foreach ($groups[$tab] as $id) {
            $rows .= $this->orderRow($tab, $id, $orders, $purchases, $id === $open);
        }

        $columns = [[__('Bezeichnung', 'taxmod-stock'), ''], [__('Händler', 'taxmod-stock'), '14em'], [__('Positionen', 'taxmod-stock'), '16em']];
        $rows    = $rows !== '' ? $rows : '<tr class="no-items"><td colspan="5">' . esc_html__('Nichts da.', 'taxmod-stock') . '</td></tr>';
        $hint    = match ($tab) {
            'bedarf'    => __('Was gebraucht wird, noch ohne Händler. Je Position eine Bezugsquelle wählen: sie wandert in den Entwurf des Händlers, der Bedarf bleibt als Klammer.', 'taxmod-stock'),
            'bestellen' => __('Je Händler ein Entwurf. Beim Händler selbst bestellen (Warenkorb-Liste kopieren), dann «Als bestellt markieren».', 'taxmod-stock'),
            'bestellt'  => __('Bestellt und noch nicht angekommen. Weiter geht es im Wareneingang.', 'taxmod-stock'),
            default     => __('Verworfene Bedarfe und Entwürfe; «Wiederherstellen» macht sie wieder zum Entwurf.', 'taxmod-stock'),
        };

        return $bar . '<p class="description">' . esc_html($hint) . '</p>' . ListPage::table($columns, $rows);
    }

    /**
     * The things under their minimum, counting what is already wanted or ordered.
     *
     * @return array<int, array{name: string, stock: float, minimum: float, open: float, proposed: int}>
     */
    private function reorderRows(Table $orders, Table $purchases): array
    {
        $s      = $this->stock->structure();
        $lager  = $this->stock->table('lager');
        $ledger = $this->stock->ledger();
        $things = $this->stock->things();
        $open   = $this->stock->purchasing()->openQuantities($orders, $purchases);
        $out    = [];

        foreach ($lager->ids() as $record) {
            $thing   = (int) $lager->first($record, $s->field('lager_thing'));
            $minimum = (float) ($lager->first($record, $s->field('lager_min')) ?? 0);

            if ($thing <= 0 || $minimum <= 0.0 || $things->lagerOf($thing) !== $record) {
                continue;
            }

            $stock = $ledger->stockOf($thing);
            $come  = $open[$thing] ?? 0.0;

            if ($stock + $come < $minimum) {
                $out[$thing] = ['name' => $things->name($thing), 'stock' => $stock, 'minimum' => $minimum, 'open' => $come, 'proposed' => Purchasing::proposedQuantity($minimum, $stock, $come)];
            }
        }

        uasort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** @param array<int, array{name: string, stock: float, minimum: float, open: float, proposed: int}> $rows */
    private function reorderList(array $rows): string
    {
        $html = '<p class="description">' . esc_html__('Material unter dem Mindestbestand (Bestand plus schon gewünscht oder bestellt). Auswählen und «In Bedarf übernehmen».', 'taxmod-stock') . '</p>'
            . $this->bulkBar('nachbestellen', ['pick' => __('In Bedarf übernehmen', 'taxmod-stock')], count($rows));
        $body = '';

        foreach ($rows as $thing => $row) {
            $body .= ListPage::row(self::BULK_FORM, (string) $thing, [
                '<a href="' . esc_url(StockPage::thingUrl($thing, 'bestand')) . '">' . esc_html($row['name']) . '</a>',
                esc_html($this->number($row['stock'])),
                esc_html($this->number($row['minimum'])),
                esc_html($this->number($row['open'])),
            ], '<a class="button button-primary" href="' . esc_url(self::reorderUrl($thing)) . '">' . esc_html__('Nachbestellen', 'taxmod-stock') . '</a>', '');
        }

        $body = $body !== '' ? $body : '<tr class="no-items"><td colspan="6">' . esc_html__('Alles über dem Mindestbestand.', 'taxmod-stock') . '</td></tr>';

        return $html . ListPage::table([[__('Material', 'taxmod-stock'), ''], [__('Bestand', 'taxmod-stock'), '7em'], [__('Mindestbestand', 'taxmod-stock'), '9em'], [__('Unterwegs / gewünscht', 'taxmod-stock'), '11em']], $body);
    }

    /**
     * The dialog of «Nachbestellen»: quantity per thing, and the demand to add to — an open one or a new one.
     *
     * @param list<int> $demands
     */
    private function reorderDialog(array $demands, Table $orders, Table $purchases): string
    {
        $s       = $this->stock->structure();
        $things  = array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) ($_GET['things'] ?? '')))))));
        $labels  = $this->stock->recordLabels($things);
        $ledger  = $this->stock->ledger();
        $lager   = $this->stock->table('lager');
        $open    = $this->stock->purchasing()->openQuantities($orders, $purchases);
        $choices = ['0' => __('— Neuer Bedarf —', 'taxmod-stock')];

        if ($things === []) {
            return '<p>' . esc_html__('Kein Material gewählt.', 'taxmod-stock') . '</p>';
        }

        foreach ($demands as $demand) {
            $choices[(string) $demand] = (string) $orders->first($demand, $s->field('label')) . ' (#' . $demand . ')';
        }

        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin-top:12px;max-width:900px">'
            . $this->hidden('reorder', 'neu')
            . '<h2 style="margin-top:0">' . esc_html__('Nachbestellen', 'taxmod-stock') . '</h2>'
            . '<table class="widefat striped"><thead><tr><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th>' . esc_html__('Bestand', 'taxmod-stock') . '</th><th>'
            . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><th>' . esc_html__('Unterwegs / gewünscht', 'taxmod-stock') . '</th><th>' . esc_html__('Menge', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ($things as $thing) {
            $record  = $this->stock->things()->lagerOf($thing);
            $minimum = $record === null ? null : $lager->first($record, $s->field('lager_min'));
            $stock   = $ledger->stockOf($thing);
            $come    = $open[$thing] ?? 0.0;
            $html   .= '<tr><td>' . esc_html(($labels[$thing] ?? '') . ' (Nr. ' . $thing . ')') . '</td><td>' . esc_html($this->number($stock)) . '</td><td>'
                . esc_html($minimum === null ? '—' : $minimum) . '</td><td>' . esc_html($this->number($come)) . '</td>'
                . '<td><input type="number" min="1" step="1" style="width:7em" name="quantity[' . esc_attr((string) $thing) . ']" value="'
                . esc_attr((string) Purchasing::proposedQuantity($minimum === null ? null : (float) $minimum, $stock, $come)) . '"></td></tr>';
        }

        return $html . '</tbody></table><p><label>' . esc_html__('In Bedarf', 'taxmod-stock') . ' <select name="demand">' . $this->select($choices, (string) ($demands === [] ? '0' : end($demands))) . '</select></label> '
            . '<label>' . esc_html__('Name des neuen Bedarfs', 'taxmod-stock') . ' <input type="text" class="regular-text" name="name" placeholder="' . esc_attr__('z. B. Projekt oder «Lager auffüllen»', 'taxmod-stock') . '"></label></p>'
            . get_submit_button(__('Hinzufügen', 'taxmod-stock'), 'primary', '', false) . ' <a class="button" href="' . esc_url(self::url(['tab' => 'nachbestellen'])) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a></form>';
    }

    private function orderRow(string $tab, int $order, Table $orders, Table $purchases, bool $open): string
    {
        $s        = $this->stock->structure();
        $p        = $this->stock->purchasing();
        $lines    = array_map('intval', $orders->all($order, $s->field('order_lines')));
        $label    = (string) $orders->first($order, $s->field('label'));
        $merchant = $p->merchantOf($order, $orders);
        $number   = $s->hasField('order_number') ? (string) $orders->first($order, $s->field('order_number')) : '';
        $sourced  = $tab === 'bedarf' ? $this->sourcedFrom($order, $purchases) : [];
        $count    = sprintf(/* translators: %d: positions */ _n('%d Position', '%d Positionen', count($lines), 'taxmod-stock'), count($lines))
            . ($sourced === [] ? '' : ' · ' . sprintf(/* translators: %d: positions at a supplier */ __('%d beim Händler', 'taxmod-stock'), count($sourced)));
        $buttons  = match ($tab) {
            'bedarf', 'bestellen' => $this->button('discard', $order, 0, __('Verwerfen', 'taxmod-stock'), $tab, false, __('Verwerfen? Er wird storniert, nicht gelöscht, und lässt sich unter «Verworfen» wiederherstellen.', 'taxmod-stock')),
            'bestellt'  => $this->button('back', $order, 0, __('Zurück zu Entwurf', 'taxmod-stock'), $tab, false)
                . '<a class="button" href="' . esc_url(InboundPage::url(['tab' => 'ordered'])) . '">' . esc_html__('Wareneingang', 'taxmod-stock') . '</a>',
            default     => $this->button('restore', $order, 0, __('Wiederherstellen', 'taxmod-stock'), $tab, true),
        };

        return ListPage::row(self::BULK_FORM, (string) $order, [
            '<strong>' . esc_html($label === '' ? '#' . $order : $label) . '</strong> <span class="description">#' . esc_html((string) $order) . ($number === '' ? '' : ' · ' . esc_html($number)) . '</span>',
            esc_html($merchant === '' ? '—' : $merchant),
            esc_html($count),
        ], $buttons, $this->details($tab, $order, $lines, $sourced, $orders, $purchases), $open);
    }

    /**
     * @param list<int> $lines
     * @param list<int> $sourced positions of this demand that went to a supplier
     */
    private function details(string $tab, int $order, array $lines, array $sourced, Table $orders, Table $purchases): string
    {
        $s    = $this->stock->structure();
        $p    = $this->stock->purchasing();
        $edit = in_array($tab, ['bedarf', 'bestellen'], true);
        $html = '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>' . esc_html__('Position', 'taxmod-stock') . '</th><th>' . esc_html__('Art.-Nr.', 'taxmod-stock') . '</th><th>'
            . esc_html__('Menge', 'taxmod-stock') . '</th><th>' . ($tab === 'bedarf' ? esc_html__('Bezugsquelle', 'taxmod-stock') : '') . '</th><th></th></tr></thead><tbody>';

        foreach ($lines as $line) {
            $thing  = (int) $purchases->first($line, $s->field('purchase_model'));
            $number = $s->hasField('purchase_number') ? (string) $purchases->first($line, $s->field('purchase_number')) : '';
            $html  .= '<tr><td>' . ($thing > 0 ? '<a href="' . esc_url(StockPage::thingUrl($thing, 'einkauf')) . '">' . esc_html((string) $purchases->first($line, $s->field('label'))) . '</a>' : esc_html((string) $purchases->first($line, $s->field('label'))))
                . ' <span class="description">#' . esc_html((string) $line) . '</span></td><td>' . esc_html($number === '' ? '—' : $number) . '</td>'
                . '<td>' . ($edit ? $this->quantityForm($order, $line, (string) ($purchases->first($line, $s->field('purchase_quantity')) ?? '1'), $tab) : esc_html((string) ($purchases->first($line, $s->field('purchase_quantity')) ?? '—'))) . '</td>'
                . '<td>' . ($tab === 'bedarf' ? $this->sourceForm($order, $line, $thing) : '') . '</td><td style="text-align:right;white-space:nowrap">'
                . ($tab === 'bedarf' && has_filter(self::OFFERS) ? '<a class="button" href="' . esc_url(self::url(['tab' => 'angebote', 'order' => $order, 'purchase' => $line])) . '">' . esc_html__('Angebote', 'taxmod-stock') . '</a> ' : '')
                . ($tab === 'bestellen' && (int) $purchases->first($line, $s->field('purchase_demand')) > 0 ? $this->button('unsource', $order, $line, __('Zurück in Bedarf', 'taxmod-stock'), $tab, false) : '')
                . ($edit ? $this->button('remove', $order, $line, __('Entfernen', 'taxmod-stock'), $tab, false, __('Position entfernen? «Nachbestellen» legt sie wieder an.', 'taxmod-stock')) : '') . '</td></tr>';
        }

        foreach ($sourced as $line) {
            $at    = $this->orderOf($line, $orders);
            $state = $at === null ? '' : ($p->isSupplierDraft($at, $orders) ? __('im Entwurf', 'taxmod-stock') : ($p->isOrdered($at, $orders) ? __('bestellt', 'taxmod-stock') : __('eingegangen oder storniert', 'taxmod-stock')));
            $html .= '<tr style="opacity:.75"><td>' . esc_html((string) $purchases->first($line, $s->field('label'))) . ' <span class="description">#' . esc_html((string) $line) . '</span></td>'
                . '<td>' . esc_html($s->hasField('purchase_number') ? (string) $purchases->first($line, $s->field('purchase_number')) : '') . '</td>'
                . '<td>' . esc_html((string) ($purchases->first($line, $s->field('purchase_quantity')) ?? '')) . '</td>'
                . '<td>' . esc_html(($at === null ? '' : $p->merchantOf($at, $orders)) . ' · ' . $state) . '</td><td></td></tr>';
        }

        $html .= ($lines === [] && $sourced === [] ? '<tr><td colspan="5">' . esc_html__('Keine Position.', 'taxmod-stock') . '</td></tr>' : '') . '</tbody></table>';

        if ($tab === 'bestellen') {
            $cart  = implode("\n", $p->cartLines($order, $orders, $purchases));
            $html .= '<p><label><strong>' . esc_html__('Warenkorb-Liste', 'taxmod-stock') . '</strong> <span class="description">' . esc_html__('(Art.-Nr. Menge, für die Schnellbestellung des Shops)', 'taxmod-stock') . '</span><br>'
                . '<textarea readonly rows="' . esc_attr((string) max(2, count($lines))) . '" style="width:100%;max-width:480px;font-family:monospace" onclick="this.select()">' . esc_textarea($cart) . '</textarea></label></p>'
                . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->hidden('ordered', $tab) . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
                . '<label>' . esc_html__('Bestellnummer des Händlers', 'taxmod-stock') . ' <input type="text" name="number" class="regular-text"></label> '
                . get_submit_button(__('Als bestellt markieren', 'taxmod-stock'), 'primary', '', false) . '</form>';
        }

        return $html;
    }

    private function quantityForm(int $order, int $purchase, string $quantity, string $tab): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="white-space:nowrap">' . $this->hidden('quantity', $tab)
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . '<input type="number" min="1" step="1" name="quantity" style="width:6em" value="' . esc_attr($quantity) . '"> '
            . get_submit_button('✓', 'small', '', false, ['title' => __('Menge speichern', 'taxmod-stock')]) . '</form>';
    }

    /**
     * The suppliers' offers for one open position of a demand, searched by the material's name (or what he types);
     * «Übernehmen» chooses the offer as the position's source, with its number and the price for the quantity.
     */
    private function offersView(int $demand, int $purchase, Table $orders, Table $purchases): string
    {
        $s        = $this->stock->structure();
        $quantity = max(1, (int) ($purchases->first($purchase, $s->field('purchase_quantity')) ?? 1));
        $label    = (string) $purchases->first($purchase, $s->field('label'));
        $query    = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : $label;
        $back     = self::url(['tab' => 'bedarf', 'order' => $demand]);

        if (! $orders->has($demand) || ! in_array((string) $purchase, $orders->all($demand, $s->field('order_lines')), true)) {
            throw StockError::notInOrder($purchase);
        }

        $answer = apply_filters(self::OFFERS, ['list' => [], 'errors' => []], $query, $quantity);
        $offers = is_array($answer['list'] ?? null) ? $answer['list'] : [];
        $html   = '<p><a href="' . esc_url($back) . '">← ' . esc_html__('Zurück zum Bedarf', 'taxmod-stock') . '</a></p>'
            . '<h2>' . esc_html(sprintf(/* translators: 1: position, 2: quantity */ __('Angebote für %1$s (%2$d Stück)', 'taxmod-stock'), $label, $quantity)) . '</h2>'
            . '<form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="tab" value="angebote">'
            . '<input type="hidden" name="order" value="' . esc_attr((string) $demand) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . '<input type="search" name="q" class="regular-text" value="' . esc_attr($query) . '"> ' . get_submit_button(__('Suchen', 'taxmod-stock'), '', '', false) . '</form>';

        foreach ((array) ($answer['errors'] ?? []) as $merchant => $error) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html($merchant . ': ' . $error) . '</p></div>';
        }

        $rows = '';

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $text  = static fn (string $key): string => trim((string) ($offer[$key] ?? ''));
            $price = $text('price');
            $rows .= '<tr><td>' . esc_html($text('merchant')) . '</td><td>' . ($text('url') === '' ? esc_html($text('number')) : '<a href="' . esc_url($text('url')) . '" target="_blank" rel="noopener">' . esc_html($text('number')) . '</a>') . '</td>'
                . '<td>' . esc_html(trim($text('maker') . ' ' . $text('maker_number'))) . '</td><td>' . esc_html($text('description')) . '</td>'
                . '<td>' . esc_html((string) (int) ($offer['stock'] ?? 0)) . '</td><td>' . esc_html($price === '' ? '—' : $price . ' ' . $text('currency')) . '</td>'
                . '<td>' . esc_html((string) max(1, (int) ($offer['min'] ?? 1))) . ((int) ($offer['mult'] ?? 1) > 1 ? ' / ×' . esc_html((string) (int) $offer['mult']) : '') . '</td>'
                . '<td style="text-align:right"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->hidden('source', 'bedarf')
                . '<input type="hidden" name="order" value="' . esc_attr((string) $demand) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
                . '<input type="hidden" name="merchant" value="' . esc_attr($text('merchant')) . '"><input type="hidden" name="number" value="' . esc_attr($text('number')) . '">'
                . '<input type="hidden" name="price" value="' . esc_attr($price) . '">' . get_submit_button(__('Übernehmen', 'taxmod-stock'), 'small primary', '', false) . '</form></td></tr>';
        }

        return $html . '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>' . esc_html__('Händler', 'taxmod-stock') . '</th><th>' . esc_html__('Art.-Nr.', 'taxmod-stock') . '</th><th>'
            . esc_html__('Hersteller', 'taxmod-stock') . '</th><th>' . esc_html__('Beschreibung', 'taxmod-stock') . '</th><th>' . esc_html__('Lager', 'taxmod-stock') . '</th><th>'
            . esc_html__('Preis/Stück', 'taxmod-stock') . '</th><th>' . esc_html__('Mindestmenge', 'taxmod-stock') . '</th><th></th></tr></thead><tbody>'
            . ($rows === '' ? '<tr><td colspan="8">' . esc_html__('Kein Angebot gefunden.', 'taxmod-stock') . '</td></tr>' : $rows) . '</tbody></table>';
    }

    /** A firm of the register (proposed: the material's first source) or a merchant typed in, its number and a price. */
    private function sourceForm(int $demand, int $purchase, int $thing): string
    {
        $sources = $thing > 0 ? $this->stock->sourcesOf($this->stock->things()->lagerOf($thing)) : [];
        $first   = $sources[0] ?? null;
        $options = '<option value="">' . esc_html__('— Firma —', 'taxmod-stock') . '</option>';

        foreach ($this->stock->firms() as $group => $names) {
            $options .= '<optgroup label="' . esc_attr($group) . '">';

            foreach ($names as $id => $name) {
                $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($first?->firm, $id, false) . '>' . esc_html($name) . '</option>';
            }

            $options .= '</optgroup>';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">' . $this->hidden('source', 'bedarf')
            . '<input type="hidden" name="order" value="' . esc_attr((string) $demand) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . '<select name="firm" style="max-width:12em">' . $options . '</select>'
            . '<input type="text" name="merchant" placeholder="' . esc_attr__('oder Händler', 'taxmod-stock') . '" style="width:8em">'
            . '<input type="text" name="number" placeholder="' . esc_attr__('Art.-Nr.', 'taxmod-stock') . '" style="width:8em" value="' . esc_attr($first->number ?? '') . '">'
            . '<input type="text" inputmode="decimal" name="price" placeholder="' . esc_attr__('€/Stück', 'taxmod-stock') . '" style="width:6em">'
            . get_submit_button(__('Übernehmen', 'taxmod-stock'), 'small', '', false) . '</form>';
    }

    /** @return list<int> purchases that point at this demand and stand in another order now */
    private function sourcedFrom(int $demand, Table $purchases): array
    {
        $field = $this->stock->structure()->field('purchase_demand');

        return array_values(array_filter($purchases->ids(), static fn (int $id): bool => (int) $purchases->first($id, $field) === $demand));
    }

    /** A cancelled order counts as made here when one of its purchases has a demand, or it never had a supplier. */
    private function madeHere(int $order, Table $orders, Table $purchases): bool
    {
        $s = $this->stock->structure();

        if ($this->stock->purchasing()->merchantOf($order, $orders) === '' && trim((string) $orders->first($order, $s->field('label'))) !== '') {
            return true;
        }

        foreach ($orders->all($order, $s->field('order_lines')) as $line) {
            if ((int) $purchases->first((int) $line, $s->field('purchase_demand')) > 0) {
                return true;
            }
        }

        return false;
    }

    private function orderOf(int $purchase, Table $orders): ?int
    {
        $field = $this->stock->structure()->field('order_lines');

        foreach ($orders->ids() as $order) {
            if (in_array((string) $purchase, $orders->all($order, $field), true)) {
                return $order;
            }
        }

        return null;
    }

    /** @return array<int, string> thing → quantity, from the dialog */
    private function quantities(): array
    {
        $out = [];

        foreach ((array) ($_POST['quantity'] ?? []) as $thing => $quantity) {
            if (absint($thing) > 0) {
                $out[absint($thing)] = sanitize_text_field(wp_unslash((string) $quantity));
            }
        }

        return $out;
    }

    /** @param array<string, string> $actions */
    private function bulkBar(string $tab, array $actions, int $count): string
    {
        $bar = ListPage::bulkBar(self::BULK_FORM, self::ACTION, $actions, sprintf(/* translators: %d: entries */ _n('%d Eintrag', '%d Einträge', $count, 'taxmod-stock'), $count));

        // As in the cockpit: admin-post routes on «action», so the choice travels as «bulk».
        return str_replace(
            ['name="action" id="' . self::BULK_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::BULK_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"></form>'],
            $bar
        );
    }

    private function button(string $op, int $order, int $purchase, string $label, string $tab, bool $primary, string $confirm = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"'
            . ($confirm === '' ? '' : ' onsubmit="return confirm(' . esc_attr((string) wp_json_encode($confirm)) . ')"') . '>'
            . $this->hidden($op, $tab) . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . ($purchase > 0 ? '<input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">' : '')
            . '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form> ';
    }

    private function hidden(string $op, string $tab): string
    {
        return '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
    }

    /** @param array<string, string> $choices */
    private function select(array $choices, string $selected): string
    {
        $html = '';

        foreach ($choices as $value => $label) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) $value, $selected, false) . '>' . esc_html($label) . '</option>';
        }

        return $html;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
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

        return '<div class="notice notice-' . esc_attr((string) $notice['type']) . ' is-dismissible"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }
}
