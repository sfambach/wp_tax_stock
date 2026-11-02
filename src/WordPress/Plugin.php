<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\WordPress\Admin\CockpitPage;
use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\ModulePage;
use TaxmodStock\WordPress\Admin\PurchasePage;
use TaxmodStock\WordPress\Admin\SettingsPage;
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
        // Last, when every module has hung its page under the Taxmod menu, and before admin_head takes the entries out.
        add_action('admin_menu', [ModulePage::class, 'capture'], PHP_INT_MAX);
        add_action('admin_post_' . StockPage::ACTION, static fn () => (new StockPage())->handle());
        add_action('admin_post_' . StockPage::POSITIONS, static fn () => (new StockPage())->positions());
        add_action('admin_post_' . StockPage::THING_SEARCH, static fn () => (new StockPage())->thingSearch());
        add_action('admin_post_' . StockPage::THING_NODES, static fn () => (new StockPage())->thingNodes());
        add_action('admin_post_' . StockPage::THING_BRANCH, static fn () => (new StockPage())->thingBranch());
        add_action('admin_post_' . InboundPage::ACTION, static fn () => (new InboundPage())->handle());
        add_action('admin_post_' . PurchasePage::ACTION, static fn () => (new PurchasePage())->handle());
        add_action('admin_post_' . SettingsPage::ACTION, static fn () => (new SettingsPage())->handle());
        add_action('admin_post_' . LogisticsShell::FULLSCREEN_ACTION, [LogisticsShell::class, 'switchFullscreen']);
    }

    /** The branches without material, settable over REST (/wp/v2/settings) as well as on Logistik › Einstellungen. */
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
            // D-1027: demand, source, order per supplier — he orders at the shop himself.
            add_submenu_page(self::PARENT, __('Einkauf', 'taxmod-stock'), __('Einkauf', 'taxmod-stock'), 'manage_options', PurchasePage::PAGE, $page(PurchasePage::PAGE, static fn (): string => (new PurchasePage())->render())),
            add_submenu_page(self::PARENT, __('Wareneingang', 'taxmod-stock'), __('Wareneingang', 'taxmod-stock'), 'manage_options', self::MENU, $page(self::MENU, static fn (): string => (new InboundPage())->render())),
            add_submenu_page(self::PARENT, __('Lager', 'taxmod-stock'), __('Lager', 'taxmod-stock'), 'manage_options', StockPage::PAGE, $page(StockPage::PAGE, static fn (): string => (new StockPage())->render())),
            // D-1085: Logistik › Einstellungen › Grundeinstellungen, inside the navigation.
            add_submenu_page(self::PARENT, __('Grundeinstellungen', 'taxmod-stock'), __('Grundeinstellungen', 'taxmod-stock'), 'manage_options', SettingsPage::PAGE, $page(SettingsPage::PAGE, static fn (): string => (new SettingsPage())->render())),
        ];

        // D-1085: a connection module's page inside the navigation; one address for all of them, the module named in the query.
        $moduleHook = add_submenu_page(self::PARENT, __('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', ModulePage::PAGE, $page(ModulePage::PAGE, static fn (): string => (new ModulePage())->render(ModulePage::requested())));
        $hooks[]    = $moduleHook;

        if (is_string($moduleHook) && $moduleHook !== '') {
            add_action('load-' . $moduleHook, [ModulePage::class, 'load'], 20);
        }

        // Notices of other plugins do not show inside the stock interface (design 7.3); its own stand inside the page.
        foreach (array_filter($hooks) as $hook) {
            add_action('load-' . $hook, static function (): void {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            });
        }

        // Removed only when the menu is drawn: WordPress checks access to a page against the submenu, so removing them here
        // would lock the pages. D-1085: the modules' entries go too — the menu keeps Modeller, Logistik, maintenance, Configuration.
        add_action('admin_head', static function (): void {
            foreach ([self::MENU, StockPage::PAGE, PurchasePage::PAGE, SettingsPage::PAGE, ModulePage::PAGE, ...ModulePage::menuEntries()] as $hidden) {
                remove_submenu_page(self::PARENT, $hidden);
            }
        });

        // D-1069: the material page inside a goods receipt dialog shows only itself — no admin menu, bar or logistics navigation.
        add_action('admin_enqueue_scripts', static function (): void {
            if (! in_array(sanitize_key((string) ($_GET['page'] ?? '')), [StockPage::PAGE, 'taxmod'], true)) {
                return;
            }

            wp_register_script('taxmod-stock-frame', false, [], (string) TAXMOD_STOCK_VERSION, false);
            wp_enqueue_script('taxmod-stock-frame');
            wp_add_inline_script('taxmod-stock-frame', 'if(window.self!==window.top){document.documentElement.classList.add("taxmod-in-frame");}');
            wp_register_style('taxmod-stock-frame', false, [], (string) TAXMOD_STOCK_VERSION);
            wp_enqueue_style('taxmod-stock-frame');
            wp_add_inline_style('taxmod-stock-frame', 'html.taxmod-in-frame #adminmenumain,html.taxmod-in-frame #wpadminbar,html.taxmod-in-frame #wpfooter,html.taxmod-in-frame .taxmod-shell-nav,html.taxmod-in-frame .taxmod-stock-pagetabs,html.taxmod-in-frame h1.wp-heading-inline,html.taxmod-in-frame .taxmod-version{display:none!important}html.taxmod-in-frame .taxmod-shell{display:block}'
                . 'html.taxmod-in-frame.wp-toolbar{padding-top:0}html.taxmod-in-frame #wpcontent{margin-left:0}');
        });

        // On a page behind «Logistik» the menu still marks «Logistik».
        add_filter('submenu_file', static fn (?string $file): ?string => in_array(sanitize_key((string) ($_GET['page'] ?? '')), [self::MENU, StockPage::PAGE, PurchasePage::PAGE, SettingsPage::PAGE, ModulePage::PAGE], true) ? CockpitPage::PAGE : $file);
    }
}
