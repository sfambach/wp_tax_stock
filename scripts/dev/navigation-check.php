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

use Taxmod\WordPress\Admin\ListPage;
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
    ['Einkauf', 'manage_options', \TaxmodStock\WordPress\Admin\PurchasePage::PAGE],
    ['Wareneingang', 'manage_options', Plugin::MENU],
    ['Lager', 'manage_options', StockPage::PAGE],
    // D-1193: the stock's own pages stand in the submenu as Plugin registers them, so a missing exclusion shows.
    ['Kontakte', 'manage_options', \TaxmodStock\WordPress\Admin\ContactsPage::PAGE],
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
$settingsAll = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'settings'))[0]['items'] ?? [];
// D-1193: the contact group stands in Einstellungen too; the module checks below look at the rest.
$settings    = array_values(array_filter($settingsAll, static fn (array $item): bool => ! str_starts_with((string) $item['key'], 'contact')));
$pruefe(array_slice(array_column($settings, 'key'), 0, 4) === ['basics', 'structure', 'place-types', 'module-__navcheck-dhl'] && array_filter(array_slice(array_column($settings, 'key'), 4), static fn (string $key): bool => ! str_starts_with($key, 'module-') && $key !== 'label-templates') === [], 'Einstellungen: Grundeinstellungen, Lagerstruktur › Layout and Platzarten (D-1129), then the module (' . implode(', ', array_column($settings, 'key')) . ')');
$pruefe(($settings[1]['path'] ?? []) === ['Lagerstruktur'] && ($settings[2]['path'] ?? []) === ['Lagerstruktur'] && ($settings[2]['tab'] ?? '') === 'platzarten', 'Layout and Platzarten stand in the group «Lagerstruktur»');
$pruefe(($settings[0]['page'] ?? '') === SettingsPage::PAGE, 'Grundeinstellungen is a page of the stock interface, not the modeller\'s Configuration');
$pruefe(($settings[3]['label'] ?? '') === 'DHL 2', 'the module keeps its menu label, without markup');
$outbound = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'outbound'))[0]['items'] ?? [];
$pruefe(array_column($outbound, 'module') === ['taxebay-shipping'], 'Warenausgang holds the eBay shipping page');
// His words 2026-11-03 (D-1121): «pack mal das menü drucken unter einstellungen», «kannst du das so bauen das es n ebenen zulässt».
add_filter(ModulePage::FILTER, static fn (array $pages): array => [...$pages,
    ['slug' => '__navcheck-print', 'label' => 'Aufträge', 'area' => 'print', 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-production', 'label' => 'Aufträge', 'area' => 'production', 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-deep', 'label' => 'Tief', 'area' => 'settings', 'path' => ['Ebene A', 'Ebene B'], 'render' => static fn (): string => ''],
]);
ModulePage::capture();
$areas = LogisticsShell::navigation()->areas();
$einstellungen = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'settings'))[0]['items'] ?? [];
$druck = array_values(array_filter($einstellungen, static fn (array $item): bool => ($item['module'] ?? '') === '__navcheck-print'))[0] ?? null;
$pruefe($druck !== null && ($druck['path'] ?? []) === ['Druck'] && ! in_array('print', array_column($areas, 'key'), true), 'a page announced with area «print» stands under Einstellungen › Druck, not in an area of its own');
$vorlagen = array_values(array_filter($einstellungen, static fn (array $item): bool => $item['key'] === 'label-templates'))[0] ?? null;
$pruefe(($vorlagen['path'] ?? []) === ['Druck'] && ($vorlagen['label'] ?? '') === 'Etiketten' && ($vorlagen['tab'] ?? '') === 'etikettenvorlagen' && ($vorlagen['page'] ?? '') === StockPage::PAGE, 'D-1165: Druck › Etiketten opens the Lager tab of the templates');
$bestand = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'stock'))[0]['items'] ?? [];
$platz   = array_values(array_filter($bestand, static fn (array $item): bool => $item['key'] === 'places'))[0] ?? null;
$pruefe(($platz['label'] ?? '') === 'Platzbestand' && ($platz['tab'] ?? '') === 'lagerorte', 'Bestand › Platzbestand opens what lies at a place (renamed from «Lagerorte», his word 2026-11-05)');
$pruefe(array_slice(array_column($bestand, 'key'), 0, 2) === ['material', 'group-stock'] && ($bestand[1]['tab'] ?? '') === 'gruppenbestand', 'D-1174: Bestand › Gruppenbestand stands after Material');
// D-1193, his word 2026-11-06: «kontakte nochmal in einstellungen ? finde sie da gut aber dann sollten sie aus dem andren menü raus».
$kontakte = array_values(array_filter($settingsAll, static fn (array $item): bool => str_starts_with((string) $item['key'], 'contact')));
$pruefe(! in_array('contacts', array_column($areas, 'key'), true) && ($kontakte[0]['page'] ?? '') === \TaxmodStock\WordPress\Admin\ContactsPage::PAGE && ($kontakte[0]['path'] ?? []) === ['Kontakte'] && ! empty($kontakte[0]['head']), 'D-1193: Kontakte is a group under Einstellungen only, its head opens all contacts');
$pruefe(count($kontakte) > 1 && array_unique(array_column($kontakte, 'page')) === [\TaxmodStock\WordPress\Admin\ContactsPage::PAGE] && array_filter($kontakte, static fn (array $item): bool => ($item['path'][0] ?? '') !== 'Kontakte') === [], 'D-1185: the contact nodes are the items of the group Kontakte');
// D-1193: a page the stock frames itself must never come back as a connection module — ModulePage would frame it a second time
// (his finding 2026-11-06 «warum nun zweimal menü ?» on Kontakte).
$own = [\TaxmodStock\WordPress\Admin\CockpitPage::PAGE, \TaxmodStock\WordPress\Admin\PurchasePage::PAGE, \TaxmodStock\WordPress\Plugin::MENU, \TaxmodStock\WordPress\Admin\StockPage::PAGE, \TaxmodStock\WordPress\Admin\ContactsPage::PAGE, \TaxmodStock\WordPress\Admin\SettingsPage::PAGE];
$twice = array_values(array_intersect($own, array_column(\TaxmodStock\WordPress\Admin\ModulePage::all(), 'slug')));
$pruefe($twice === [], 'no page of the stock is framed twice as a connection module' . ($twice === [] ? '' : ': ' . implode(', ', $twice)));
$fertigung = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'production'))[0]['items'] ?? [];
$pruefe(array_column($fertigung, 'key') === ['project-boxes', 'module-__navcheck-production'], 'D-1142: a page announced with area «production» stands under Fertigung, after Projekt-Gebinde');
$_GET = ['page' => ModulePage::PAGE, 'module' => '__navcheck-deep'];
$tief = LogisticsShell::wrap(ModulePage::PAGE, '<p>x</p>');
$pruefe(substr_count($tief, 'taxmod-shell-group is-open') === 2 && str_contains($tief, '>Ebene B</a><ul><li><a') && str_contains($tief, 'aria-current="page">Tief</a>'), 'a page with a path of two groups stands three levels deep, its groups open (n levels)');
// D-1128, his word «auf dem hauptknoten eine übersicht zu bekommen mit abstprung»: an area and a group open an overview of tiles.
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'stock'];
$uebersicht = LogisticsShell::wrap(CockpitPage::PAGE, (new CockpitPage())->render());
$pruefe(str_contains($uebersicht, 'taxmod-shell-area is-open"><a href="' . esc_url(add_query_arg(LogisticsShell::VIA_MENU, '1', CockpitPage::overviewUrl('stock'))) . '">Bestand</a>') && substr_count($uebersicht, 'taxmod-tile-label"') === 6 && str_contains($uebersicht, '<span class="taxmod-tile-label">Platzbestand</span>'), 'Bestand opens an overview with a tile for each of its six items, the area open in the navigation');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'settings'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, esc_attr(CockpitPage::overviewUrl('settings', 'Lagerstruktur'))) && substr_count($uebersicht, '>Lagerstruktur</span>') === 1, 'a group (Lagerstruktur) is one tile that opens its own overview');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'settings', CockpitPage::GROUP => 'Lagerstruktur'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, '>Layout</span>') && str_contains($uebersicht, '>Platzarten</span>') && ! str_contains($uebersicht, '>Grundeinstellungen</span>'), 'the group overview shows only its own items');
$nav = LogisticsShell::wrap(CockpitPage::PAGE, $uebersicht);
$pruefe(str_contains($nav, 'taxmod-shell-group is-open"><a href="' . esc_url(add_query_arg(LogisticsShell::VIA_MENU, '1', CockpitPage::overviewUrl('settings', 'Lagerstruktur'))) . '" style="padding-left:24px" class="is-current"'), 'D-1134: the group\'s overview opens the group in the navigation, its sub-items shown');
$pruefe(substr_count($uebersicht, 'class="taxmod-tile-figure"') === 2, 'D-1134: Layout and Platzarten show how many there are');
// D-1258, his word «auf den hauptseiten mit den tiles sollte als erstes die prozess kette stehen also für we und für wa».
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'inbound'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, 'class="taxmod-steps"') && strpos($uebersicht, 'class="taxmod-steps"') < strpos($uebersicht, 'taxmod-tile-label"') && ! str_contains($uebersicht, 'taxmod-steps-all'), 'D-1258: the Wareneingang overview opens with its process bar, before the tiles, no «Alle» arrow');
$haken = static fn (string $html, string $area): string => $area === 'outbound' ? '<nav class="taxmod-steps">WA</nav>' : $html;
add_filter(CockpitPage::PROCESS_BAR, $haken, 10, 2);
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'outbound'];
$pruefe(str_contains((new CockpitPage())->render(), '<nav class="taxmod-steps">WA</nav>'), 'D-1258: the Warenausgang overview shows the bar its module hands over');
remove_filter(CockpitPage::PROCESS_BAR, $haken, 10);
// D-1242: the reorder list is named for what it holds.
$pruefe(in_array('Mindestbestand unterschritten', array_column(LogisticsShell::navigation()->areas()[1]['items'], 'label'), true), 'D-1242: «Nachbestellen» in the menu reads «Mindestbestand unterschritten»');

