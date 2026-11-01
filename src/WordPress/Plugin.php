<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\WordPress\Admin\CockpitPage;
use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\LogisticsShell;
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
    public const PARENT = 'taxmod';

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
        add_action('admin_post_' . LogisticsShell::FULLSCREEN_ACTION, [LogisticsShell::class, 'switchFullscreen']);
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

    /**
     * D-1053: «Logistik» is the one entry in the Taxmod menu; the pages behind it keep their addresses (bookmarks, links of
     * other modules) but leave the menu, since the stock interface's own navigation reaches them.
     */
    public function registerMenu(): void
    {
        $page = static fn (string $slug, callable $render): \Closure => static fn () => print LogisticsShell::wrap($slug, $render());

        $hooks = [
            add_submenu_page(self::PARENT, __('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', CockpitPage::PAGE, $page(CockpitPage::PAGE, static fn (): string => (new CockpitPage())->render())),
            add_submenu_page(self::PARENT, __('Wareneingang', 'taxmod-stock'), __('Wareneingang', 'taxmod-stock'), 'manage_options', self::MENU, $page(self::MENU, static fn (): string => (new InboundPage())->render())),
            add_submenu_page(self::PARENT, __('Lager', 'taxmod-stock'), __('Lager', 'taxmod-stock'), 'manage_options', StockPage::PAGE, $page(StockPage::PAGE, static fn (): string => (new StockPage())->render())),
            // D-1027: demand, source, order per supplier — he orders at the shop himself.
            add_submenu_page(self::PARENT, __('Einkauf', 'taxmod-stock'), __('Einkauf', 'taxmod-stock'), 'manage_options', PurchasePage::PAGE, $page(PurchasePage::PAGE, static fn (): string => (new PurchasePage())->render())),
        ];

        // Notices of other plugins do not show inside the stock interface (design 7.3); its own stand inside the page.
        foreach (array_filter($hooks) as $hook) {
            add_action('load-' . $hook, static function (): void {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            });
        }

        // Removed only when the menu is drawn: WordPress checks access to a page against the submenu, so removing them here
        // would lock the pages.
        add_action('admin_head', static function (): void {
            foreach ([self::MENU, StockPage::PAGE, PurchasePage::PAGE] as $hidden) {
                remove_submenu_page(self::PARENT, $hidden);
            }
        });

        // On a page behind «Logistik» the menu still marks «Logistik».
        add_filter('submenu_file', static fn (?string $file): ?string => in_array(sanitize_key((string) ($_GET['page'] ?? '')), [self::MENU, StockPage::PAGE, PurchasePage::PAGE], true) ? CockpitPage::PAGE : $file);
    }
}
