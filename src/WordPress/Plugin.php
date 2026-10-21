<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\WordPress\Admin\SettingsTab;
use TaxmodStock\WordPress\Admin\StockPage;

/**
 * Wires the module into WordPress: a tab on the modeller's Configuration and the page «Lager» under its menu.
 */
final class Plugin
{
    /** The modeller's menu slug; the page hangs under it rather than opening a menu of its own. */
    private const PARENT_MENU = 'taxmod';

    public function boot(): void
    {
        add_filter('taxmod_config_tabs', [SettingsTab::class, 'register']);
        // After the modeller (priority 10) has created its menu.
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_post_' . StockPage::ACTION, static fn () => (new StockPage())->handle());
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            self::PARENT_MENU,
            __('Lager', 'taxmod-stock'),
            __('Lager', 'taxmod-stock'),
            'manage_options',
            StockPage::PAGE,
            static fn () => print (new StockPage())->render()
        );
    }
}