// D-1241: the cockpit is one item; what is due and the period (D-1167) stand on its one page, the period below.
$_GET    = ['page' => CockpitPage::PAGE];
$cockpit = (new CockpitPage())->render();
$pruefe(array_column(LogisticsShell::navigation()->areas()[0]['items'], 'key') === ['cockpit'], 'D-1241: the cockpit area has one item, «Was ansteht» and «Zeitraum» are gone from the menu');
$pruefe(str_contains($cockpit, 'Angekommen, einbuchen') && str_contains($cockpit, 'id="taxmod-period"') && strpos($cockpit, 'Angekommen, einbuchen') < strpos($cockpit, 'id="taxmod-period"') && str_contains($cockpit, 'class="taxmod-period"'), 'D-1241: the cockpit shows what is due, the period below it with its switch');
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

echo "Bereich mit einem Punkt\n";
// D-1241, his word 2026-11-09: «cock pit nun in weiteren unterpunkt cockpit ds sollte so nicht sein nur cockpit einmal».
$_GET   = ['page' => CockpitPage::PAGE];
$navi   = LogisticsShell::wrap(CockpitPage::PAGE, (new CockpitPage())->render());
$pruefe(substr_count($navi, '>Cockpit</a>') === 1 && str_contains($navi, 'class="is-current" aria-current="page">Cockpit</a>'), 'D-1241: Cockpit stands once, as the current area, with no item «Cockpit» below it');
$zwei   = new \TaxmodStock\Core\Navigation([['key' => 'one', 'label' => 'Eins', 'items' => [['key' => 'a', 'label' => 'A', 'page' => 'p', 'tab' => '']]],
    ['key' => 'two', 'label' => 'Zwei', 'items' => [['key' => 'b', 'label' => 'B', 'page' => 'p', 'tab' => 'b'], ['key' => 'c', 'label' => 'C', 'page' => 'p', 'tab' => 'c']]]]);
