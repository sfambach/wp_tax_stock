<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\Tiles;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Stammdaten › Auswahllisten (D-1474): the model's choice lists (Konstanten, D-1457) kept up in words a person uses —
 * a value added, renamed, merged into another, or deleted when nothing chooses it. Each change is one act of the modeller, and
 * «Rückgängig» takes the last one back.
 *
 * ```mermaid
 * flowchart LR
 *   K["Kacheln: Kategorien"] --> L["Liste: Werte mit «verwendet»"]
 *   L -->|"+ Wert · Umbenennen · Zusammenführen · Löschen"| A["ein Akt des Modellers"] -->|Rückgängig| L
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1474
 */
final class ChoiceListsPage
{
    public const PAGE   = Pages::CHOICES;
    public const ACTION = 'taxmod_stock_choices';

    private const NOTICE    = 'taxmod_stock_choice_notice_';
    private const UNDO      = 'taxmod_stock_choice_undo_';
    private const BULK_FORM = 'taxmod-stock-choices-bulk';

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /** @param array<string, string> $args */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE] + $args), admin_url('admin.php'));
    }

    public function render(): string
    {
        $html = Notice::take(self::NOTICE, true);
        $page = static fn (string $title, string $body): string => ListPage::page($title, [], [['html' => $body]], (string) TAXMOD_STOCK_VERSION);

        if (! $this->stock->canKeepChoiceLists()) {
            return $page(__('Auswahllisten', 'taxmod-stock'), $html . '<div class="notice notice-warning inline"><p>' . esc_html__('Der Taxonomy Modeller ist zu alt für die Pflege der Auswahllisten (ab 0.2.152).', 'taxmod-stock') . '</p></div>');
        }

        try {
            $tree = $this->stock->choiceTree();
            $root = $this->stock->choiceRoot();
            $node = absint($_GET['tab'] ?? 0);
            $node = isset($tree[$node]) ? $node : $root;

            return $page($this->title($tree, $root, $node), $html . ($node === $root ? ListPage::toolbar([[$this->undoButton($node)]]) . $this->tiles($tree, $root) : $this->list($tree, $root, $node)));
        } catch (StockError $e) {
            return $page(__('Auswahllisten', 'taxmod-stock'), $html . '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>');
        }
    }

    /** admin-post: capability → nonce → validate → sanitize → act → back to the list with a notice. */
    public function handle(): void
    {
        if (! current_user_can(\Taxmod\WordPress\Rights::CONFIGURE)) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

        $kind = sanitize_key((string) ($_POST['booking'] ?? ''));
        $list = absint($_POST['liste'] ?? 0);
        $node = absint($_POST['wert'] ?? 0);
        $name = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
        $user = get_current_user_id();

        try {
            $done = match ($kind) {
                'neu'            => ['undo' => ['trash', $this->stock->addChoice($list, $name)], 'text' => sprintf(/* translators: %s: value */ __('«%s» angelegt.', 'taxmod-stock'), $name)],
                'umbenennen'     => ['undo' => ['undo', $this->stock->renameChoice($node, $name)], 'text' => sprintf(/* translators: %s: new name */ __('Umbenannt in «%s».', 'taxmod-stock'), $name)],
                'zusammenfuehren' => ['undo' => ['undo', $this->stock->mergeChoice($node, absint($_POST['in'] ?? 0))], 'text' => __('Zusammengeführt: alle Materialien wählen jetzt den anderen Wert, der doppelte liegt im Papierkorb.', 'taxmod-stock')],
                'loeschen'       => ['undo' => ['undo', $this->stock->trashChoice($node)], 'text' => __('Gelöscht (Papierkorb).', 'taxmod-stock')],
                'zuruecknehmen'  => ['undo' => null, 'text' => $this->takeBack($user)],
                default          => throw StockError::nothingToBook(),
            };

            if ($done['undo'] === null) {
                delete_transient(self::UNDO . $user);
            } else {
                set_transient(self::UNDO . $user, $done['undo'], 15 * MINUTE_IN_SECONDS);
            }

            Notice::remember('success', $done['text'], self::NOTICE);
        } catch (StockError $e) {
            Notice::remember('error', Messages::of($e), self::NOTICE);
        }

        wp_safe_redirect(self::url(['tab' => (string) $list]));
        exit;
    }

    /** D-1474: the last change of this user — an added value goes to the trash again, any other change is undone by its number. */
    private function takeBack(int $user): string
    {
        $last = get_transient(self::UNDO . $user);

        if (! is_array($last) || count($last) !== 2) {
            throw StockError::nothingToBook();
        }

        [$how, $id] = $last;
        $how === 'trash' ? $this->stock->trashChoice((int) $id) : $this->stock->undoChoice((int) $id);

        return __('Zurückgenommen.', 'taxmod-stock');
    }

    /** @param array<int, array{name: string, parent: int, class: string, hidden: bool}> $tree */
    private function title(array $tree, int $root, int $node): string
    {
        $path = [];

        for ($at = $node; $at !== $root && isset($tree[$at]); $at = $tree[$at]['parent']) {
            array_unshift($path, $tree[$at]['name']);
        }

        return implode(' › ', [__('Auswahllisten', 'taxmod-stock'), ...$path]);
    }

    /**
     * The categories below the Konstanten (D-1457: one per module), one tile each with its lists.
     *
     * @param array<int, array{name: string, parent: int, class: string, hidden: bool}> $tree
     */
    private function tiles(array $tree, int $root): string
    {
        $tiles = [];

        foreach ($this->childrenOf($tree, $root) as $child => $name) {
            $tiles[] = Tiles::counted($name, count($this->childrenOf($tree, $child)), self::url(['tab' => (string) $child]));
        }

        return $tiles === [] ? '<p class="description">' . esc_html__('Keine Auswahlliste im Modell.', 'taxmod-stock') . '</p>' : Tiles::grid($tiles);
    }

    /**
     * A node's children as rows: a child with values of its own opens as a list, every row says how often it is chosen.
     *
     * @param array<int, array{name: string, parent: int, class: string, hidden: bool}> $tree
     */
    private function list(array $tree, int $root, int $node): string
    {
        $children = $this->childrenOf($tree, $node);
        $uses     = $this->stock->choiceUses(array_keys($children));
        $up       = $tree[$node]['parent'];
        $count    = sprintf(/* translators: %d: number of values */ _n('%d Wert', '%d Werte', count($children), 'taxmod-stock'), count($children));
        $toolbar  = '<div class="alignleft actions">' . ListPage::toolbar([[$this->newDialog($node, $tree[$node]['name']), $this->undoButton($node)]]) . '</div>';
        $body     = '';

        foreach ($children as $child => $name) {
            $below = count($this->childrenOf($tree, $child));
            $used  = $uses[$child] ?? 0;
            $label = $below > 0 ? '<a href="' . esc_url(self::url(['tab' => (string) $child])) . '"><strong>' . esc_html($name) . '</strong></a>' : esc_html($name);

            $body .= ListPage::row(self::BULK_FORM, (string) $child, [
                $label,
                esc_html($below > 0 ? sprintf(/* translators: %d: number of values */ _n('%d Wert', '%d Werte', $below, 'taxmod-stock'), $below) : '—'),
                esc_html(ListPage::number($used, 0)),
            ], ListPage::toolbar([[$this->renameDialog($node, $child, $name), $this->mergeDialog($node, $child, $name, $children), $this->trashButton($node, $child, $name, $used, $below)]]), '');
        }

        if ($body === '') {
            $body = '<tr class="no-items"><td class="colspanchange" colspan="5">' . esc_html__('Diese Liste hat noch keinen Wert.', 'taxmod-stock') . '</td></tr>';
        }

        // D-1521: the way back stands before the list's name in the one head.
        return ListPage::recordHead(__('Auswahlliste', 'taxmod-stock'), $tree[$node]['name'], self::url(['tab' => $up === $root ? '' : (string) $up]), __('Zurück', 'taxmod-stock'))
            . ListPage::bulkBar(self::BULK_FORM, self::ACTION, [], $count, $toolbar, false)
            . ListPage::table([[__('Wert', 'taxmod-stock'), ''], [__('Darunter', 'taxmod-stock'), '8em'], [__('Verwendet', 'taxmod-stock'), '8em']], $body);
    }

    /**
     * @param array<int, array{name: string, parent: int, class: string, hidden: bool}> $tree
     * @return array<int, string> child id → name, hidden ones left out
     */
    private function childrenOf(array $tree, int $node): array
    {
        $out = [];

        foreach ($tree as $id => $row) {
            if ($row['parent'] === $node && ! $row['hidden']) {
                $out[$id] = $row['name'];
            }
        }

        return $out;
    }

    private function newDialog(int $list, string $name): string
    {
        return $this->formStart($list) . ListPage::dialogAction(
            'taxmod-stock-choice-new',
            __('+ Wert', 'taxmod-stock'),
            /* translators: %s: list */
            sprintf(__('Neuer Wert in «%s»', 'taxmod-stock'), $name),
            '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['required' => true, 'size' => 40]) . '</label>',
            'booking',
            'neu',
            __('Anlegen', 'taxmod-stock')
        ) . '</form>';
    }

    private function renameDialog(int $list, int $value, string $name): string
    {
        return $this->formStart($list, $value) . ListPage::dialogAction(
            'taxmod-stock-choice-rename-' . $value,
            __('Umbenennen', 'taxmod-stock'),
            /* translators: %s: value */
            sprintf(__('«%s» umbenennen', 'taxmod-stock'), $name),
            '<label style="display:block">' . esc_html__('Neuer Name', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', $name, ['required' => true, 'size' => 40]) . '</label>',
            'booking',
            'umbenennen',
            __('Umbenennen', 'taxmod-stock'),
            '',
            'edit'
        ) . '</form>';
    }

    /** @param array<int, string> $siblings */
    private function mergeDialog(int $list, int $value, string $name, array $siblings): string
    {
        $options = '';

        foreach ($siblings as $id => $other) {
            $options .= $id === $value ? '' : '<option value="' . esc_attr((string) $id) . '">' . esc_html($other) . '</option>';
        }

        if ($options === '') {
            return '';
        }

        return $this->formStart($list, $value) . ListPage::dialogAction(
            'taxmod-stock-choice-merge-' . $value,
            __('Zusammenführen …', 'taxmod-stock'),
            /* translators: %s: value */
            sprintf(__('«%s» zusammenführen', 'taxmod-stock'), $name),
            '<label style="display:block">' . esc_html__('in', 'taxmod-stock') . '<br><select name="in" style="width:100%" required>' . $options . '</select></label>'
                . '<p class="description">' . esc_html__('Jedes Material, das diesen Wert gewählt hat, wählt danach den anderen; dieser Wert geht in den Papierkorb. Rückgängig machbar.', 'taxmod-stock') . '</p>',
            'booking',
            'zusammenfuehren',
            __('Zusammenführen', 'taxmod-stock'),
            '',
            'randomize'
        ) . '</form>';
    }

    /** Only a value nothing chooses and with nothing below it may go; otherwise the button says why it is not offered. */
    private function trashButton(int $list, int $value, string $name, int $used, int $below): string
    {
        if ($used > 0 || $below > 0) {
            $why = $used > 0
                /* translators: %d: number of values choosing it */
                ? sprintf(_n('Wird %d-mal gewählt – zuerst zusammenführen.', 'Wird %d-mal gewählt – zuerst zusammenführen.', $used, 'taxmod-stock'), $used)
                : __('Hat Werte darunter.', 'taxmod-stock');

            return '<button type="button" class="button" disabled title="' . esc_attr($why) . '">' . \Taxmod\Core\Renderer\IconMarkup::dashicon('trash') . '</button>';
        }

        return $this->formStart($list, $value)
            /* translators: %s: value */
            . '<button type="submit" class="button" name="booking" value="loeschen" title="' . esc_attr__('Löschen', 'taxmod-stock') . '"' . ListPage::confirm(sprintf(__('«%s» löschen? Er liegt danach im Papierkorb und lässt sich zurücknehmen.', 'taxmod-stock'), $name)) . '>'
            . \Taxmod\Core\Renderer\IconMarkup::dashicon('trash') . '</button></form>';
    }

    /** D-1474: «Rückgängig» for this user's last change on this page, while it is fresh. */
    /** «Letzte Änderung rückgängig» as an icon in the one toolbar, only while this user has a change to take back. */
    private function undoButton(int $node): string
    {
        if (! is_array(get_transient(self::UNDO . get_current_user_id()))) {
            return '';
        }

        $label = __('Letzte Änderung rückgängig', 'taxmod-stock');

        return $this->formStart($node) . '<button type="submit" class="button taxmod-icon-button" name="booking" value="zuruecknehmen" title="' . esc_attr($label) . '">'
            . ListPage::icon('undo', $label) . '</button></form>';
    }

    private function formStart(int $list, int $value = 0): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="liste" value="' . esc_attr((string) $list) . '">'
            . ($value > 0 ? '<input type="hidden" name="wert" value="' . esc_attr((string) $value) . '">' : '')
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
    }
}
