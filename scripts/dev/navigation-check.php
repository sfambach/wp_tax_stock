<?php declare(strict_types=1);

/**
 * D-1085 against a local WordPress: nothing under Logistik leaves the logistics navigation, the module entries leave the
 * WP menu, a module's page renders inside the frame, and the Grundeinstellungen keep their option names.
 *
 *     php scripts/dev/navigation-check.php [path/to/wordpress]
 *
 * Builds a Taxmod submenu with two stand-in modules; uses the modeller's no-write bracket: nothing survives the run.
 * Needs the Taxonomy Modeller active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxmodStock\WordPress\Admin\CockpitPage;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\ModulePage;
use TaxmodStock\WordPress\Admin\SettingsPage;
use TaxmodStock\WordPress\Admin\StockPage;
use TaxmodStock\WordPress\Plugin;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

// The Taxmod menu as the modeller, this plugin and two modules leave it before admin_head.
global $submenu, $admin_page_hooks;
$admin_page_hooks[Plugin::PARENT] = 'taxmod';
$submenu[Plugin::PARENT]          = [
    ['Taxonomy Modeller', 'manage_options', Plugin::PARENT],
    ['Import', 'manage_options', 'taxmod-inbox'],
    ['Logistik', 'manage_options', CockpitPage::PAGE],
    ['Lager', 'manage_options', StockPage::PAGE],
    ['Grundeinstellungen', 'manage_options', SettingsPage::PAGE],
    ['Logistik', 'manage_options', ModulePage::PAGE],
    ['eBay-Versand', 'manage_options', 'taxebay-shipping'],
    ['DHL <span class="count">2</span>', 'manage_options', '__navcheck-dhl'],
    ['Cleanup', 'manage_options', 'taxmod-cleanup'],
    ['Backup', 'manage_options', 'taxmod-backup'],
    ['Configuration', 'manage_options', 'taxmod-settings'],
];
add_action(get_plugin_page_hookname('__navcheck-dhl', Plugin::PARENT), static function (): void {
    echo '<div class="wrap"><p>DHL-Seite des Moduls</p></div>';
});
$loaded = 0;
add_action('load-' . get_plugin_page_hookname('__navcheck-dhl', Plugin::PARENT), static function () use (&$loaded): void {
    ++$loaded;
});
ModulePage::capture();

echo "Navigation\n";
$navigation = LogisticsShell::navigation();
$pages      = $navigation->pages();
$outside    = [];

foreach ($navigation->areas() as $area) {
    foreach ($area['items'] as $item) {
        $url = LogisticsShell::url($item);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (! str_starts_with($url, admin_url('admin.php?')) || ! in_array($query['page'] ?? '', $pages, true) || in_array($query['page'] ?? '', [Plugin::PARENT, 'taxmod-settings'], true)) {
            $outside[] = $area['label'] . ' › ' . $item['label'] . ' → ' . $url;
        }
    }
}

$pruefe($outside === [], 'no item leaves the logistics navigation' . ($outside === [] ? '' : ': ' . implode('; ', $outside)));
$settings = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'settings'))[0]['items'] ?? [];
$pruefe(array_slice(array_column($settings, 'key'), 0, 4) === ['basics', 'structure', 'place-types', 'module-__navcheck-dhl'] && array_filter(array_slice(array_column($settings, 'key'), 4), static fn (string $key): bool => ! str_starts_with($key, 'module-')) === [], 'Einstellungen: Grundeinstellungen, Lagerstruktur › Layout and Platzarten (D-1129), then the module (' . implode(', ', array_column($settings, 'key')) . ')');
$pruefe(($settings[1]['path'] ?? []) === ['Lagerstruktur'] && ($settings[2]['path'] ?? []) === ['Lagerstruktur'] && ($settings[2]['tab'] ?? '') === 'platzarten', 'Layout and Platzarten stand in the group «Lagerstruktur»');
$pruefe(($settings[0]['page'] ?? '') === SettingsPage::PAGE, 'Grundeinstellungen is a page of the stock interface, not the modeller\'s Configuration');
$pruefe(($settings[3]['label'] ?? '') === 'DHL 2', 'the module keeps its menu label, without markup');
$outbound = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'outbound'))[0]['items'] ?? [];
$pruefe(array_column($outbound, 'module') === ['taxebay-shipping'], 'Warenausgang holds the eBay shipping page');
// His words 2026-11-03 (D-1121): «pack mal das menü drucken unter einstellungen», «kannst du das so bauen das es n ebenen zulässt».
add_filter(ModulePage::FILTER, static fn (array $pages): array => [...$pages,
    ['slug' => '__navcheck-print', 'label' => 'Aufträge', 'area' => 'print', 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-deep', 'label' => 'Tief', 'area' => 'settings', 'path' => ['Ebene A', 'Ebene B'], 'render' => static fn (): string => ''],
]);
ModulePage::capture();
$areas = LogisticsShell::navigation()->areas();
$einstellungen = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'settings'))[0]['items'] ?? [];
$druck = array_values(array_filter($einstellungen, static fn (array $item): bool => ($item['module'] ?? '') === '__navcheck-print'))[0] ?? null;
$pruefe($druck !== null && ($druck['path'] ?? []) === ['Druck'] && ! in_array('print', array_column($areas, 'key'), true), 'a page announced with area «print» stands under Einstellungen › Druck, not in an area of its own');
$_GET = ['page' => ModulePage::PAGE, 'module' => '__navcheck-deep'];
$tief = LogisticsShell::wrap(ModulePage::PAGE, '<p>x</p>');
$pruefe(substr_count($tief, 'taxmod-shell-group is-open') === 2 && str_contains($tief, '>Ebene B</a><ul><li><a') && str_contains($tief, 'aria-current="page">Tief</a>'), 'a page with a path of two groups stands three levels deep, its groups open (n levels)');
// D-1128, his word «auf dem hauptknoten eine übersicht zu bekommen mit abstprung»: an area and a group open an overview of tiles.
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'stock'];
$uebersicht = LogisticsShell::wrap(CockpitPage::PAGE, (new CockpitPage())->render());
$pruefe(str_contains($uebersicht, 'taxmod-shell-area is-open"><a href="' . esc_url(CockpitPage::overviewUrl('stock')) . '">Bestand</a>') && substr_count($uebersicht, 'taxmod-tile-label"') === 5 && str_contains($uebersicht, '<span class="taxmod-tile-label">Lagerorte</span>'), 'Bestand opens an overview with a tile for each of its five items, the area open in the navigation');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'settings'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, esc_url(CockpitPage::overviewUrl('settings', 'Lagerstruktur'))) && substr_count($uebersicht, '>Lagerstruktur</span>') === 1, 'a group (Lagerstruktur) is one tile that opens its own overview');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'settings', CockpitPage::GROUP => 'Lagerstruktur'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, '>Layout</span>') && str_contains($uebersicht, '>Platzarten</span>') && ! str_contains($uebersicht, '>Grundeinstellungen</span>'), 'the group overview shows only its own items');
$nav = LogisticsShell::wrap(CockpitPage::PAGE, $uebersicht);
$pruefe(str_contains($nav, 'taxmod-shell-group is-open"><a href="' . esc_url(CockpitPage::overviewUrl('settings', 'Lagerstruktur')) . '" style="padding-left:24px" class="is-current"'), 'D-1134: the group\'s overview opens the group in the navigation, its sub-items shown');
$pruefe(substr_count($uebersicht, 'class="taxmod-tile-count"') === 2, 'D-1134: Layout and Platzarten show how many there are');
$_GET = ['page' => CockpitPage::PAGE];
$pruefe(str_contains((new CockpitPage())->render(), 'Angekommen, einbuchen'), 'the cockpit itself still shows what is due');
$_GET = [];

echo "WP menu\n";
$hidden = ModulePage::menuEntries();
sort($hidden);
$pruefe($hidden === ['__navcheck-dhl', 'taxebay-shipping'], 'the module entries leave the Taxmod menu (' . implode(', ', $hidden) . ')');

echo "Module page inside the frame\n";
$_GET = ['page' => ModulePage::PAGE, 'module' => '__navcheck-dhl'];
ModulePage::load();
$pruefe($loaded === 1, "the module's own load hook runs");
$html = LogisticsShell::wrap(ModulePage::PAGE, (new ModulePage())->render(ModulePage::requested()));
$pruefe(str_contains($html, 'DHL-Seite des Moduls'), "the module's page callback is drawn");
$pruefe(str_contains($html, 'class="taxmod-shell-nav"'), 'inside the logistics navigation');
$pruefe(str_contains($html, 'is-current" aria-current="page">DHL 2</a>'), 'with the module marked as current');

add_filter(ModulePage::FILTER, static fn (array $pages): array => [...$pages, ['slug' => '__navcheck-jlc', 'label' => 'JLCPCB', 'render' => static fn (): string => '<p>JLC übergeben</p>']]);
ModulePage::capture();
$_GET = ['page' => ModulePage::PAGE, 'module' => '__navcheck-jlc'];
$html = LogisticsShell::wrap(ModulePage::PAGE, (new ModulePage())->render(ModulePage::requested()));
$pruefe(str_contains($html, 'JLC übergeben') && str_contains($html, 'aria-current="page">JLCPCB</a>'), 'a module that hands over a render callback is drawn from it, inside the frame');
$pruefe(! in_array('__navcheck-jlc', ModulePage::menuEntries(), true), 'a module announced only through the filter has no menu entry to hide');

echo "Grundeinstellungen\n";
$_GET = ['page' => SettingsPage::PAGE];
$html = LogisticsShell::wrap(SettingsPage::PAGE, (new SettingsPage())->render());
$pruefe(str_contains($html, 'aria-current="page">Grundeinstellungen</a>'), 'the page stands inside the navigation');
$pruefe(! str_contains($html, 'class="taxmod-shell-side"') && str_contains($html, 'class="taxmod-shell"'), 'a page without a box under the navigation keeps the plain navigation (D-1092)');
$pruefe(str_contains($html, 'name="taxmod_stock_not_material[0]"') && str_contains($html, 'name="taxmod_stock_tests[0]"') && str_contains($html, 'name="taxmod_stock_views[material]"'), 'it carries the business settings');
$pruefe(str_contains($html, 'value="' . SettingsPage::ACTION . '"') && str_contains($html, 'name="_wpnonce"'), 'it posts to its own handler with a nonce');
$pruefe(! str_contains($html, 'taxmod_stock_structure'), 'the structure JSON is not on it');

(new SettingsPage())->save([
    'taxmod_stock_not_material_sent'    => '1',
    'taxmod_stock_not_material'         => ['77', 'x', '', '77'],
    'taxmod_stock_tests_sent'           => '1',
    'taxmod_stock_tests'                => ['88'],
    'taxmod_stock_inbound_since'        => '2026-01-02',
    'taxmod_stock_inbound_archive_days' => '9',
    'taxmod_stock_views_sent'           => '1',
    'taxmod_stock_views'                => ['material' => '1'],
]);
$pruefe(get_option(StockService::NOT_MATERIAL) === [77], 'saved on the same option names: branches without material, sanitized (' . wp_json_encode(get_option(StockService::NOT_MATERIAL)) . ')');
$pruefe(get_option(StockService::INBOUND_SINCE) === '2026-01-02' && (int) get_option(StockService::INBOUND_ARCHIVE_DAYS) === 9, 'goods receipt since and archive days');
$pruefe((new StockService())->testSettings()->branches === [88], 'branches tested after the goods receipt');
$pruefe((new StockService())->viewSettings()->expanded('material'), 'the view of the tabs');

$tab = new TaxmodStock\WordPress\Admin\SettingsTab();
$pruefe(str_contains($tab->render(), 'taxmod_stock_structure') && ! str_contains($tab->render(), 'taxmod_stock_not_material'), 'the Configuration tab «Lager» keeps only the technical structure');

printf("\n%s\n", $fehler === 0 ? 'navigation-check: green' : "navigation-check: {$fehler} FAIL");
exit($fehler === 0 ? 0 : 1);
