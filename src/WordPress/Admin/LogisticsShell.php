<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\Contacts;
use TaxmodStock\Core\Navigation;
use TaxmodStock\Core\ReturnPath;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * The frame of the stock interface (D-1029, D-1053, D-1085): its own navigation on the left, along the flow of goods, with each page's
 * tabs as sub-items, and the page on the right. Integrated into wp-admin for now; full screen comes later.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1053
 */
final class LogisticsShell
{
    /** admin-post action switching the user's view between integrated and full screen (design 1, 7.9). */
    public const FULLSCREEN_ACTION = 'taxmod_stock_fullscreen';

    /** User meta: '1' when the user works in full screen. */
    private const FULLSCREEN_META = 'taxmod_stock_fullscreen';

    /**
     * D-1179, the convention for every module: a link that jumps elsewhere carries where it comes from — path and query of
     * the current page in {@see FROM}, its name in {@see FROM_LABEL} — and the frame offers «← Zurück zu …» until the user is
     * back there or takes the navigation.
     */
    public const FROM       = 'taxmod_from';
    public const FROM_LABEL = 'taxmod_from_label';

    /** Set on the navigation's own links: whoever takes the menu starts afresh, the way back is dropped. */
    public const VIA_MENU = 'taxmod_nav';

    /** User meta: the stack of origins (D-1179). */
    private const RETURN_META = 'taxmod_stock_return_path';

    /** D-1232: the same switch, set from Benutzereinstellungen. */
    public static function setFullscreen(bool $on): void
    {
        update_user_meta(get_current_user_id(), self::FULLSCREEN_META, $on ? '1' : '0');
    }

    public static function isFullscreen(): bool
    {
        return get_user_meta(get_current_user_id(), self::FULLSCREEN_META, true) === '1';
    }

