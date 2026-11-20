<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Renderer\GridMarkup;
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
    public const PAGE   = Pages::PURCHASE;
    public const ACTION = 'taxmod_stock_purchase';

    private const TABS      = ['nachbestellen', 'bedarf', 'bestellen', 'bestellt', 'beendet', 'archiviert', 'verworfen'];
    private const BULK_FORM = 'taxmod-stock-purchase-bulk';

    /** D-1147: the source name under which an order's row draws its positions when opened. */

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
        $html = Notice::take();

        try {
            $html .= $this->body();
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        // D-1373: the page in the modeller's template.
        return ListPage::page(__('Einkauf', 'taxmod-stock'), [], [['html' => $html]], (string) TAXMOD_STOCK_VERSION);
    }

    /** admin-post handler: capability → nonce → validate → sanitize → act → back to the tab with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

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
                // His word 2026-11-14 «noch eine Position hinzufügen … einfach ein Material wählen»: the same as «Nachbestellen», into this demand.
                'add'       => $this->stock->reorder([absint($_POST['thing'] ?? 0) ?: throw StockError::nothingToBook() => $text('quantity')], $order, ''),
                'source'    => $this->stock->sourcePosition($order, $purchase, absint($_POST['firm'] ?? 0) ?: null, $text('merchant'), $text('number'), $text('price') === '' ? null : $text('price')),
                'unsource'  => $this->stock->unsourcePosition($order, $purchase),
                'quantity'  => $this->stock->setPositionQuantity($order, $purchase, $text('quantity')),
                'remove'    => $this->stock->removePosition($order, $purchase),
                'ordered'   => $this->stock->markOrdered($order, $text('number')),
                'back'      => $this->stock->orderBackToDraft($order, (string) (($this->inbound->orders()[$order] ?? null)?->receivedOn ?? '')),
                'discard'   => $this->stock->discardOrder($order),
                'restore'   => $this->stock->restoreOrder($order),
                // D-1130: a copy of the order as a new demand.
                'again'     => $this->stock->reorderOrder($order),
                // C5 (D-1320): ticked positions of the demand into another demand or a new one.
                'move'      => $this->stock->moveDemandLines($order, array_values(array_filter(array_map('absint', (array) ($_POST['positions'] ?? [])))), absint($_POST['demand'] ?? 0) ?: null, $text('name')),
                default     => throw StockError::nothingToBook(),
            };
            /* translators: %d: number of changes the modeller applied */
            Notice::remember('success', sprintf(__('Gespeichert: %d Änderungen in einem Schritt.', 'taxmod-stock'), count((array) ($result['applied'] ?? []))));
            $back = match ($op) {
                'reorder', 'again' => ['tab' => 'bedarf'],
                'ordered' => ['tab' => 'bestellt'],
                'source'  => ['bedarf' => $order],
                'unsource', 'restore' => ['tab' => 'bedarf'],
                'back'    => ['tab' => 'bestellen', 'order' => $order],
                'move'    => ['bedarf' => $order],
                'add'     => $tab === 'bestellen' ? ['tab' => 'bestellen', 'order' => $order] : ['bedarf' => $order],
                default   => $back,
            };
        } catch (StockError $e) {
            Notice::remember('error', Messages::of($e));

            if ($op === 'reorder') {
                $back = ['tab' => 'neu', 'things' => implode(',', array_keys($this->quantities()))];
            }

            if ($op === 'move' || ($op === 'add' && $tab !== 'bestellen')) {
                $back = ['bedarf' => $order];
            }
        }

        wp_safe_redirect(self::url($back));
        exit;
    }

    /** One purchase in the modeller's record editor, the way back to «Einkauf» first in its toolbar (D-1316). */
    private function purchaseView(int $purchase): string
    {
        $word   = (string) ($this->stock->recordsOf('purchase', [$purchase])->first($purchase, $this->stock->structure()->field('label')) ?? '#' . $purchase);
        $return = [[ListPage::back(self::url(), __('Zum Einkauf', 'taxmod-stock'))]];
        $form   = (string) apply_filters('taxmod_record_editor', '', $purchase, self::url(['kauf' => (string) $purchase]), $return);

        return '<h2>' . esc_html(sprintf(/* translators: %s: the purchase's word */ __('Kauf – %s', 'taxmod-stock'), $word)) . '</h2>'
            . ($form === '' ? ListPage::toolbar($return) . StockPage::editorMissing() : $form);
    }

    /**
     * C5 (D-1320): one demand on its own page — its head, the way back and «Verschieben nach …» in the toolbar (D-1308,
     * D-1316), its positions below with a tick each; the ticked ones go together into another demand or a new one.
     */
    private function demandView(int $demand, Table $orders, Table $purchases): string
    {
        $s       = $this->stock->structure();
        $p       = $this->stock->purchasing();
        $form    = 'taxmod-stock-demand-move';
        $label   = (string) $orders->first($demand, $s->field('label'));
        $targets = ['' => __('— neuer Bedarf —', 'taxmod-stock')];

        foreach ($orders->ids() as $id) {
            if ($id !== $demand && $p->isDemand($id, $orders)) {
                $targets[(string) $id] = (string) $orders->first($id, $s->field('label')) ?: '#' . $id;
            }
        }

        $body  = '<label style="display:block">' . esc_html__('In den Bedarf', 'taxmod-stock') . '<br><select name="demand" form="' . esc_attr($form) . '" style="width:100%">' . $this->select($targets, '') . '</select></label>'
            . '<label style="display:block;margin-top:.5em">' . esc_html__('Name des neuen Bedarfs', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['form' => $form, 'size' => 40]) . '</label>'
            . '<p class="description">' . esc_html__('Die angehakten Positionen wandern mit ihren Werten; der Name gilt nur für «neuer Bedarf».', 'taxmod-stock') . '</p>';
        $move  = ListPage::dialogAction('taxmod-stock-demand-move-dialog', __('Verschieben nach …', 'taxmod-stock'), __('Positionen verschieben', 'taxmod-stock'), $body, 'op', 'move', __('Verschieben', 'taxmod-stock'), $form);
        $back  = [ListPage::back(self::url(['tab' => 'bedarf']), __('Zum Bedarf', 'taxmod-stock'))];
        $lines = array_map('intval', $orders->all($demand, $s->field('order_lines')));

        // D-1320 ergänzt: a demand that passed on names the orders it went into and offers its copy.
        $into   = $p->passedInto($orders, $purchases)[$demand] ?? [];
        $ended  = $lines === [] && $into !== [];
        $passed = $into === [] ? '' : '<p>' . esc_html__('Übergegangen in:', 'taxmod-stock') . ' ' . implode(', ', array_map(fn (int $o): string => '<a href="' . esc_url($this->orderLink($o, $orders)) . '">'
            . esc_html((string) $orders->first($o, $s->field('label')) ?: '#' . $o) . '</a>', $into)) . '</p>';

        return '<h2>' . esc_html(sprintf(/* translators: %s: the demand's name */ __('Bedarf – %s', 'taxmod-stock'), $label === '' ? '#' . $demand : $label)) . '</h2>' . $passed
            . '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->hidden('move', 'bedarf')
            . '<input type="hidden" name="order" value="' . esc_attr((string) $demand) . '"></form>'
            . ListPage::toolbar([...($back === [] ? [] : [$back]), [...($ended ? [$this->button('again', $demand, 0, __('Als neuen Bedarf kopieren', 'taxmod-stock'), 'beendet', true)] : []), $this->addDialog($demand, 'bedarf'), $move]])
            . $this->details('bedarf', $demand, $lines, $this->sourcedFrom($demand, $purchases), $orders, $purchases, $form);
    }

    private function body(): string
    {
        $s         = $this->stock->structure();
        $p         = $this->stock->purchasing();
        $orders    = $this->stock->table('order');
        $purchases = $this->stock->table('purchase');
        $tab       = sanitize_key((string) ($_GET['tab'] ?? self::TABS[0]));
        $open      = absint($_GET['order'] ?? 0);
        $single    = absint($_GET['kauf'] ?? 0);

        $demand = absint($_GET['bedarf'] ?? 0);

        // C5 (D-1320): a demand on a page of its own.
        if ($demand > 0 && $orders->has($demand) && $p->isDemand($demand, $orders)) {
            return $this->demandView($demand, $orders, $purchases);
        }

        // His wish 2026-11-11: a purchase without an order opens here, not in the modeller.
        if ($single > 0 && $this->stock->recordsOf('purchase', [$single])->has($single)) {
            return $this->purchaseView($single);
        }

        if (! $s->hasValue('status_draft') || ! $s->hasValue('status_ordered') || ! $s->hasField('purchase_demand') || ! $s->hasField('order_merchant')) {
            throw StockError::notConfigured(! $s->hasField('purchase_demand') ? 'fields.purchase_demand' : 'values.status_ordered');
        }

        // D-1183 for Einkauf, his word 2026-11-14 «ausserdem ist hier wieder die aufklapp logik»: no row opens; a draft,
        // an order or a dropped one is a page of its own with the way back into its tab.
        if ($open > 0 && $orders->has($open) && in_array($tab, ['bestellen', 'bestellt', 'verworfen'], true)) {
            return $this->orderView($tab, $open, $orders, $purchases);
        }

        $groups = ['bedarf' => [], 'bestellen' => [], 'bestellt' => [], 'beendet' => [], 'archiviert' => [], 'verworfen' => []];

        $brackets = $p->brackets($purchases);
        $into     = $p->passedInto($orders, $purchases);
        $placed   = array_filter(array_map(static fn ($o): string => (string) $o->orderedOn, $this->inbound->orders()));
        $days     = $this->stock->demandArchiveDays();
        $today    = current_time('Y-m-d');

        foreach ($orders->ids() as $id) {
            $group = match (true) {
                ($end = $p->endState($id, $orders, $brackets, $into, $placed, $today, $days)) !== '' => $end,
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
        // D-1500 (Prüftor 20.11. a): the purchasing stages as process arrows like the goods receipt (D-1197) — demand, draft,
        // ordered, ended, archived; «below minimum» (feeds the demand) and «dropped» stand beside the process as tabs.
        $bar     = ListPage::steps([
            ['bedarf', __('Bedarf', 'taxmod-stock'), count($groups['bedarf'])],
            ['bestellen', __('Bestellen', 'taxmod-stock'), count($groups['bestellen'])],
            ['bestellt', __('Bestellt', 'taxmod-stock'), count($groups['bestellt'])],
            ['beendet', __('Beendet', 'taxmod-stock'), count($groups['beendet'])],
            ['archiviert', __('Archiviert', 'taxmod-stock'), count($groups['archiviert'])],
        ], match ($tab) { 'neu' => 'nachbestellen', 'angebote' => 'bedarf', default => $tab }, static fn (string $slug): string => self::url(['tab' => $slug]),
            [['nachbestellen', __('Mindestbestand unterschritten', 'taxmod-stock'), count($reorder)], ['verworfen', __('Verworfen', 'taxmod-stock'), count($groups['verworfen'])]]);

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
            'beendet'   => __('Bedarfe, die ganz in Bestellungen übergegangen sind. «Als neuen Bedarf kopieren» legt dieselben Materialien als neuen Bedarf an.', 'taxmod-stock'),
            'archiviert' => __('Beendete Bedarfe, deren letzte Bestellung länger zurückliegt als in den Einstellungen festgelegt.', 'taxmod-stock'),
            default     => __('Verworfene Bedarfe und Entwürfe; «Wiederherstellen» macht sie wieder zum Entwurf.', 'taxmod-stock'),
        };

        $count = count($groups[$tab]);

        return $bar . '<p class="description">' . esc_html($hint) . '</p>'
            // D-1500: the one bar over every list.
            . ListPage::listBar(sprintf(/* translators: %d: entries */ _n('%d Eintrag', '%d Einträge', $count, 'taxmod-stock'), $count), '', '', true)
            . ListPage::table($columns, $rows);
    }

    /**
     * The things under their minimum, counting what is already wanted or ordered. D-1088: «Nachbestellen» counts the
     * available stock — stock less what is reserved — not the stock.
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
        $held   = $this->stock->reservedByThing();
        $out    = [];

        foreach ($lager->ids() as $record) {
            $thing   = (int) $lager->first($record, $s->field('lager_thing'));
            $minimum = (float) ($lager->first($record, $s->field('lager_min')) ?? 0);

            if ($thing <= 0 || $minimum <= 0.0 || $things->lagerOf($thing) !== $record) {
                continue;
            }

            $stock = $ledger->stockOf($thing) - ($held[$thing] ?? 0.0);
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
        $html = '<p class="description">' . esc_html__('Material unter dem Mindestbestand (verfügbarer Bestand plus schon gewünscht oder bestellt). Auswählen und «In Bedarf übernehmen».', 'taxmod-stock') . '</p>'
            . $this->bulkBar('nachbestellen', ['pick' => __('In Bedarf übernehmen', 'taxmod-stock')], count($rows));
        $body = '';

        foreach ($rows as $thing => $row) {
            $body .= ListPage::row(self::BULK_FORM, (string) $thing, [
                '<a href="' . esc_url(StockPage::thingUrl($thing, 'bestand')) . '">' . esc_html($row['name']) . '</a>',
                esc_html($this->number($row['stock'])),
                esc_html($this->number($row['minimum'])),
                esc_html($this->number($row['open'])),
            ], ListPage::iconButton('cart', __('Nachbestellen', 'taxmod-stock'), self::reorderUrl($thing)), '');
        }

        $body = $body !== '' ? $body : '<tr class="no-items"><td colspan="6">' . esc_html__('Alles über dem Mindestbestand.', 'taxmod-stock') . '</td></tr>';

        return $html . ListPage::table([[__('Material', 'taxmod-stock'), ''], [__('Verfügbar', 'taxmod-stock'), '7em'], [__('Mindestbestand', 'taxmod-stock'), '9em'], [__('Unterwegs / gewünscht', 'taxmod-stock'), '11em']], $body);
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
        $held    = $this->stock->reservedByThing();
        $units   = $this->stock->unitWords($things);
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
            // D-1308: the acts in the toolbar above the form.
            . ListPage::toolbar([[get_submit_button(__('Hinzufügen', 'taxmod-stock'), 'primary', '', false)], ['<a class="button" href="' . esc_url(self::url(['tab' => 'nachbestellen'])) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a>']]);
        $rows = [];

        foreach ($things as $thing) {
            $record  = $this->stock->things()->lagerOf($thing);
            $minimum = $record === null ? null : $lager->first($record, $s->field('lager_min'));
            $stock   = $ledger->stockOf($thing) - ($held[$thing] ?? 0.0);
            $come    = $open[$thing] ?? 0.0;
            $rows[]  = [esc_html(($labels[$thing] ?? '') . ' (Nr. ' . $thing . ')'), esc_html($this->number($stock)), esc_html($minimum === null ? '—' : $minimum), esc_html($this->number($come)),
                // D-1436/D-1500: the quantity with the unit the Lager counts this material in.
                ListPage::quantity('quantity[' . $thing . ']', $units[$thing] ?? '', (string) Purchasing::proposedQuantity($minimum === null ? null : (float) $minimum, $stock, $come))];
        }

        $html .= ListPage::grid([[__('Material', 'taxmod-stock')], [__('Verfügbar', 'taxmod-stock'), GridMarkup::NUMBER], [__('Mindestbestand', 'taxmod-stock'), GridMarkup::NUMBER],
            [__('Unterwegs / gewünscht', 'taxmod-stock'), GridMarkup::NUMBER], [__('Menge', 'taxmod-stock')]], $rows);

        return $html . '<p><label>' . esc_html__('In Bedarf', 'taxmod-stock') . ' <select name="demand">' . $this->select($choices, (string) ($demands === [] ? '0' : end($demands))) . '</select></label> '
            . '<label>' . esc_html__('Name des neuen Bedarfs', 'taxmod-stock') . ' ' . ListPage::field(InputKind::Line, 'name', '', ['size' => 40, 'placeholder' => __('z. B. Projekt oder «Lager auffüllen»', 'taxmod-stock')]) . '</label></p></form>';
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
        // D-1320, his word 2026-11-14 «von bedarf muss man doch auf bestellen und nicht auf nochmal bestellen»: a copy only of
        // what was ordered or dropped; a demand's own step is «Bestellen», which opens its choice of merchant.
        $buttons  = $this->rowButtons($tab, $order);

        return ListPage::row(self::BULK_FORM, (string) $order, [
            '<a href="' . esc_url(self::url(in_array($tab, ['bedarf', 'beendet', 'archiviert'], true) ? ['bedarf' => $order] : ['tab' => $tab, 'order' => $order])) . '"><strong>' . esc_html($label === '' ? '#' . $order : $label) . '</strong></a> <span class="description">#' . esc_html((string) $order) . ($number === '' ? '' : ' · ' . esc_html($number)) . '</span>',
            esc_html($merchant === '' ? '—' : $merchant),
            esc_html($count),
        ], $buttons, '', false);
    }

    /** Where an order a demand went into opens: its draft or its placed order here, else its view in the goods receipt. */
    private function orderLink(int $order, Table $orders): string
    {
        $p = $this->stock->purchasing();

        return match (true) {
            $p->isSupplierDraft($order, $orders) => self::url(['tab' => 'bestellen', 'order' => $order]),
            $p->isOrdered($order, $orders)       => self::url(['tab' => 'bestellt', 'order' => $order]),
            default                              => InboundPage::orderUrl($order, ''),
        };
    }

    /**
     * His word 2026-11-14 «noch eine Position hinzufügen … einfach ein Material wählen»: a material and its quantity into this
     * demand or draft — the same way as «Nachbestellen». The dialog sits inside its form, so the chooser's choice goes along.
     */
    private function addDialog(int $order, string $tab): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . $this->hidden('add', $tab)
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . ListPage::dialogAction('taxmod-stock-add-' . $order, __('Position hinzufügen', 'taxmod-stock'), __('Position hinzufügen', 'taxmod-stock'),
                '<span style="display:block">' . esc_html__('Material', 'taxmod-stock') . '</span>' . (new StockPage($this->stock))->materialPicker('thing')
                . '<label style="display:block;margin-top:.5em">' . esc_html__('Menge', 'taxmod-stock') . '<br>' . ListPage::quantity('quantity', '') . '</label>',
                'op', 'add', __('Hinzufügen', 'taxmod-stock'))
            . '</form>';
    }

    /** The buttons of an order in its tab — in its row and on its own page alike. */
    private function rowButtons(string $tab, int $order): string
    {
        $again    = in_array($tab, ['beendet', 'archiviert'], true) ? $this->button('again', $order, 0, __('Als neuen Bedarf kopieren', 'taxmod-stock'), $tab, true, row: true)
            : (in_array($tab, ['bestellt', 'verworfen'], true) ? $this->button('again', $order, 0, __('Nochmal bestellen', 'taxmod-stock'), $tab, false, row: true)
            : ($tab === 'bedarf' ? ListPage::iconButton('cart', __('Bestellen', 'taxmod-stock'), self::url(['bedarf' => $order])) . ' ' : ''));
        return $again . match ($tab) {
            'bedarf', 'bestellen' => $this->button('discard', $order, 0, __('Verwerfen', 'taxmod-stock'), $tab, false, __('Verwerfen? Er wird storniert, nicht gelöscht, und lässt sich unter «Verworfen» wiederherstellen.', 'taxmod-stock'), true),
            'beendet', 'archiviert' => '',
            'bestellt'  => $this->button('back', $order, 0, __('Zurück zu Entwurf', 'taxmod-stock'), $tab, false, row: true)
                . ListPage::iconButton('download', __('Wareneingang', 'taxmod-stock'), InboundPage::url(['tab' => 'ordered'])),
            default     => $this->button('restore', $order, 0, __('Wiederherstellen', 'taxmod-stock'), $tab, true, row: true),
        };
    }

    /** D-1183 for Einkauf: a draft, an order or a dropped one on a page of its own — its buttons, positions and the way back. */
    private function orderView(string $tab, int $order, Table $orders, Table $purchases): string
    {
        $s     = $this->stock->structure();
        $label = (string) $orders->first($order, $s->field('label'));
        $names = ['bestellen' => __('Bestellen', 'taxmod-stock'), 'bestellt' => __('Bestellt', 'taxmod-stock'), 'verworfen' => __('Verworfen', 'taxmod-stock')];
        $back  = [ListPage::back(self::url(['tab' => $tab]), sprintf(/* translators: %s: tab */ __('Zurück zu «%s»', 'taxmod-stock'), $names[$tab]))];
        $lines = array_map('intval', $orders->all($order, $s->field('order_lines')));

        return '<h2>' . esc_html($label === '' ? '#' . $order : $label) . ' <span class="description">#' . esc_html((string) $order) . '</span></h2>'
            . ListPage::toolbar([...($back === [] ? [] : [$back]), [...($tab === 'bestellen' ? [$this->addDialog($order, $tab)] : []), $this->rowButtons($tab, $order)]])
            . $this->details($tab, $order, $lines, [], $orders, $purchases);
    }

    /**
     * @param list<int> $lines
     * @param list<int> $sourced positions of this demand that went to a supplier
     */
    private function details(string $tab, int $order, array $lines, array $sourced, Table $orders, Table $purchases, string $pick = ''): string
    {
        $s    = $this->stock->structure();
        $p    = $this->stock->purchasing();
        $edit = in_array($tab, ['bedarf', 'bestellen'], true);
        // C5: on the demand's own page each position carries a tick for «Verschieben nach …», in that form.
        $tick = static fn (int $line): string => $pick === '' ? '' : '<td>' . ListPage::field(InputKind::Tick, 'positions[]', (string) $line, ['form' => $pick]) . '</td>';
        $html = '<table class="widefat striped" style="margin-top:8px"><thead><tr>' . ($pick === '' ? '' : '<td class="check-column"></td>') . '<th>' . esc_html__('Position', 'taxmod-stock') . '</th><th>' . esc_html__('Art.-Nr.', 'taxmod-stock') . '</th><th>'
            . esc_html__('Menge', 'taxmod-stock') . '</th><th>' . ($tab === 'bedarf' ? esc_html__('Bezugsquelle', 'taxmod-stock') : '') . '</th><th></th></tr></thead><tbody>';

        foreach ($lines as $line) {
            $thing  = (int) $purchases->first($line, $s->field('purchase_model'));
            $number = $s->hasField('purchase_number') ? (string) $purchases->first($line, $s->field('purchase_number')) : '';
            $html  .= '<tr>' . $tick($line) . '<td>' . ($thing > 0 ? '<a href="' . esc_url(StockPage::thingUrl($thing, 'einkauf')) . '">' . esc_html((string) $purchases->first($line, $s->field('label'))) . '</a>' : esc_html((string) $purchases->first($line, $s->field('label'))))
                . ' <span class="description">#' . esc_html((string) $line) . '</span></td><td>' . esc_html($number === '' ? '—' : $number) . '</td>'
                . '<td>' . ($edit ? $this->quantityForm($order, $line, (string) ($purchases->first($line, $s->field('purchase_quantity')) ?? '1'), $tab) : esc_html((string) ($purchases->first($line, $s->field('purchase_quantity')) ?? '—'))) . '</td>'
                . '<td>' . ($tab === 'bedarf' ? $this->sourceForm($order, $line, $thing) : '') . '</td><td style="text-align:right;white-space:nowrap">'
                . ($tab === 'bedarf' && has_filter(self::OFFERS) ? ListPage::iconButton('search', __('Angebote', 'taxmod-stock'), self::url(['tab' => 'angebote', 'order' => $order, 'purchase' => $line])) . ' ' : '')
                . ($tab === 'bestellen' && (int) $purchases->first($line, $s->field('purchase_demand')) > 0 ? $this->button('unsource', $order, $line, __('Zurück in Bedarf', 'taxmod-stock'), $tab, false, row: true) : '')
                . ($edit ? $this->button('remove', $order, $line, __('Entfernen', 'taxmod-stock'), $tab, false, __('Position entfernen? «Nachbestellen» legt sie wieder an.', 'taxmod-stock'), true) : '') . '</td></tr>';
        }

        foreach ($sourced as $line) {
            $at    = $this->orderOf($line, $orders);
            $state = $at === null ? '' : ($p->isSupplierDraft($at, $orders) ? __('im Entwurf', 'taxmod-stock') : ($p->isOrdered($at, $orders) ? __('bestellt', 'taxmod-stock') : __('eingegangen oder storniert', 'taxmod-stock')));
            $html .= '<tr style="opacity:.75">' . ($pick === '' ? '' : '<td></td>') . '<td>' . esc_html((string) $purchases->first($line, $s->field('label'))) . ' <span class="description">#' . esc_html((string) $line) . '</span></td>'
                . '<td>' . esc_html($s->hasField('purchase_number') ? (string) $purchases->first($line, $s->field('purchase_number')) : '') . '</td>'
                . '<td>' . esc_html((string) ($purchases->first($line, $s->field('purchase_quantity')) ?? '')) . '</td>'
                . '<td>' . esc_html(($at === null ? '' : $p->merchantOf($at, $orders)) . ' · ' . $state) . '</td><td></td></tr>';
        }

        $html .= ($lines === [] && $sourced === [] ? '<tr><td colspan="5">' . esc_html__('Keine Position.', 'taxmod-stock') . '</td></tr>' : '') . '</tbody></table>';

        if ($tab === 'bestellen') {
            $cart  = implode("\n", $p->cartLines($order, $orders, $purchases));
            $html .= '<p><label><strong>' . esc_html__('Warenkorb-Liste', 'taxmod-stock') . '</strong> <span class="description">' . esc_html__('(Art.-Nr. Menge, für die Schnellbestellung des Shops)', 'taxmod-stock') . '</span><br>'
                . '<textarea readonly rows="' . esc_attr((string) max(2, count($lines))) . '" style="width:100%;max-width:480px;font-family:monospace">' . esc_textarea($cart) . '</textarea></label></p>'
                . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->hidden('ordered', $tab) . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
                . '<label>' . esc_html__('Bestellnummer des Händlers', 'taxmod-stock') . ' ' . ListPage::field(InputKind::Line, 'number', '', ['size' => 30]) . '</label> '
                . get_submit_button(__('Als bestellt markieren', 'taxmod-stock'), 'primary', '', false) . '</form>';
        }

        return $html;
    }

    private function quantityForm(int $order, int $purchase, string $quantity, string $tab): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="white-space:nowrap">' . $this->hidden('quantity', $tab)
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . ListPage::field(InputKind::Number, 'quantity', $quantity, ['min' => '1', 'step' => '1']) . ' '
            . StockPage::saveButton(__('Menge speichern', 'taxmod-stock')) . '</form>';
    }

    /**
     * The suppliers' offers for one open position of a demand, searched by the material's name (or what he types);
     * «Bestellen bei …» chooses the offer as the position's source, with its number and the price for the quantity.
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

        $rows = [];

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $text  = static fn (string $key): string => trim((string) ($offer[$key] ?? ''));
            $price = $text('price');
            $rows[] = [esc_html($text('merchant')), $text('url') === '' ? esc_html($text('number')) : '<a href="' . esc_url($text('url')) . '" target="_blank" rel="noopener">' . esc_html($text('number')) . '</a>',
                esc_html(trim($text('maker') . ' ' . $text('maker_number'))), esc_html($text('description')),
                esc_html((string) (int) ($offer['stock'] ?? 0)), esc_html($price === '' ? '—' : $price . ' ' . $text('currency')),
                esc_html((string) max(1, (int) ($offer['min'] ?? 1))) . ((int) ($offer['mult'] ?? 1) > 1 ? ' / ×' . esc_html((string) (int) $offer['mult']) : ''),
                '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $this->hidden('source', 'bedarf')
                . '<input type="hidden" name="order" value="' . esc_attr((string) $demand) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
                . '<input type="hidden" name="merchant" value="' . esc_attr($text('merchant')) . '"><input type="hidden" name="number" value="' . esc_attr($text('number')) . '">'
                . '<input type="hidden" name="price" value="' . esc_attr($price) . '">' . get_submit_button(sprintf(/* translators: %s: merchant */ __('Bestellen bei %s', 'taxmod-stock'), $text('merchant')), 'small primary', '', false) . '</form>'];
        }

        return $html . ListPage::grid([[__('Händler', 'taxmod-stock')], [__('Art.-Nr.', 'taxmod-stock')], [__('Hersteller', 'taxmod-stock')], [__('Beschreibung', 'taxmod-stock')],
            [__('Lager', 'taxmod-stock'), GridMarkup::NUMBER], [__('Preis/Stück', 'taxmod-stock'), GridMarkup::NUMBER], [__('Mindestmenge', 'taxmod-stock'), GridMarkup::NUMBER], ['']],
            $rows, __('Kein Angebot gefunden.', 'taxmod-stock'));
    }

    /** A firm of the register (proposed: the material's first source) or a merchant typed in, its number and a price. */
    private function sourceForm(int $demand, int $purchase, int $thing): string
    {
        $sources = $thing > 0 ? $this->stock->sourcesOf($this->stock->things()->lagerOf($thing)) : [];
        $first   = $sources[0] ?? null;
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">' . $this->hidden('source', 'bedarf')
            . '<input type="hidden" name="order" value="' . esc_attr((string) $demand) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . FirmChoice::field('firm', $this->stock->firmTree(), $first?->firm)
            . ListPage::field(InputKind::Line, 'merchant', '', ['size' => 10, 'placeholder' => __('oder Händler', 'taxmod-stock')])
            . ListPage::field(InputKind::Line, 'number', (string) ($first->number ?? ''), ['size' => 10, 'placeholder' => __('Art.-Nr.', 'taxmod-stock')])
            . ListPage::field(InputKind::Decimal, 'price', '', ['size' => 6, 'placeholder' => __('€/Stück', 'taxmod-stock')])
            . get_submit_button(__('Bestellen bei …', 'taxmod-stock'), 'small', '', false) . '</form>';
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

    /** D-1500: a row's action is an icon square (`$row`), a toolbar's a text button. */
    private function button(string $op, int $order, int $purchase, string $label, string $tab, bool $primary, string $confirm = '', bool $row = false): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"'
            . ($confirm === '' ? '' : ListPage::confirm($confirm)) . '>'
            . $this->hidden($op, $tab) . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . ($purchase > 0 ? '<input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">' : '')
            . ($row ? ListPage::iconButton(self::ICONS[$op] ?? 'admin-generic', $label) : ListPage::button($label, '', $primary)) . '</form> ';
    }

    /** D-1500: the icon of each row action. */
    private const ICONS = [
        'again'    => 'controls-repeat',
        'discard'  => 'dismiss',
        'back'     => 'undo',
        'restore'  => 'undo',
        'unsource' => 'undo',
        'remove'   => 'trash',
    ];

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
        return ListPage::number($value);
    }


}
