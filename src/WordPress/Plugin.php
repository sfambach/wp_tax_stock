<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\PurchasePage;
use TaxmodStock\WordPress\Admin\SettingsTab;
use TaxmodStock\WordPress\Admin\StockPage;

/**
 * Wires the module into WordPress: a tab on the modeller's Configuration, and the goods receipt cockpit, the page «Lager» and
 * the page «Einkauf» under the modeller's menu (D-1028: one Taxmod menu, not two; D-1027).
 */
final class Plugin
{
    /** The slug of the goods receipt cockpit; kept from the former menu «Logistik» so bookmarks and links still work (D-1028). */
    public const MENU = 'taxmod-logistik';

    /** The modeller's menu, which every Taxmod module hangs its pages under (D-1028). */
    private const PARENT = 'taxmod';

    public function boot(): void
    {
        // Checked at runtime rather than with «Requires Plugins»: WordPress matches that header against the folder name, and the
        // modeller lives in a versioned folder on fambach.net, so activation was refused although it was active.
        add_action('plugins_loaded', [$this, 'bootWhenModellerIsThere']);
    }

    public function bootWhenModellerIsThere(): void
    {
        if (! class_exists(\Taxmod\WordPress\Plugin::class)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Stock braucht den aktiven Taxonomy Modeller.', 'taxmod-stock') . '</p></div>';
            });

            return;
        }

        add_filter('taxmod_config_tabs', [SettingsTab::class, 'register']);
        add_action('init', [$this, 'registerSettings']);
        // After the modeller (priority 10) has created its menu.
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_post_' . StockPage::ACTION, static fn () => (new StockPage())->handle());
        add_action('admin_post_' . StockPage::POSITIONS, static fn () => (new StockPage())->positions());
        add_action('admin_post_' . StockPage::THING_SEARCH, static fn () => (new StockPage())->thingSearch());
        add_action('admin_post_' . StockPage::THING_NODES, static fn () => (new StockPage())->thingNodes());
        add_action('admin_post_' . StockPage::THING_BRANCH, static fn () => (new StockPage())->thingBranch());
        add_action('admin_post_' . InboundPage::ACTION, static fn () => (new InboundPage())->handle());
        add_action('admin_post_' . PurchasePage::ACTION, static fn () => (new PurchasePage())->handle());
    }

    /** The branches without material, settable over REST (/wp/v2/settings) as well as on the configuration tab. */
    public function registerSettings(): void
    {
        register_setting('taxmod_stock', StockService::NOT_MATERIAL, [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => static fn ($value): array => array_values(array_filter(array_map('absint', is_array($value) ? $value : []))),
            'show_in_rest'      => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ]);
    }

    public function registerMenu(): void
    {
        $inbound = static fn () => print (new InboundPage())->render();

        add_submenu_page(self::PARENT, __('Wareneingang', 'taxmod-stock'), __('Wareneingang', 'taxmod-stock'), 'manage_options', self::MENU, $inbound);
        add_submenu_page(self::PARENT, __('Lager', 'taxmod-stock'), __('Lager', 'taxmod-stock'), 'manage_options', StockPage::PAGE, static fn () => print (new StockPage())->render());
        // D-1027: demand, source, order per supplier — he orders at the shop himself.
        add_submenu_page(self::PARENT, __('Einkauf', 'taxmod-stock'), __('Einkauf', 'taxmod-stock'), 'manage_options', PurchasePage::PAGE, static fn () => print (new PurchasePage())->render());
    }
}