    /** admin-post: capability → nonce → validate → keep the user's choice → back to the page. */
    public static function switchFullscreen(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'taxmod-stock'), '', ['response' => 403]);
        }

        check_admin_referer(self::FULLSCREEN_ACTION);

        update_user_meta(get_current_user_id(), self::FULLSCREEN_META, self::isFullscreen() ? '0' : '1');
        wp_safe_redirect(wp_validate_redirect(esc_url_raw(wp_unslash((string) ($_POST['return'] ?? ''))), admin_url('admin.php?page=' . CockpitPage::PAGE)));
        exit;
    }
    public static function navigation(): Navigation
    {
        $item = static fn (string $key, string $label, string $page, string $tab = ''): array => ['key' => $key, 'label' => $label, 'page' => $page, 'tab' => $tab];

        return new Navigation([
            // D-1241, his word 2026-11-09: «… unter Cockpit angezeigt werden. Und auch das Zeitraum würde ich einfach unten drunter
            // packen … Sodass wir nur noch Cockpit haben.» — one item, what is due and the period on one page.
            ['key' => 'cockpit', 'label' => __('Cockpit', 'taxmod-stock'), 'items' => [
                $item('cockpit', __('Cockpit', 'taxmod-stock'), CockpitPage::PAGE),
            ]],
            ['key' => 'purchasing', 'label' => __('Einkauf', 'taxmod-stock'), 'items' => [
                $item('reorder', __('Nachbestellen', 'taxmod-stock'), PurchasePage::PAGE, 'nachbestellen'),
                $item('demand', __('Bedarf', 'taxmod-stock'), PurchasePage::PAGE, 'bedarf'),
                $item('to-order', __('Zu bestellen', 'taxmod-stock'), PurchasePage::PAGE, 'bestellen'),
                $item('ordered', __('Bestellt', 'taxmod-stock'), PurchasePage::PAGE, 'bestellt'),
                $item('dropped', __('Verworfen', 'taxmod-stock'), PurchasePage::PAGE, 'verworfen'),
            ]],
            ['key' => 'inbound', 'label' => __('Wareneingang', 'taxmod-stock'), 'items' => [
                // In the order of the process, like the tabs of the page (his question 2026-11-01: «die reihenfolge im menü stimmt nicht mit dem ablauf in den tabs überein»).
                $item('awaited', __('Bestellt', 'taxmod-stock'), InboundPage::PAGE, 'ordered'),
                $item('on-the-way', __('Versendet', 'taxmod-stock'), InboundPage::PAGE, 'shipped'),
                $item('arrived', __('Angekommen', 'taxmod-stock'), InboundPage::PAGE, 'arrived'),
                $item('book', __('Einbuchen', 'taxmod-stock'), StockPage::PAGE, 'wareneingang'),
                $item('booked', __('Eingebucht', 'taxmod-stock'), InboundPage::PAGE, 'booked'),
                $item('test', __('Zu testen', 'taxmod-stock'), InboundPage::PAGE, 'test'),
                $item('archive', __('Archiv', 'taxmod-stock'), InboundPage::PAGE, 'archived'),
            ]],
            ['key' => 'stock', 'label' => __('Bestand', 'taxmod-stock'), 'items' => [
                $item('material', __('Material', 'taxmod-stock'), StockPage::PAGE, 'material'),
                // D-1174: a node of the material tree is a group; its stock holds every group below it.
                $item('group-stock', __('Gruppenbestand', 'taxmod-stock'), StockPage::PAGE, 'gruppenbestand'),
                $item('count', __('Inventur', 'taxmod-stock'), StockPage::PAGE, 'inventur'),
                $item('use', __('Verbrauch', 'taxmod-stock'), StockPage::PAGE, 'verbrauch'),
                $item('reservations', __('Reservierungen', 'taxmod-stock'), StockPage::PAGE, 'reservierungen'),
                $item('places', __('Platzbestand', 'taxmod-stock'), StockPage::PAGE, 'lagerorte'),
            ]],
            // D-957 names «Fertigung» among the module's areas; D-1139 is its first page.
            ['key' => 'production', 'label' => __('Fertigung', 'taxmod-stock'), 'items' => [
                $item('project-boxes', __('Projekt-Gebinde', 'taxmod-stock'), StockPage::PAGE, 'fertigung'),
                // D-1142: the manufacturing module (wp_tax_manufacturing) hangs its pages here.
                ...self::modules('production'),
            ]],
            ['key' => 'outbound', 'label' => __('Warenausgang', 'taxmod-stock'), 'items' => self::modules('outbound')],
            // D-1184, his word 2026-11-05: «kontakt ast in logistik menü einbauen» — firms, suppliers, manufacturers, persons.
            // D-1185, his word 2026-11-06: «kontakte hat doch schon unter knoten die bitte im kontakte knoten an zueigen und der kontakte knoten
            // selbst ist alle kontakte», then «ich meine das kontakte menü» — the area itself opens all contacts, the nodes below it are its items.
            // His word 2026-11-01: «einen Punkt Einstellung … die API-Konfiguration, die Grundeinstellung und ja, die Lagerstruktur».
            ['key' => 'settings', 'label' => __('Einstellungen', 'taxmod-stock'), 'items' => [
                // D-1085: the business settings inside the navigation; the technical structure stays on the modeller's Configuration.
                $item('basics', __('Grundeinstellungen', 'taxmod-stock'), SettingsPage::PAGE),
                // His words 2026-11-03 (D-1129): «sollte eigener punkt in einstellungen sein vielleicht lagerstrukut platzarten, layout».
                ['path' => [__('Lagerstruktur', 'taxmod-stock')]] + $item('structure', __('Layout', 'taxmod-stock'), StockPage::PAGE, 'lagerstruktur'),
                ['path' => [__('Lagerstruktur', 'taxmod-stock')]] + $item('place-types', __('Platzarten', 'taxmod-stock'), StockPage::PAGE, 'platzarten'),
                // D-1193, his word 2026-11-06: «kontakte nochmal in einstellungen ? finde sie da gut aber dann sollten sie aus dem andren
                // menü raus» — the contact group stands under Einstellungen only; its head opens all contacts.
                ...self::contactItems(),
                ...self::modules('settings'),
                // His words 2026-11-03: «pack mal das menü drucken unter einstellungen», «warum hat das nur zwei ebenen», «kannst du
                // das so bauen das es n ebenen zulässt» — an item's `path` puts it any depth below its area (D-1121).
                // D-1153: label templates are records; their list sits with the print pages.
                ['path' => [__('Druck', 'taxmod-stock')]] + $item('label-templates', __('Etiketten', 'taxmod-stock'), StockPage::PAGE, 'etikettenvorlagen'),
                // D-1192: the printers, the roll each holds, «Rolle wechseln».
                ['path' => [__('Druck', 'taxmod-stock')]] + $item('printers', __('Drucker', 'taxmod-stock'), StockPage::PAGE, 'drucker'),
                ...array_map(static fn (array $item): array => ['path' => [__('Druck', 'taxmod-stock'), ...$item['path']]] + $item, self::modules('print')),
                // D-1232, his question 2026-11-08: «was ist eigentlich mit dem Benutzereinstellungen los? Die sind noch nicht sichtbar».
                $item('user', __('Benutzereinstellungen', 'taxmod-stock'), SettingsPage::PAGE, SettingsPage::USER_TAB),
            ]],
        ]);
    }

    /**
     * D-1185, D-1193: the contact branch's nodes as items of the group «Kontakte» under Einstellungen — the group's head opens all
     * contacts, a node with nodes below it a group whose head item is its own link. One read-tree per request.
     *
     * @return list<array<string, mixed>>
     */
    private static function contactItems(): array
    {
        static $items = null;

        if ($items !== null) {
            return $items;
        }

        $group = __('Kontakte', 'taxmod-stock');
        $items = [['key' => 'contacts', 'label' => $group, 'page' => ContactsPage::PAGE, 'tab' => '', 'path' => [$group], 'head' => true]];

        try {
            $stock = new StockService();
            $tree  = $stock->contactTree();

            foreach (Contacts::menu($tree, $stock->contactRoot()) as $node) {
                $items[] = ['key' => 'contact-' . $node['node'], 'label' => $node['label'], 'page' => ContactsPage::PAGE, 'tab' => (string) $node['node'],
                    'path' => [$group, ...$node['path']], 'head' => $node['head']];
            }
        } catch (StockError) {
            // Without the modeller's tree the area keeps its own page only; the page names what is missing.
        }

        return $items;
    }

    /**
     * The connection modules' pages of one area, each opened inside the frame (D-1085).
     *
     * @return list<array{key: string, label: string, page: string, tab: string, module: string}>
     */
    private static function modules(string $area): array
    {
        $items = [];

        foreach (ModulePage::all() as $module) {
            if ($module['area'] === $area) {
                $items[] = ['key' => 'module-' . sanitize_key($module['slug']), 'label' => $module['label'], 'page' => ModulePage::PAGE, 'tab' => '', 'module' => $module['slug'], 'path' => $module['path']];
            }
        }

        return $items;
    }

    /**
     * Puts a page of the stock interface into the frame; a page outside it comes back unchanged. `$side` is what the page
     * shows under the navigation, in its own box of the same width (D-1092); most pages have none.
     */
    public static function wrap(string $page, string $html, string $side = ''): string
    {
        $navigation = self::navigation();
        $tab        = sanitize_key((string) ($_GET['tab'] ?? ''));
        $overview   = $page === CockpitPage::PAGE ? sanitize_key((string) ($_GET[CockpitPage::AREA] ?? '')) : '';
        // D-1128: an area's overview opens its area in the navigation, no item marked; D-1134: a group's overview opens the group.
        $group      = $overview === '' ? '' : sanitize_text_field(wp_unslash((string) ($_GET[CockpitPage::GROUP] ?? '')));
        $active     = $overview !== '' && $overview !== 'cockpit' ? ['area' => $overview, 'item' => '', 'group' => $group] : $navigation->active($page, $tab, $page === ModulePage::PAGE ? ModulePage::requested() : '');

        if ($active === null) {
            return $side . $html;
        }

        return self::style() . (self::isFullscreen() ? self::fullscreenStyle() : '') . '<div class="taxmod-shell' . ($side === '' ? '' : ' has-side') . '">'
            . '<div class="taxmod-shell-column"><nav class="taxmod-shell-nav" aria-label="' . esc_attr__('Logistik', 'taxmod-stock') . '">'
            . self::menu($navigation, $active) . '</nav>' . ($side === '' ? '' : '<div class="taxmod-shell-side">' . $side . '</div>')
            . '</div><div class="taxmod-shell-main">' . self::backLink() . $html . '</div></div>';
    }

    /**
     * A link to `$url` that remembers the current page as its origin (D-1179).
     */
    public static function withOrigin(string $url, string $label): string
    {
        return add_query_arg([self::FROM => rawurlencode(self::here()), self::FROM_LABEL => rawurlencode($label)], $url);
    }

    /** D-1179: the way back for this request, kept per user; '' when there is none. */
    private static function backLink(): string
    {
        $user   = get_current_user_id();
        $stored = get_user_meta($user, self::RETURN_META, true);
        $path   = isset($_GET[self::VIA_MENU]) ? ReturnPath::empty() : ReturnPath::fromStored($stored)->arrive(self::here(), self::origin());

        if ($path->toStored() !== (is_array($stored) ? $stored : [])) {
            update_user_meta($user, self::RETURN_META, $path->toStored());
        }

        $top = $path->top();

        return $top === null ? '' : '<p class="taxmod-shell-back"><a href="' . esc_url($top['url']) . '">&larr; '
            . esc_html(sprintf(__('Zurück zu %s', 'taxmod-stock'), $top['label'])) . '</a></p>';
    }

    /** Path and query of this request, without the arguments of D-1179. */
    private static function here(): string
    {
        return remove_query_arg([self::FROM, self::FROM_LABEL, self::VIA_MENU], wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
    }

    /**
     * The origin the request names; only a page of this admin counts, anything else is ignored.
     *
     * @return array{url: string, label: string}|null
     */
    private static function origin(): ?array
    {
        $url   = esc_url_raw(wp_unslash((string) ($_GET[self::FROM] ?? '')));
        $admin = (string) wp_parse_url(admin_url(), PHP_URL_PATH);

        if ($url === '' || $admin === '' || ! str_starts_with($url, $admin)) {
            return null;
        }

        $label = mb_substr(sanitize_text_field(wp_unslash((string) ($_GET[self::FROM_LABEL] ?? ''))), 0, 120);

        return ['url' => $url, 'label' => $label === '' ? __('vorherige Seite', 'taxmod-stock') : $label];
    }

    /** @param array{area: string, item: string, group?: string} $active `group`: the open group's path, joined by «/» */
    private static function menu(Navigation $navigation, array $active): string
    {
        $full = self::isFullscreen();
        $back = esc_url_raw((is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_HOST'] ?? ''))) . wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
        $html = '<p class="taxmod-shell-title">' . esc_html__('Logistik', 'taxmod-stock') . '</p><ul>';

        foreach ($navigation->areas() as $area) {
            $here  = $area['key'] === $active['area'];
            // D-1128: the area itself opens its overview with a tile per item; the cockpit is that overview already.
            $link  = $area['url'] ?? ($area['key'] === 'cockpit' ? self::url($area['items'][0]) : CockpitPage::overviewUrl($area['key']));
            $html .= '<li class="taxmod-shell-area' . ($here ? ' is-open' : '') . '"><a href="' . esc_url(add_query_arg(self::VIA_MENU, '1', $link)) . '">' . esc_html($area['label']) . '</a>';

            if ($here || count($area['items']) > 1) {
                $html .= self::items($area['items'], 0, $here ? $active['item'] : '', $area['key'], $here ? (string) ($active['group'] ?? '') : '');
            }

            $html .= '</li>';
        }

        return $html . '</ul><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-shell-mode">'
            . wp_nonce_field(self::FULLSCREEN_ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::FULLSCREEN_ACTION) . '">'
            . '<input type="hidden" name="return" value="' . esc_attr($back) . '"><button type="submit" class="button-link">'
            . ($full ? esc_html__('Mit WordPress-Leisten', 'taxmod-stock') : esc_html__('Vollbild', 'taxmod-stock')) . '</button></form>'
            . ($full ? '<p class="taxmod-shell-mode"><a href="' . esc_url(admin_url()) . '">' . esc_html__('Zu WordPress', 'taxmod-stock') . '</a></p>' : '');
    }

    /**
     * The items of an area as nested lists: an item's `path` names the groups above it, any number (D-1121). A group links to
     * its overview and opens when the current item lies in it or its overview is shown (D-1134).
     *
     * @param list<array{key: string, label: string, page: string, tab: string, module?: string, path?: list<string>}> $items
     */
    private static function items(array $items, int $depth, string $current, string $area = '', string $openGroup = ''): string
    {
        $html  = '<ul>';
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            $item = $items[$i];
            $path = array_values($item['path'] ?? []);

            if (! empty($item['hidden'])) {
                continue;
            }

            if (count($path) <= $depth) {
                $html .= '<li><a href="' . esc_url(add_query_arg(self::VIA_MENU, '1', self::url($item))) . '" style="padding-left:' . (24 + 12 * $depth) . 'px"'
                    . ($item['key'] === $current ? ' class="is-current" aria-current="page"' : '') . '>' . esc_html($item['label']) . '</a></li>';
                continue;
            }

            $group   = $path[$depth];
            $members = [];
            while ($i < $count && ($items[$i]['path'][$depth] ?? null) === $group) {
                $members[] = $items[$i];
                $i++;
            }
            $i--;
            $groupPath = implode('/', array_slice($path, 0, $depth + 1));
            $open      = in_array($current, array_column($members, 'key'), true) || $openGroup === $groupPath || str_starts_with($openGroup, $groupPath . '/');
            // D-1185: a group with a head item (a node with nodes below it) opens that item's page; the head is not listed inside it.
            $head      = null;
            foreach ($members as $at => $member) {
                if (! empty($member['head']) && count((array) $member['path']) === $depth + 1) {
                    $head = $member;
                    unset($members[$at]);
                    break;
                }
            }
            $members   = array_values($members);
            // D-1128: a group opens its own overview, like an area.
            $target    = $head !== null ? self::url($head) : ($area === '' ? self::url($members[0]) : CockpitPage::overviewUrl($area, $groupPath));
            $here      = $head !== null ? $head['key'] === $current : $openGroup === $groupPath;
            $html .= '<li class="taxmod-shell-group' . ($open ? ' is-open' : '') . '"><a href="' . esc_url(add_query_arg(self::VIA_MENU, '1', $target)) . '" style="padding-left:' . (24 + 12 * $depth) . 'px"'
                . ($here ? ' class="is-current" aria-current="page"' : '') . '>'
                . esc_html($head !== null ? (string) $head['label'] : $group) . '</a>' . ($members === [] ? '' : self::items($members, $depth + 1, $current, $area, $openGroup)) . '</li>';
        }

        return $html . '</ul>';
    }

    /** Full screen: the WordPress bars go, the stock interface fills the window; login and rights stay WordPress's. */
    private static function fullscreenStyle(): string
    {
        return '<style>#wpadminbar,#adminmenumain,#wpfooter{display:none}html.wp-toolbar{padding-top:0}#wpcontent,#wpfooter{margin-left:0}'
            . '#wpcontent{padding-left:20px}.taxmod-shell-column{top:10px}.taxmod-shell.has-side .taxmod-shell-column{height:calc(100vh - 20px)}</style>';
    }

    /**
     * The address of a navigation item: always a page of the stock interface (D-1085).
     *
     * @param array{page: string, tab: string, module?: string} $item
     */
    public static function url(array $item): string
    {
        return add_query_arg(array_filter(['page' => $item['page'], 'tab' => $item['tab'], 'module' => $item['module'] ?? '']), admin_url('admin.php'));
    }

    private static function style(): string
    {
        // The current item is marked by weight and a bar, not by colour alone (design 29).
        return '<style>'
            . '.taxmod-shell{display:grid;grid-template-columns:200px minmax(0,1fr);gap:20px;margin-top:10px}'
            . '.taxmod-shell-column{align-self:start;position:sticky;top:42px;display:flex;flex-direction:column;gap:12px;min-width:0}'
            . '.taxmod-shell-nav{background:#fff;border:1px solid #c3c4c7;padding:8px 0;flex:none}'
            . '.taxmod-shell-title{margin:0 12px 6px;font-weight:600;font-size:14px}'
            . '.taxmod-shell-nav ul{margin:0;list-style:none}.taxmod-shell-nav li{margin:0}'
            . '.taxmod-shell-nav a{display:block;padding:5px 12px;text-decoration:none;border-left:3px solid transparent}'
            . '.taxmod-shell-area>a{font-weight:600;color:#1d2327}'
            . '.taxmod-shell-area ul a{padding-left:24px;color:#2271b1}'
            . '.taxmod-shell-area:not(.is-open) ul{display:none}'
            . '.taxmod-shell-group>a{color:#2271b1}.taxmod-shell-group:not(.is-open)>ul{display:none}'
            . '.taxmod-shell-nav a.is-current{font-weight:600;border-left-color:#2271b1;background:#f0f6fc}'
            . '.taxmod-shell-nav a:focus{box-shadow:0 0 0 2px #2271b1;outline:none}'
            . '.taxmod-shell-mode{margin:10px 12px 0;padding-top:8px;border-top:1px solid #dcdcde}.taxmod-shell-mode+.taxmod-shell-mode{border-top:0;padding-top:0;margin-top:4px}'
            . '.taxmod-shell-main{overflow-x:auto}.taxmod-shell-main>.wrap{margin-top:0}'
            . '.taxmod-shell-back{margin:0 0 6px}.taxmod-shell-back a{text-decoration:none;font-weight:600}'
            // The narrower column leaves fixed layout too little room for a header without a width.
            . '.taxmod-shell-main .wp-list-table{table-layout:auto}'
            . self::sideStyle()
            . '@media (max-width:782px){.taxmod-shell{grid-template-columns:1fr}.taxmod-shell-column{position:static}.taxmod-shell.has-side .taxmod-shell-column{height:auto}.taxmod-shell-side .taxmod-chooser-tree{max-height:50vh}}'
            . '</style>';
    }

    /**
     * D-1092: the page's box under the navigation runs to the bottom of the window and scrolls inside; in the modeller's
     * tree only the frame changes here — its rows, folding and highlight stay as the modeller draws them. In the frame of
     * another page (html.taxmod-in-frame) the navigation is hidden and the box stands above the content.
     */
    private static function sideStyle(): string
    {
        $css = '.taxmod-shell.has-side .taxmod-shell-column{height:calc(100vh - 52px)}'
            . '.taxmod-shell-side{flex:1 1 auto;min-height:16em;display:flex;flex-direction:column;background:#fff;border:1px solid #c3c4c7;padding:8px 0 0;overflow:hidden}'
            . '.taxmod-shell-side>*{flex:none}'
            . '.taxmod-shell-side>.taxmod-stock-places{flex:1 1 auto;min-height:0;min-width:0;display:flex;flex-direction:column}'
            . '.taxmod-stock-places-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin:0 8px 6px 0}'
            . '.taxmod-stock-places-head{flex-wrap:wrap;padding-left:12px}.taxmod-stock-places-head .taxmod-shell-title{margin:0}'
            . '.wp-core-ui .taxmod-stock-places-head .button-small{padding:0 6px;font-size:12px;white-space:nowrap}'
            . '.taxmod-stock-places-top{margin:0 8px 4px 12px;padding:3px 6px;border:1px dashed #c3c4c7;border-radius:3px;color:#50575e;font-size:12px}'
            . '.taxmod-shell-side .taxmod-record-tree,.taxmod-shell-side .taxmod-record-tree>.taxmod-chooser{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;width:100%;min-width:0}'
            . '.taxmod-shell-side .taxmod-chooser-tree{flex:1 1 auto;min-height:0;max-height:none;overflow-x:hidden;overflow-y:auto;border:0;border-top:1px solid #dcdcde;border-radius:0;padding:0;margin:0}'
            . '.taxmod-shell-side .taxmod-tree-search{padding:6px 8px}.taxmod-shell-side .taxmod-tree-filter{min-width:0;width:100%}'
            . '.taxmod-shell-side .taxmod-tree-row{padding:1px 4px}'
            . '.taxmod-shell-side .taxmod-tree-fold-box{width:1.1em}'
            . '.taxmod-shell-side .taxmod-chooser-row{display:flex;min-width:0;max-width:100%}'
            . '.taxmod-shell-side .taxmod-tree-node{min-width:0;overflow:hidden}'
            . '.taxmod-shell-side .taxmod-tree-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . 'html.taxmod-in-frame .taxmod-shell.has-side .taxmod-shell-column{position:static;height:auto;margin-bottom:16px}'
            . 'html.taxmod-in-frame .taxmod-shell-side .taxmod-chooser-tree{max-height:40vh}';

        // A deep tree in a narrow box: each level indents less than the modeller's 1.4em (its width stands inline per row).
        for ($depth = 1; $depth <= 16; $depth++) {
            $css .= '.taxmod-shell-side .taxmod-tree-row[data-depth="' . $depth . '"]>.taxmod-tree-indent{width:' . ($depth * 0.8) . 'em!important}';
        }

        return $css;
    }
}
