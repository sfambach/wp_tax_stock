<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\SettingsTab;
use TaxmodStock\WordPress\Admin\StockPage;

/**
 * Wires the module into WordPress: a tab on the modeller's Configuration, and the menu «Logistik» with the goods receipt
 * cockpit and the page «Lager» (D-1003: goods in, goods out and stock get a menu of their own beside the modeller's).
 */
final class Plugin
{
    /** D-1003: the menu of goods in, goods out and stock; other modules may hang their pages under this slug. */
    public const MENU = 'taxmod-logistik';

    /** Just below the modeller's own menu entry. */
    private const MENU_POSITION = 59;

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
        // After the modeller (priority 10) has created its menu.
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_post_' . StockPage::ACTION, static fn () => (new StockPage())->handle());
        add_action('admin_post_' . StockPage::POSITIONS, static fn () => (new StockPage())->positions());
        add_action('admin_post_' . InboundPage::ACTION, static fn () => (new InboundPage())->handle());
    }

    public function registerMenu(): void
    {
        $inbound = static fn () => print (new InboundPage())->render();

        add_menu_page(__('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', self::MENU, $inbound, 'dashicons-archive', self::MENU_POSITION);
        // The first entry under a menu repeats its slug; named «Wareneingang» it opens the cockpit (D-1000).
        add_submenu_page(self::MENU, __('Wareneingang', 'taxmod-stock'), __('Wareneingang', 'taxmod-stock'), 'manage_options', self::MENU, $inbound);
        add_submenu_page(self::MENU, __('Lager', 'taxmod-stock'), __('Lager', 'taxmod-stock'), 'manage_options', StockPage::PAGE, static fn () => print (new StockPage())->render());
    }
}
