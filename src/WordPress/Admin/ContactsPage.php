<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Listing\ListView;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\Tiles;
use TaxmodStock\Core\Contacts;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Kontakte (D-1184, D-1185): the contact branch of the model — firms, suppliers, manufacturers, persons. Its nodes are the
 * items of the area in the navigation; the area itself lists all contacts, each node its own and those below it, with a tile per node
 * directly below; a contact opens in the modeller's record editor.
 *
 * ```mermaid
 * flowchart LR
 *   T["Menü: Knoten wählen"] --> K["Kacheln: Unterknoten mit Anzahl"] --> L["Liste: Kontakte des Knotens und darunter"]
 *   L -->|Bearbeiten| E["Satz-Editor des Modellers"]
 *   L -->|"+ Neuer Kontakt"| N["Name → anlegen"] -->|Rückgängig| L
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1184
 */
final class ContactsPage
{
    public const PAGE    = Pages::CONTACTS;
    public const ACTION  = 'taxmod_stock_contacts';
    public const DETAILS = 'stock-contact';

    private const NOTICE    = 'taxmod_stock_contact_notice_';
    private const UNDO      = 'taxmod_stock_contact_undo_';
    private const BULK_FORM = 'taxmod-stock-contacts-bulk';
    private const SEARCH    = 'taxmod-stock-contacts-search';
    private const PER_PAGE  = 50;

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /** @param array<string, string> $args */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE] + $args), admin_url('admin.php'));
    }

    /** @return array{side: string, main: string} */
    public function render(): array
    {
        $html = '<div class="wrap">' . ListPage::heading(__('Kontakte', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice();

        try {
            $tree = $this->stock->contactTree();
            $root = $this->stock->contactRoot();

            if ($root === 0 || $tree === []) {
                return ['side' => '', 'main' => $html . '<p class="description">' . esc_html__('Das Modell kennt keinen Kontakt-Ast (nodes.contact in der Lager-Struktur).', 'taxmod-stock') . '</p></div>'];
            }

            $node    = absint($_GET['tab'] ?? 0);
            $node    = isset($tree[$node]) ? $node : $root;
            $contact = absint($_GET['kontakt'] ?? 0);

            return ['side' => '', 'main' => $html . ($contact > 0 ? $this->editor($contact, $node) : $this->list($tree, $root, $node)) . '</div>'];
        } catch (StockError $e) {
            return ['side' => '', 'main' => $html . '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div></div>'];
        }
    }

    /** admin-post: capability → nonce → validate → sanitize → act → back to the list with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

        $kind = sanitize_key((string) ($_POST['booking'] ?? ''));
        $node = absint($_POST['gruppe'] ?? 0);
        $user = get_current_user_id();
        $back = ['tab' => (string) $node];

        try {
            if ($kind === 'neu') {
                $created = $this->stock->createContact(
                    $node,
                    sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
                    sanitize_text_field(wp_unslash((string) ($_POST['first_name'] ?? '')))
                );
                set_transient(self::UNDO . $user, $created, 15 * MINUTE_IN_SECONDS);
                $this->remember('success', __('Kontakt angelegt; die weiteren Felder stehen jetzt zum Ausfüllen da.', 'taxmod-stock'));
                $back['kontakt'] = (string) $created;
            } elseif ($kind === 'zuruecknehmen') {
                $record = absint($_POST['kontakt'] ?? 0);

                // D-1010: only the step just taken is taken back — the contact this user created, before anything could point at it.
                if ($record === 0 || (int) get_transient(self::UNDO . $user) !== $record) {
                    throw StockError::contactNotUndoable($record);
                }

                $this->stock->removeContact($record);
                delete_transient(self::UNDO . $user);
                $this->remember('success', __('Zurückgenommen: der neue Kontakt ist wieder entfernt.', 'taxmod-stock'));
            } else {
                throw StockError::nothingToBook();
            }
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
        }

        wp_safe_redirect(self::url($back));
        exit;
    }

    /**
     * D-1147: a contact row's materials — those whose «Bezugsquellen» name it — drawn when the row opens.
     *
     * @param array<string, string> $args
     */
    public function listDetails(?string $html, string $source, array $args): ?string
    {
        if ($source !== self::DETAILS || ! current_user_can('manage_options')) {
            return $html;
        }

        $things = $this->stock->suppliedBy(absint($args['contact'] ?? 0));

        if ($things === []) {
            return '<p class="description">' . esc_html__('Keine Bezugsquelle im Lager nennt diesen Kontakt.', 'taxmod-stock') . '</p>';
        }

        $items = '';

        foreach ($things as $thing => $name) {
            $items .= '<li><a href="' . esc_url(LogisticsShell::withOrigin(add_query_arg(['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $thing], admin_url('admin.php')), __('Kontakte', 'taxmod-stock'))) . '">'
                . esc_html($name === '' ? '#' . $thing : $name) . '</a></li>';
        }

        return '<p><strong>' . esc_html__('Bezugsquelle für', 'taxmod-stock') . '</strong></p><ul style="margin:0 0 0 1.5em;list-style:disc">' . $items . '</ul>';
    }

    /** @param array<int, array{name: string, parent: int, class: string, hidden: bool}> $tree */
    private function list(array $tree, int $root, int $node): string
    {
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $all    = $this->stock->contacts($node);
        $rows   = Contacts::filter($all, $search);
        $paged  = absint($_GET['paged'] ?? 1);
        $url    = static fn (array $change): string => self::url($change + ['tab' => (string) $node, 's' => $search]);
        $count  = sprintf(/* translators: %d: number of contacts */ _n('%d Kontakt', '%d Kontakte', count($rows), 'taxmod-stock'), count($rows));
        $html   = '<h2>' . esc_html($node === $root ? __('Alle Kontakte', 'taxmod-stock') : $tree[$node]['name']) . '</h2>'
            . $this->tiles($tree, $node, $all)
            . ($node === $root ? '' : '<div style="text-align:right;margin-bottom:12px">' . $this->newDialog($node, $tree[$node]['name']) . '</div>')
            . ListPage::searchBox(self::SEARCH, $search, __('Name, Gruppe, Nummer …', 'taxmod-stock'), ['page' => self::PAGE, 'tab' => (string) $node])
            . ListPage::bulkBar(self::BULK_FORM, self::ACTION, [], $count);
        $body   = '';

        foreach (ListView::page($rows, $paged, self::PER_PAGE) as $row) {
            $edit  = esc_url(self::url(['tab' => (string) $node, 'kontakt' => (string) $row['id']]));
            $body .= ListPage::row(self::BULK_FORM, (string) $row['id'], [
                '<a href="' . $edit . '">' . esc_html((string) $row['id']) . '</a>',
                '<strong><a href="' . $edit . '">' . esc_html($row['name']) . '</a></strong>',
                esc_html($row['group'] === '' ? '—' : $row['group']),
                esc_html((string) $row['sources']),
            ], '<a class="button button-small" href="' . $edit . '">' . esc_html__('Bearbeiten', 'taxmod-stock') . '</a>', ListPage::lazyDetails(self::DETAILS, ['contact' => (string) $row['id']]));
        }

        if ($body === '') {
            $body = '<tr class="no-items"><td class="colspanchange" colspan="6">' . esc_html__('Kein Kontakt in dieser Gruppe.', 'taxmod-stock') . '</td></tr>';
        }

        return $html . ListPage::table([[__('Nr.', 'taxmod-stock'), '6em'], [__('Name', 'taxmod-stock'), ''], [__('Gruppe', 'taxmod-stock'), '16em'], [__('Bezugsquellen', 'taxmod-stock'), '9em']], $body)
            . ListPage::pagination(count($rows), $paged, self::PER_PAGE, $url);
    }

    /**
     * D-1185, his word 2026-11-06: «der kontakte knoten selbst ist alle kontakte und zeigt die tiles oben it den unterknoten und anzahl
     * einträge» — one tile per node directly below, with its contacts and those below it; a click opens it.
     *
     * @param array<int, array{name: string, parent: int, class: string, hidden: bool}> $tree
     * @param list<array{id: int, name: string, node: int, group: string, sources: int}> $rows
     */
    private function tiles(array $tree, int $node, array $rows): string
    {
        $children = Contacts::childrenOf($tree, $node);

        if ($children === []) {
            return '';
        }

        $counts = Contacts::counts($tree, $rows);
        $tiles  = [];

        foreach ($children as $child => $name) {
            $tiles[] = Tiles::counted($name, $counts[$child] ?? 0, self::url(['tab' => (string) $child]));
        }

        return Tiles::grid($tiles);
    }

    /** D-1025: the contact in the modeller's record editor; saving comes back here. */
    private function editor(int $contact, int $node): string
    {
        $back = self::url(['tab' => (string) $node, 'kontakt' => (string) $contact]);
        $form = (string) apply_filters('taxmod_record_editor', '', $contact, $back);

        return '<p><a href="' . esc_url(self::url(['tab' => (string) $node])) . '">' . esc_html__('← zur Liste', 'taxmod-stock') . '</a></p>' . $this->undoForm($contact, $node)
            . ($form === '' ? '<p><a class="button" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $contact], admin_url('admin.php'))) . '">'
                . esc_html__('Im Taxonomy Modeller bearbeiten', 'taxmod-stock') . '</a></p>' : $form);
    }

    /** «+ Neuer Kontakt» in the chosen node: the name (and a person's first name) only. */
    private function newDialog(int $node, string $group): string
    {
        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br><input type="text" name="name" style="width:100%" required></label>'
            . ($this->stock->hasContactFirstName($node) ? '<label style="display:block;margin-top:8px">' . esc_html__('Vorname', 'taxmod-stock') . '<br><input type="text" name="first_name" style="width:100%"></label>' : '');

        return $this->formStart($node) . ListPage::dialogAction(
            'taxmod-stock-new-contact',
            __('+ Neuer Kontakt', 'taxmod-stock'),
            /* translators: %s: name of the contact group */
            sprintf(__('Neuer Kontakt in «%s»', 'taxmod-stock'), $group),
            $body,
            'booking',
            'neu',
            __('Anlegen', 'taxmod-stock')
        ) . '</form>';
    }

    /** D-1010: right after «Anlegen» the step can be taken back; it says what goes with it. */
    private function undoForm(int $contact, int $node): string
    {
        if ((int) get_transient(self::UNDO . get_current_user_id()) !== $contact) {
            return '';
        }

        return $this->formStart($node) . '<input type="hidden" name="kontakt" value="' . esc_attr((string) $contact) . '">'
            . '<p style="text-align:right"><button type="submit" class="button" name="booking" value="zuruecknehmen"' . ListPage::confirm(__('Den eben angelegten Kontakt mit allen eingetragenen Werten wieder entfernen?', 'taxmod-stock')) . '>'
            . esc_html__('Anlegen zurücknehmen', 'taxmod-stock') . '</button></p></form>';
    }

    private function formStart(int $node): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="gruppe" value="' . esc_attr((string) $node) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
    }

    private function remember(string $type, string $text): void
    {
        set_transient(self::NOTICE . get_current_user_id(), ['type' => $type, 'text' => $text], 60);
    }

    private function notice(): string
    {
        $notice = get_transient(self::NOTICE . get_current_user_id());

        if (! is_array($notice)) {
            $said = isset($_GET['taxmod_message']) ? sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['taxmod_message']))) : '';

            return $said === '' ? '' : '<div class="notice notice-' . ($said === 'ok' ? 'success' : 'info') . ' is-dismissible"><p>' . esc_html($said === 'ok' ? __('Gespeichert.', 'taxmod-stock') : $said) . '</p></div>';
        }

        delete_transient(self::NOTICE . get_current_user_id());

        return '<div class="notice notice-' . esc_attr($notice['type'] === 'success' ? 'success' : 'error') . '"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }
}