$menu   = new ReflectionMethod(LogisticsShell::class, 'menu');
$menu->setAccessible(true);
$gezeigt = (string) $menu->invoke(null, $zwei, ['area' => 'two', 'item' => 'b', 'group' => '']);
$pruefe(! str_contains($gezeigt, '>A</a>') && str_contains($gezeigt, '>B</a>') && str_contains($gezeigt, '>C</a>') && str_contains($gezeigt, esc_url(add_query_arg(LogisticsShell::VIA_MENU, '1', CockpitPage::overviewUrl('two')))),
    'the rule is the count of items, not a name: one item shows no list, a second brings the list and the overview back');

echo "Benutzereinstellungen\n";
// D-1232, his question 2026-11-08: «was ist eigentlich mit dem Benutzereinstellungen los? Die sind noch nicht sichtbar in den Einstellungsmenü.»
$_GET = ['page' => SettingsPage::PAGE, 'tab' => SettingsPage::USER_TAB];
$html = LogisticsShell::wrap(SettingsPage::PAGE, (new SettingsPage())->render());
$pruefe(str_contains($html, 'aria-current="page">Benutzereinstellungen</a>') && str_contains($html, 'name="fullscreen"') && str_contains($html, 'value="' . SettingsPage::USER_ACTION . '"'), 'D-1232: Einstellungen › Benutzereinstellungen is in the navigation and carries this person\'s settings');
(new SettingsPage())->saveUser(true, false);
$pruefe(LogisticsShell::isFullscreen(), 'D-1232: «Vollbild» set there is the same switch as the one under the navigation');
(new SettingsPage())->saveUser(false, false);
$pruefe(! LogisticsShell::isFullscreen(), 'D-1232: and it can be switched off again');
$pruefe(! method_exists(ListPage::class, 'resetTreeStates') || str_contains($html, 'name="reset_trees"'), 'his wish 2026-11-09: the kept trees can be forgotten there');

