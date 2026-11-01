<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\Navigation;
use TaxmodStock\WordPress\Plugin;

/**
 * The frame of the stock interface (D-1029, D-1053): its own navigation on the left, along the flow of goods, with each page's
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
        $settings = add_query_arg(['page' => 'taxmod-settings', 'tab' => SettingsTab::SLUG], admin_url('admin.php'));
        $shipping = function_exists('menu_page_url') ? (string) menu_page_url('taxebay-shipping', false) : '';
        $item     = static fn (string $key, string $label, string $page, string $tab = ''): array => ['key' => $key, 'label' => $label, 'page' => $page, 'tab' => $tab];

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
                $item('arrived', __('Angekommen', 'taxmod-stock'), InboundPage::PAGE, 'arrived'),
                $item('on-the-way', __('Unterwegs', 'taxmod-stock'), InboundPage::PAGE, 'shipped'),
                $item('awaited', __('Bestellt', 'taxmod-stock'), InboundPage::PAGE, 'ordered'),
                $item('book', __('Einbuchen', 'taxmod-stock'), StockPage::PAGE, 'wareneingang'),
                $item('booked', __('Eingebucht', 'taxmod-stock'), InboundPage::PAGE, 'booked'),
                $item('test', __('Zu testen', 'taxmod-stock'), InboundPage::PAGE, 'test'),
                $item('archive', __('Archiv', 'taxmod-stock'), InboundPage::PAGE, 'archived'),
            ]],
            ['key' => 'stock', 'label' => __('Bestand', 'taxmod-stock'), 'items' => [
                $item('material', __('Material', 'taxmod-stock'), StockPage::PAGE, 'material'),
                $item('count', __('Inventur', 'taxmod-stock'), StockPage::PAGE, 'inventur'),
                $item('use', __('Verbrauch', 'taxmod-stock'), StockPage::PAGE, 'verbrauch'),
            ]],
            ['key' => 'outbound', 'label' => __('Warenausgang', 'taxmod-stock'), 'items' => $shipping === '' ? [] : [
                ['key' => 'shipping', 'label' => __('eBay-Versand', 'taxmod-stock'), 'page' => 'taxebay-shipping', 'tab' => '', 'url' => $shipping],
            ]],
            ['key' => 'warehouse', 'label' => __('Lager', 'taxmod-stock'), 'items' => [
                $item('places', __('Lagerorte', 'taxmod-stock'), StockPage::PAGE, 'lagerorte'),
            ]],
            // His word 2026-11-01: «einen Punkt Einstellung … die API-Konfiguration, die Grundeinstellung und ja, die Lagerstruktur».
            ['key' => 'settings', 'label' => __('Einstellungen', 'taxmod-stock'), 'items' => [
                ['key' => 'basics', 'label' => __('Grundeinstellungen', 'taxmod-stock'), 'page' => 'taxmod-settings', 'tab' => SettingsTab::SLUG, 'url' => $settings],
                $item('structure', __('Lagerstruktur', 'taxmod-stock'), StockPage::PAGE, 'lagerstruktur'),
                ...self::connections(),
            ]],
        ]);
    }

    /**
     * The other modules under the Taxonomy Modeller menu — the API connections (eBay, DHL, JLCPCB …) — as links.
     *
     * ⚠️ *INFERRED: a module is whatever hangs under the modeller's menu and is neither the modeller's own page nor ours;
     * the modules announce nothing, so their menu entries are the only list there is.*
     *
     * @return list<array{key: string, label: string, page: string, tab: string, url: string}>
     */
    private static function connections(): array
    {
        global $submenu;

        $own = [Plugin::PARENT, 'taxmod-inbox', 'taxmod-cleanup', 'taxmod-backup', 'taxmod-settings', CockpitPage::PAGE, InboundPage::PAGE, StockPage::PAGE, PurchasePage::PAGE];
        $out = [];

        foreach ((array) ($submenu[Plugin::PARENT] ?? []) as $entry) {
            $slug = (string) ($entry[2] ?? '');

            if ($slug === '' || in_array($slug, $own, true) || str_contains($slug, 'cleanup') || str_contains($slug, 'backup') || str_contains($slug, 'inbox')) {
                continue;
            }

            $out[] = ['key' => 'module-' . sanitize_key($slug), 'label' => wp_strip_all_tags((string) ($entry[0] ?? $slug)), 'page' => $slug, 'tab' => '', 'url' => (string) menu_page_url($slug, false)];
        }

        return $out;
    }

    /** Puts a page of the stock interface into the frame; a page outside it comes back unchanged. */
    public static function wrap(string $page, string $html): string
    {
        $navigation = self::navigation();
        $tab        = sanitize_key((string) ($_GET['tab'] ?? ''));
        $active     = $navigation->active($page, $tab);

        if ($active === null) {
            return $html;
        }

        return self::style() . (self::isFullscreen() ? self::fullscreenStyle() : '') . '<div class="taxmod-shell"><nav class="taxmod-shell-nav" aria-label="' . esc_attr__('Logistik', 'taxmod-stock') . '">'
            . self::menu($navigation, $active) . '</nav><div class="taxmod-shell-main">' . $html . '</div></div>';
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
                    $html   .= '<li><a href="' . esc_url(self::url($item)) . '"' . ($current ? ' class="is-current" aria-current="page"' : '') . '>' . esc_html($item['label'])
                        . (isset($item['url']) ? ' <span class="dashicons dashicons-external" aria-hidden="true"></span>' : '') . '</a></li>';
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
            . '#wpcontent{padding-left:20px}.taxmod-shell-nav{top:10px}</style>';
    }

    /** @param array{page: string, tab: string, url?: string} $item */
    private static function url(array $item): string
    {
        return $item['url'] ?? add_query_arg(array_filter(['page' => $item['page'], 'tab' => $item['tab']]), admin_url('admin.php'));
    }

    private static function style(): string
    {
        // The current item is marked by weight and a bar, not by colour alone (design 29).
        return '<style>'
            . '.taxmod-shell{display:grid;grid-template-columns:200px minmax(0,1fr);gap:20px;margin-top:10px}'
            . '.taxmod-shell-nav{background:#fff;border:1px solid #c3c4c7;padding:8px 0;align-self:start;position:sticky;top:42px}'
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
            . '@media (max-width:782px){.taxmod-shell{grid-template-columns:1fr}.taxmod-shell-nav{position:static}}'
            . '</style>';
    }
}
