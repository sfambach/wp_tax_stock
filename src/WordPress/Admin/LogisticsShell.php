<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\Navigation;

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
            ['key' => 'cockpit', 'label' => __('Cockpit', 'taxmod-stock'), 'items' => [
                $item('cockpit', __('Was ansteht', 'taxmod-stock'), CockpitPage::PAGE),
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
                $item('count', __('Inventur', 'taxmod-stock'), StockPage::PAGE, 'inventur'),
                $item('use', __('Verbrauch', 'taxmod-stock'), StockPage::PAGE, 'verbrauch'),
                $item('reservations', __('Reservierungen', 'taxmod-stock'), StockPage::PAGE, 'reservierungen'),
                $item('places', __('Lagerorte', 'taxmod-stock'), StockPage::PAGE, 'lagerorte'),
            ]],
            ['key' => 'outbound', 'label' => __('Warenausgang', 'taxmod-stock'), 'items' => self::modules('outbound')],
            // His word 2026-11-01: «einen Punkt Einstellung … die API-Konfiguration, die Grundeinstellung und ja, die Lagerstruktur».
            ['key' => 'settings', 'label' => __('Einstellungen', 'taxmod-stock'), 'items' => [
                // D-1085: the business settings inside the navigation; the technical structure stays on the modeller's Configuration.
                $item('basics', __('Grundeinstellungen', 'taxmod-stock'), SettingsPage::PAGE),
                $item('structure', __('Lagerstruktur', 'taxmod-stock'), StockPage::PAGE, 'lagerstruktur'),
                ...self::modules('settings'),
            ]],
        ]);
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
                $items[] = ['key' => 'module-' . sanitize_key($module['slug']), 'label' => $module['label'], 'page' => ModulePage::PAGE, 'tab' => '', 'module' => $module['slug']];
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
        $active     = $navigation->active($page, $tab, $page === ModulePage::PAGE ? ModulePage::requested() : '');

        if ($active === null) {
            return $side . $html;
        }

        return self::style() . (self::isFullscreen() ? self::fullscreenStyle() : '') . '<div class="taxmod-shell' . ($side === '' ? '' : ' has-side') . '">'
            . '<div class="taxmod-shell-column"><nav class="taxmod-shell-nav" aria-label="' . esc_attr__('Logistik', 'taxmod-stock') . '">'
            . self::menu($navigation, $active) . '</nav>' . ($side === '' ? '' : '<div class="taxmod-shell-side">' . $side . '</div>')
            . '</div><div class="taxmod-shell-main">' . $html . '</div></div>';
    }

    /** @param array{area: string, item: string} $active */
    private static function menu(Navigation $navigation, array $active): string
    {
        $full = self::isFullscreen();
        $back = esc_url_raw((is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_HOST'] ?? ''))) . wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
        $html = '<p class="taxmod-shell-title">' . esc_html__('Logistik', 'taxmod-stock') . '</p><ul>';

        foreach ($navigation->areas() as $area) {
            $here  = $area['key'] === $active['area'];
            $html .= '<li class="taxmod-shell-area' . ($here ? ' is-open' : '') . '"><a href="' . esc_url(self::url($area['items'][0])) . '">' . esc_html($area['label']) . '</a>';

            if ($here || count($area['items']) > 1) {
                $html .= '<ul>';

                foreach ($area['items'] as $item) {
                    $current = $here && $item['key'] === $active['item'];
                    $html   .= '<li><a href="' . esc_url(self::url($item)) . '"' . ($current ? ' class="is-current" aria-current="page"' : '') . '>' . esc_html($item['label']) . '</a></li>';
                }

                $html .= '</ul>';
            }

            $html .= '</li>';
        }

        return $html . '</ul><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-shell-mode">'
            . wp_nonce_field(self::FULLSCREEN_ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::FULLSCREEN_ACTION) . '">'
            . '<input type="hidden" name="return" value="' . esc_attr($back) . '"><button type="submit" class="button-link">'
            . ($full ? esc_html__('Mit WordPress-Leisten', 'taxmod-stock') : esc_html__('Vollbild', 'taxmod-stock')) . '</button></form>'
            . ($full ? '<p class="taxmod-shell-mode"><a href="' . esc_url(admin_url()) . '">' . esc_html__('Zu WordPress', 'taxmod-stock') . '</a></p>' : '');
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
            . '.taxmod-shell-nav a.is-current{font-weight:600;border-left-color:#2271b1;background:#f0f6fc}'
            . '.taxmod-shell-nav a:focus{box-shadow:0 0 0 2px #2271b1;outline:none}'
            . '.taxmod-shell-mode{margin:10px 12px 0;padding-top:8px;border-top:1px solid #dcdcde}.taxmod-shell-mode+.taxmod-shell-mode{border-top:0;padding-top:0;margin-top:4px}'
            . '.taxmod-shell-main{overflow-x:auto}.taxmod-shell-main>.wrap{margin-top:0}'
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