echo "Baum links\n";
// His wish 2026-11-09: «hier fange ich immer neu an … button wie collapse all usw fehlen» — the modeller's one side tree.
$_GET = ['page' => StockPage::PAGE, 'tab' => 'gruppenbestand'];
$parts = (new StockPage())->parts();
$html  = LogisticsShell::wrap(StockPage::PAGE, $parts['main'], $parts['side']);
$pruefe(! method_exists(ListPage::class, 'sideTree') || ! str_contains($parts['side'], 'class="taxmod-chooser') || (str_contains($parts['side'], 'data-taxmod-tree="taxmod_stock_groups"') && str_contains($parts['side'], 'taxmod-side-tree-collapse')), 'Gruppenbestand: the tree on the left is the modeller\'s side tree, with «Alle aufklappen / zuklappen» and the kept state');
$pruefe(str_contains($html, 'grid-template-columns:300px'), 'his word «das genaze menü etwas breiter machen 1,5 mal so viel»: the left column is 300 px');

echo "Grundeinstellungen\n";
$_GET = ['page' => SettingsPage::PAGE];
$html = LogisticsShell::wrap(SettingsPage::PAGE, (new SettingsPage())->render());
$pruefe(str_contains($html, 'aria-current="page">Grundeinstellungen</a>'), 'the page stands inside the navigation');
$pruefe(! str_contains($html, 'class="taxmod-shell-side"') && str_contains($html, 'class="taxmod-shell"'), 'a page without a box under the navigation keeps the plain navigation (D-1092)');
$pruefe(str_contains($html, 'name="taxmod_stock_not_material[0]"') && str_contains($html, 'name="taxmod_stock_test_place"') && ! str_contains($html, 'name="taxmod_stock_tests[0]"') && str_contains($html, 'name="taxmod_stock_views[material]"'), 'it carries the business settings');
$pruefe(str_contains($html, 'value="' . SettingsPage::ACTION . '"') && str_contains($html, 'name="_wpnonce"'), 'it posts to its own handler with a nonce');
$pruefe(! str_contains($html, 'taxmod_stock_structure'), 'the structure JSON is not on it');

(new SettingsPage())->save([
    'taxmod_stock_not_material_sent'    => '1',
    'taxmod_stock_not_material'         => ['77', 'x', '', '77'],
    'taxmod_stock_tests_sent'           => '1',
    'taxmod_stock_inbound_since'        => '2026-01-02',
    'taxmod_stock_inbound_archive_days' => '9',
    'taxmod_stock_views_sent'           => '1',
    'taxmod_stock_views'                => ['material' => '1'],
]);
$pruefe(get_option(StockService::NOT_MATERIAL) === [77], 'saved on the same option names: branches without material, sanitized (' . wp_json_encode(get_option(StockService::NOT_MATERIAL)) . ')');
$pruefe(get_option(StockService::INBOUND_SINCE) === '2026-01-02' && (int) get_option(StockService::INBOUND_ARCHIVE_DAYS) === 9, 'goods receipt since and archive days');
$pruefe(get_option(StockService::TESTS) === ['place' => null], 'D-1259: the test settings hold the test place only; the tested groups are a group property');
$pruefe((new StockService())->viewSettings()->expanded('material'), 'the view of the tabs');

$tab = new TaxmodStock\WordPress\Admin\SettingsTab();
$pruefe(str_contains($tab->render(), 'taxmod_stock_structure') && ! str_contains($tab->render(), 'taxmod_stock_not_material'), 'the Configuration tab «Lager» keeps only the technical structure');

printf("\n%s\n", $fehler === 0 ? 'navigation-check: green' : "navigation-check: {$fehler} FAIL");
exit($fehler === 0 ? 0 : 1);
