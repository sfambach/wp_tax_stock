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

// Grenzlauf 2026-11-17 (modeller D-1427): on a database without the owner's structure the check builds the small one stock-check uses.
global $wpdb;
$lagerKnoten = (int) ((get_option(StockService::OPTION, [])['nodes']['lager'] ?? 0));

// Prüftor 2026-11-21: a stored structure left by an older run can name a «lager» node that still stands but lack the contact
// branch (D-1185) or the material field (the business settings) — then the check builds its own too; the bracket rolls it back.
$gespeichert = (array) get_option(StockService::OPTION, []);
$unvollstaendig = empty($gespeichert['nodes']['contact']) || empty($gespeichert['fields']['lager_thing'])
    || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}taxmod_nodes WHERE id = %d", (int) ($gespeichert['nodes']['contact'] ?? 0))) === 0;

if ($lagerKnoten === 0 || $unvollstaendig || (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}taxmod_nodes WHERE id = %d", $lagerKnoten)) === 0) {
    $kind = 'aggregation';
    require __DIR__ . '/lib/struktur.php';

    // D-1185: a contact branch with two nodes, so the group Kontakte has items.
    $kontaktAst = $apply([
        ['op' => 'create_node', 'ref' => 'contact', 'name' => '__Kontakte', 'parent' => 402],
        ['op' => 'create_node', 'name' => '__Firmen', 'parent' => '@contact'],
        ['op' => 'create_node', 'name' => '__Personen', 'parent' => '@contact'],
    ]);
    $struktur['nodes']['contact'] = (int) $kontaktAst['refs']['contact'];
    update_option(StockService::OPTION, $struktur);
}

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
    ['Auswahllisten', 'manage_options', \TaxmodStock\WordPress\Admin\ChoiceListsPage::PAGE],
    ['Grundeinstellungen', 'manage_options', SettingsPage::PAGE],
    // D-1558: the one rules page, and the old test rules address that leads there.
    ['Regeln', 'manage_options', \TaxmodStock\WordPress\Admin\Pages::RULES],
    ['Testregeln', 'manage_options', \TaxmodStock\WordPress\Admin\Pages::TEST_RULES],
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
$settings   = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'settings'))[0]['items'] ?? [];
$stammdaten = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'masterdata'))[0]['items'] ?? [];
// D-1472 (replaces the place of D-1193): Einstellungen holds no contact item any more.
$pruefe(array_filter($settings, static fn (array $item): bool => str_starts_with((string) $item['key'], 'contact')) === [], 'D-1472: Einstellungen holds no Kontakte');
// Grenzlauf 2026-11-17 (modeller D-1427, PR-9): «Drucker» (D-1192) and «Benutzereinstellungen» (D-1232) stand there since; the list names them.
// D-1512 (replaces the place D-1129 gave it, PR-9): Lagerstruktur left Einstellungen for Stammdaten.
$pruefe(array_slice(array_column($settings, 'key'), 0, 2) === ['basics', 'module-__navcheck-dhl'] && array_filter(array_slice(array_column($settings, 'key'), 2), static fn (string $key): bool => ! str_starts_with($key, 'module-') && ! str_starts_with($key, 'rules-') && ! in_array($key, ['field-catalogue', 'user'], true)) === [], 'Einstellungen: Grundeinstellungen, then the module — no Lagerstruktur any more (D-1512) (' . implode(', ', array_column($settings, 'key')) . ')');
$lagerstruktur = array_values(array_filter($stammdaten, static fn (array $item): bool => ($item['path'] ?? []) === ['Lagerstruktur']));
$pruefe(array_column($lagerstruktur, 'key') === ['structure', 'place-types'] && ($lagerstruktur[1]['tab'] ?? '') === 'platzarten' && array_search('structure', array_column($stammdaten, 'key'), true) === array_search('choice-lists', array_column($stammdaten, 'key'), true) + 1, 'D-1512: Layout and Platzarten stand in the group «Lagerstruktur» under Stammdaten, after Auswahllisten');
// D-1574 (changes the place check above from «third» to «after Auswahllisten», PR-9): Materialgruppen stand after Material, each the
// modeller's node page scoped to one branch «Material steht in».
$gruppen = array_values(array_filter($stammdaten, static fn (array $item): bool => str_starts_with((string) $item['key'], 'material-groups')));
$roots   = (new \TaxmodStock\WordPress\StockService())->materialRoots();
$pruefe(count($gruppen) === count($roots) && array_search($gruppen[0]['key'] ?? '', array_column($stammdaten, 'key'), true) === ($roots === [] ? false : 1)
    && array_filter($gruppen, static fn (array $item): bool => $item['page'] !== \TaxmodStock\WordPress\Admin\Pages::MATERIAL_GROUPS || (count($roots) > 1 && ! in_array((int) $item['tab'], $roots, true))) === [],
    'D-1574, Live-Check 15 (changes the claim «opens the modeller», PR-9): one «Materialgruppen» item per material branch, after Material, each a page of this template naming its branch (' . count($roots) . ' branches)');
if ($roots !== []) {
    $_GET['taxmod_collapsed'] = 'none';
    $seite = (new \TaxmodStock\WordPress\Admin\MaterialGroupsPage())->render();
    unset($_GET['taxmod_collapsed']);
    $pruefe(str_contains($seite, 'id="taxmod-node-' . $roots[0] . '"') && str_contains($seite, 'page=' . \TaxmodStock\WordPress\Admin\Pages::MATERIAL_GROUPS),
        'D-1574, Live-Check 15: the page draws the modeller\'s tree of the first material branch, and its links come back to this page');
    $pruefe(in_array(\TaxmodStock\WordPress\Admin\Pages::MATERIAL_GROUPS, (array) apply_filters('taxmod_node_screen_hosts', []), true), 'D-1574: the page is registered as a host of the node page, so the modeller takes it as the way back');
    $back  = add_query_arg(['page' => \TaxmodStock\WordPress\Admin\Pages::MATERIAL_GROUPS, 'taxmod_node' => (string) $roots[0], 'taxmod_scope' => (string) $roots[0], 'taxmod_collapsed' => 'none'], admin_url('admin.php'));
    $_GET[\Taxmod\WordPress\Admin\ListPage::SHOW_ALL] = '1';
    $reiter = (array) apply_filters('taxmod_scoped_tabs', [], $roots[0], $roots[0], $back);
    unset($_GET[\Taxmod\WordPress\Admin\ListPage::SHOW_ALL]);
    $mat    = array_values(array_filter($reiter, static fn (mixed $t): bool => is_array($t) && ($t['key'] ?? '') === 'materials'))[0] ?? [];
    $pruefe(($mat['label'] ?? '') === 'Materialien' && str_contains((string) ($mat['html'] ?? ''), 'name="host_return"')
        && ! str_contains((string) ($mat['html'] ?? ''), 'page=' . \TaxmodStock\WordPress\Admin\StockPage::PAGE . '&amp;tab=gruppenbestand'),
        'D-1574 (Prüftor C): the scoped node page gets the tab «Materialien» — the group\'s list, its bulk form going back to Materialgruppen, no address on «Gruppenbestand»');
    $pruefe(array_filter((array) apply_filters('taxmod_scoped_tabs', [], $roots[0], 999999999, $back), static fn (mixed $t): bool => is_array($t) && ($t['key'] ?? '') === 'materials') === [],
        'D-1574: a branch that is no «Material steht in» gets no «Materialien»');
}
// D-1558 (replaces D-1541's place, PR-9, visible change): Einstellungen › Regeln holds one item per rule type, each opening the one
// rules page with that type; Stammdaten › Test › Testregeln opens the same page at the test rules.
$regeln = array_values(array_filter($settings, static fn (array $item): bool => str_starts_with((string) $item['key'], 'rules-')));
$pruefe(! (new TaxmodStock\WordPress\StockService())->structure()->hasNode('rules_root')
    || ($regeln !== [] && array_filter($regeln, static fn (array $item): bool => $item['page'] !== TaxmodStock\WordPress\Admin\Pages::RULES || ($item['path'] ?? []) !== ['Regeln'] || $item['tab'] === '') === []),
    'D-1558: Einstellungen › Regeln › one item per type, each the one rules page with its type (' . implode(', ', array_column($regeln, 'label')) . ')');
$test = array_values(array_filter($stammdaten, static fn (array $item): bool => $item['key'] === 'test-rules'));
$pruefe(count($test) === 1 && ($test[0]['path'] ?? []) === ['Test'] && $test[0]['page'] === TaxmodStock\WordPress\Admin\Pages::RULES && ! in_array('test-rules', array_column($settings, 'key'), true),
    'D-1558: Stammdaten › Test › Testregeln opens the one rules page; Einstellungen has no Testregeln item of its own');
$pruefe(in_array('field-catalogue', array_column($settings, 'key'), true) && (array_values(array_filter($settings, static fn (array $item): bool => $item['key'] === 'field-catalogue'))[0]['page'] ?? '') === TaxmodStock\WordPress\Admin\Pages::FIELD_CATALOGUE, 'D-1555: Einstellungen › Feldkatalog is a page of the stock interface');
$pruefe(($settings[0]['page'] ?? '') === SettingsPage::PAGE, 'Grundeinstellungen is a page of the stock interface, not the modeller\'s Configuration');
$pruefe(($settings[1]['label'] ?? '') === 'DHL 2', 'the module keeps its menu label, without markup');
$outbound = array_values(array_filter($navigation->areas(), static fn (array $area): bool => $area['key'] === 'outbound'))[0]['items'] ?? [];
// D-1528 (PR-9, visible change): the sales module adds its stage pages to Warenausgang, so the eBay page is one of them, not the only one.
$pruefe(in_array('taxebay-shipping', array_column($outbound, 'module'), true), 'Warenausgang holds the eBay shipping page');
// His words 2026-11-03 (D-1121): «pack mal das menü drucken unter einstellungen», «kannst du das so bauen das es n ebenen zulässt».
add_filter(ModulePage::FILTER, static fn (array $pages): array => [...$pages,
    ['slug' => '__navcheck-print', 'label' => 'Aufträge', 'area' => 'print', 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-production', 'label' => 'Aufträge', 'area' => 'production', 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-master', 'label' => 'Plätze', 'area' => 'masterdata', 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-deep', 'label' => 'Tief', 'area' => 'settings', 'path' => ['Ebene A', 'Ebene B'], 'render' => static fn (): string => ''],
]);
ModulePage::capture();
$areas = LogisticsShell::navigation()->areas();
$einstellungen = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'settings'))[0]['items'] ?? [];
$druck = array_values(array_filter($einstellungen, static fn (array $item): bool => ($item['module'] ?? '') === '__navcheck-print'))[0] ?? null;
// D-1502 Stufe 3, his word 2026-11-20 «ein Arbeitsplatz ist ein Stammdatum»: a module page with area «masterdata» stands under Stammdaten, after Lagerstruktur.
$stamm = array_column(array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'masterdata'))[0]['items'] ?? [], 'key');
$kontakt = array_values(array_filter(array_keys($stamm), static fn (int $k): bool => str_starts_with((string) $stamm[$k], 'contact')))[0] ?? count($stamm);
$pruefe(($i = array_search('module-__navcheck-master', $stamm, true)) !== false && $i > array_search('place-types', $stamm, true) && $i < $kontakt && ! in_array('module-__navcheck-master', array_column($einstellungen, 'key'), true), 'D-1518: a master-data page of a module stands under Stammdaten between Lagerstruktur and Kontakte (' . implode(', ', $stamm) . ')');
$pruefe($druck !== null && ($druck['path'] ?? []) === ['Druck'] && ! in_array('print', array_column($areas, 'key'), true), 'a page announced with area «print» stands under Einstellungen › Druck, not in an area of its own');
// D-1296, his report 2026-11-18 «etiketten gibts jetzt zweimal als menüpunkt», «drucker auch»: labels, printers and rolls are the
// labels module's. INF-137 (changes this check, PR-9): «Etiketten je Bauform» went with the old preselection.
$pruefe(array_filter($einstellungen, static fn (array $item): bool => $item['key'] === 'form-labels') === [], 'INF-137: Druck has no «Etiketten je Bauform» any more');
$pruefe(array_filter($einstellungen, static fn (array $item): bool => in_array($item['key'], ['label-templates', 'printers'], true)) === [], 'D-1296: the stock offers no «Etiketten» or «Drucker» of its own');
$bestand = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'stock'))[0]['items'] ?? [];
$platz   = array_values(array_filter($bestand, static fn (array $item): bool => $item['key'] === 'places'))[0] ?? null;
$pruefe(($platz['label'] ?? '') === 'Platzbestand' && ($platz['tab'] ?? '') === 'lagerorte', 'Bestand › Platzbestand opens what lies at a place (renamed from «Lagerorte», his word 2026-11-05)');
$pruefe(($bestand[0]['key'] ?? '') === 'group-stock' && ($bestand[0]['tab'] ?? '') === 'gruppenbestand' && ! in_array('material', array_column($bestand, 'key'), true), 'D-1174, D-1472: Bestand opens with Gruppenbestand; Material stands under Stammdaten');
// D-1472, his word 2026-11-19: «Stammdaten sollen ein eigenes Stammdatenbereich bekommen. Ja, die Kontakte werden das gleiche.»
$areaKeys = array_column($areas, 'key');
$pruefe(array_search('masterdata', $areaKeys, true) === array_search('outbound', $areaKeys, true) + 1 && array_search('settings', $areaKeys, true) === array_search('masterdata', $areaKeys, true) + 1, 'D-1472: Stammdaten stands between Warenausgang and Einstellungen (' . implode(', ', $areaKeys) . ')');
$pruefe(($stammdaten[0]['key'] ?? '') === 'material' && ($stammdaten[0]['page'] ?? '') === StockPage::PAGE && ($stammdaten[0]['tab'] ?? '') === 'material', 'D-1472: Stammdaten › Material keeps its address (page=taxmod-stock, tab=material)');
$pruefe($navigation->active(StockPage::PAGE, 'material') === ['area' => 'masterdata', 'item' => 'material'], 'D-1472: a material page marks Stammdaten as the current area');
$pruefe(($stammdaten[1]['key'] ?? '') === 'choice-lists' && ($stammdaten[1]['page'] ?? '') === \TaxmodStock\WordPress\Admin\ChoiceListsPage::PAGE && $navigation->active(\TaxmodStock\WordPress\Admin\ChoiceListsPage::PAGE, '') === ['area' => 'masterdata', 'item' => 'choice-lists'], 'D-1474: Stammdaten › Auswahllisten follows Material and marks Stammdaten');
$kontakte = array_values(array_filter($stammdaten, static fn (array $item): bool => str_starts_with((string) $item['key'], 'contact')));
$pruefe(! in_array('contacts', $areaKeys, true) && ($kontakte[0]['page'] ?? '') === \TaxmodStock\WordPress\Admin\ContactsPage::PAGE && ($kontakte[0]['path'] ?? []) === ['Kontakte'] && ! empty($kontakte[0]['head']), 'D-1472: Kontakte is a group under Stammdaten only, its head opens all contacts');
$pruefe(count($kontakte) > 1 && array_unique(array_column($kontakte, 'page')) === [\TaxmodStock\WordPress\Admin\ContactsPage::PAGE] && array_filter($kontakte, static fn (array $item): bool => ($item['path'][0] ?? '') !== 'Kontakte') === [], 'D-1185: the contact nodes are the items of the group Kontakte');
// D-1193: a page the stock frames itself must never come back as a connection module — ModulePage would frame it a second time
// (his finding 2026-11-06 «warum nun zweimal menü ?» on Kontakte).
$own = [\TaxmodStock\WordPress\Admin\CockpitPage::PAGE, \TaxmodStock\WordPress\Admin\PurchasePage::PAGE, \TaxmodStock\WordPress\Plugin::MENU, \TaxmodStock\WordPress\Admin\StockPage::PAGE, \TaxmodStock\WordPress\Admin\ContactsPage::PAGE, \TaxmodStock\WordPress\Admin\ChoiceListsPage::PAGE, \TaxmodStock\WordPress\Admin\SettingsPage::PAGE];
$twice = array_values(array_intersect($own, array_column(\TaxmodStock\WordPress\Admin\ModulePage::all(), 'slug')));
$pruefe($twice === [], 'no page of the stock is framed twice as a connection module' . ($twice === [] ? '' : ': ' . implode(', ', $twice)));
$fertigung = array_values(array_filter($areas, static fn (array $area): bool => $area['key'] === 'production'))[0]['items'] ?? [];
$pruefe(array_column($fertigung, 'key') === ['project-boxes', 'module-__navcheck-production'], 'D-1142: a page announced with area «production» stands under Fertigung, after Projekt-Gebinde');
$_GET = ['page' => ModulePage::PAGE, 'module' => '__navcheck-deep'];
$tief = LogisticsShell::wrap(ModulePage::PAGE, '<p>x</p>');
$pruefe(substr_count($tief, 'taxmod-shell-group is-open') === 2 && str_contains($tief, '>Ebene B</a><ul><li><a') && str_contains($tief, 'aria-current="page">Tief</a>'), 'a page with a path of two groups stands three levels deep, its groups open (n levels)');
// D-1521, his word 2026-11-20 «die Box rechts, die verschwindet ein bisschen aus dem Bild raus»: the shell keeps WordPress's right margin.
$pruefe((bool) preg_match('~\.taxmod-shell\{[^}]*margin:10px 20px 0 0~', $tief), 'D-1521: the shell keeps a right margin, a box on the right stays inside the screen');
$pruefe(str_contains($tief, '<div class="taxmod-shell-column"><div class="taxmod-shell-head">Tax Logistik</div><nav') && ! str_contains($tief, 'taxmod-shell-title">Logistik') && (bool) preg_match('~\.taxmod-shell-head,\.taxmod-shell-main>h2:first-child\{[^}]*min-height:var\(--taxmod-head-height~', $tief), 'D-1521: a box as high as the page head stands above the menu, so menu and content start on one line');
$pruefe(str_contains($tief, '.taxmod-shell-main{overflow-x:auto;position:relative}'), 'Live-Check 16: what scrolls beside the menu is the frame of its absolute children, so none widens the page');
$seite = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/LogisticsShell.php');
$pruefe(str_contains($seite, ".taxmod-shell-side .taxmod-record-tree{max-height:none;overflow:hidden;padding:0}") && str_contains($seite, '.taxmod-shell-side .taxmod-chooser-tree{flex:1 1 auto;min-height:0;max-height:none;overflow-x:hidden;overflow-y:auto') && str_contains($seite, '.taxmod-shell-side{flex:1 1 auto;min-height:max(16em,70vh);'), 'D-1575: the tree box beside the menu is at least 70vh high and has exactly one scroller, the tree list');
// D-1128, his word «auf dem hauptknoten eine übersicht zu bekommen mit abstprung»: an area and a group open an overview of tiles.
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'stock'];
$uebersicht = LogisticsShell::wrap(CockpitPage::PAGE, (new CockpitPage())->render());
$pruefe(str_contains($uebersicht, 'taxmod-shell-area is-open"><a href="' . esc_url(add_query_arg(LogisticsShell::VIA_MENU, '1', CockpitPage::overviewUrl('stock'))) . '">Bestand</a>') && substr_count($uebersicht, 'taxmod-tile-label"') === 5 && str_contains($uebersicht, '<span class="taxmod-tile-label">Platzbestand</span>'), 'Bestand opens an overview with a tile for each of its five items (D-1472: Material moved to Stammdaten), the area open in the navigation');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'masterdata'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, '<span class="taxmod-tile-label">Material</span>') && str_contains($uebersicht, 'taxmod-material-search'), 'D-1472: Stammdaten opens an overview with a Material tile and the material search');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'masterdata'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, esc_attr(CockpitPage::overviewUrl('masterdata', 'Lagerstruktur'))) && substr_count($uebersicht, '>Lagerstruktur</span>') === 1, 'a group (Lagerstruktur) is one tile that opens its own overview');
$_GET = ['page' => CockpitPage::PAGE, CockpitPage::AREA => 'masterdata', CockpitPage::GROUP => 'Lagerstruktur'];
$uebersicht = (new CockpitPage())->render();
$pruefe(str_contains($uebersicht, '>Layout</span>') && str_contains($uebersicht, '>Platzarten</span>') && ! str_contains($uebersicht, '>Grundeinstellungen</span>'), 'the group overview shows only its own items');
$nav = LogisticsShell::wrap(CockpitPage::PAGE, $uebersicht);
$pruefe(str_contains($nav, 'taxmod-shell-group is-open"><a href="' . esc_url(add_query_arg(LogisticsShell::VIA_MENU, '1', CockpitPage::overviewUrl('masterdata', 'Lagerstruktur'))) . '" style="padding-left:24px" class="is-current"'), 'D-1134: the group\'s overview opens the group in the navigation, its sub-items shown');
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

// D-1502: a module adds its own line (the labels module: «Arbeitsplatz»); a line without its parts stays out.
$gespeichert = 0;
$zeile       = static function (array $lines) use (&$gespeichert): array {
    return array_merge($lines, [
        ['label' => '__nc Platz', 'control' => '<select name="__nc_platz"></select>', 'save' => static function () use (&$gespeichert): void { $gespeichert++; }],
        ['label' => '__nc kaputt', 'control' => '<b>x</b>'],
    ]);
};
add_filter(SettingsPage::USER_SETTINGS_FILTER, $zeile);
$html = (new SettingsPage())->render();
$pruefe(str_contains($html, '__nc Platz</th><td><select name="__nc_platz">') && ! str_contains($html, '__nc kaputt'), 'D-1502: a module\'s line stands in Benutzereinstellungen, a broken one stays out');
foreach (SettingsPage::moduleSettings() as $line) {
    ($line['save'])();
}
$pruefe($gespeichert === 1, 'D-1502: saving the form asks the module to keep its own field');
remove_filter(SettingsPage::USER_SETTINGS_FILTER, $zeile);
if (class_exists(\TaxmodLabel\WordPress\Admin\WorkplacesPage::class)) {
    // Prüftor 2026-11-20: the role «Arbeitsplätze» points at a node this database does not have — the page stands, no line, no workplace.
    $datei = (string) file_get_contents(dirname((string) (new \ReflectionClass(\TaxmodLabel\WordPress\LabelService::class))->getFileName(), 3) . '/structure-live.json');
    $tot   = static function () use ($datei): array {
        $struktur                             = (array) json_decode($datei, true);
        $struktur['nodes']['label_workplace'] = 999999999990;

        return $struktur;
    };
    add_filter('pre_option_' . \TaxmodLabel\WordPress\LabelService::STRUCTURE, $tot);
    $html = (new SettingsPage())->render();
    $pruefe(str_contains($html, 'name="_wpnonce"') && ! str_contains($html, 'name="' . \TaxmodLabel\WordPress\LabelService::WORKPLACE_META . '"'), 'D-1502: a role pointing at a missing node leaves Benutzereinstellungen standing, without the line');
    $pruefe((new \TaxmodLabel\WordPress\LabelService())->workplace() === null && \TaxmodLabel\WordPress\Admin\WorkplacesPage::userSetting([]) === [], 'D-1502: and it is «kein Arbeitsplatz», not an error');
    remove_filter('pre_option_' . \TaxmodLabel\WordPress\LabelService::STRUCTURE, $tot);
} else {
    echo "  (Etikettenmodul nicht aktiv: Fall «toter Verweis Arbeitsplätze» übersprungen)\n";
}

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
$pruefe(str_contains($html, 'name="taxmod_stock_material_in[0]"') && str_contains($html, 'name="taxmod_stock_test_place"') && ! str_contains($html, 'name="taxmod_stock_tests[0]"') && str_contains($html, 'name="taxmod_stock_views[material]"'), 'it carries the business settings');
$pruefe(str_contains($html, 'value="' . SettingsPage::ACTION . '"') && str_contains($html, 'name="_wpnonce"'), 'it posts to its own handler with a nonce');
$pruefe(! str_contains($html, 'taxmod_stock_structure'), 'the structure JSON is not on it');

(new SettingsPage())->save([
    'taxmod_stock_material_in_sent'     => '1',
    'taxmod_stock_material_in'          => ['77', 'x', '', '77'],
    'taxmod_stock_tests_sent'           => '1',
    'taxmod_stock_inbound_since'        => '2026-01-02',
    'taxmod_stock_inbound_archive_days' => '9',
    'taxmod_stock_views_sent'           => '1',
    'taxmod_stock_views'                => ['material' => '1'],
]);
$pruefe(get_option(StockService::MATERIAL_IN) === [77], 'D-1522 (changed visibly, was «Kein Material»): saved as «Material steht in», sanitized (' . wp_json_encode(get_option(StockService::MATERIAL_IN)) . ')');
delete_option(StockService::MATERIAL_IN);
$pruefe(get_option(StockService::INBOUND_SINCE) === '2026-01-02' && (int) get_option(StockService::INBOUND_ARCHIVE_DAYS) === 9, 'goods receipt since and archive days');
$pruefe(get_option(StockService::TESTS) === ['place' => null], 'D-1259: the test settings hold the test place only; the tested groups are a group property');
$pruefe((new StockService())->viewSettings()->expanded('material'), 'the view of the tabs');

$tab = new TaxmodStock\WordPress\Admin\SettingsTab();
$pruefe(str_contains($tab->render(), 'taxmod_stock_structure') && ! str_contains($tab->render(), 'taxmod_stock_not_material'), 'the Configuration tab «Lager» keeps only the technical structure');

// D-1372 (ergänzt D-1128), his report 2026-11-14 «warum so und nicht so»: a page a module marks `opens_area` is what its area's
// head opens instead of the tile overview; where two mark one, the first by position wins.
add_filter(ModulePage::FILTER, static fn (array $pages): array => [...$pages,
    ['slug' => '__navcheck-head-1', 'label' => 'Kopf 1', 'area' => 'production', 'opens_area' => true, 'render' => static fn (): string => ''],
    ['slug' => '__navcheck-head-2', 'label' => 'Kopf 2', 'area' => 'production', 'opens_area' => true, 'render' => static fn (): string => ''],
]);
ModulePage::capture();
$_GET = ['page' => CockpitPage::PAGE];
$kopf = LogisticsShell::wrap(CockpitPage::PAGE, '<p>x</p>');
$pruefe(preg_match('/<a href="[^"]*module=__navcheck-head-1[^"]*">Fertigung<\/a>/', $kopf) === 1 && preg_match('/<a href="[^"]*area=stock[^"]*">Bestand<\/a>/', $kopf) === 1,
    'D-1372: Fertigung opens the page its module marks (the first of two), Bestand still its overview');

// D-1483: a record this module keeps opens on its page wherever the modeller links to it — by its own node, and from a node above.
$heim  = new StockService();
$heimS = $heim->structure();

foreach (['order' => 'page=taxmod-logistik&order=', 'location' => 'tab=lagerstruktur&ort=', 'lager' => 'thing=', 'lot' => 'thing='] as $key => $erwartet) {
    $id = $heimS->hasNode($key) ? (array_map('intval', $heim->table($key)->ids())[0] ?? 0) : 0;

    if ($id === 0) {
        $pruefe(true, "D-1483: no {$key} record here — nothing to open");
        continue;
    }

    $mitKnoten = (string) apply_filters('taxmod_record_home', null, $id, $heim->nodesOf([$id])[$id] ?? 0);
    $ohne      = (string) apply_filters('taxmod_record_home', null, $id, 402);
    $pruefe(str_contains($mitKnoten, $erwartet) && $mitKnoten === $ohne, "D-1483: {$key} #{$id} opens at {$erwartet}… (by its node and from above)");
}

$pruefe(apply_filters('taxmod_record_home', 'https://x.test/', 1, 0) === 'https://x.test/', 'D-1483: an address another module gave stays');
$pruefe(apply_filters('taxmod_record_home', null, 2147483646, 0) === null, 'D-1483: a record no branch here keeps stays in the modeller');

// His word 21.11.2026 08:52 «Das Löschen von Testregeln hast du wohl vergessen»: the page draws the modeller's scripts (confirm dialog,
// D-1550 field refill) and offers «Löschen» in its bulk actions (D-1403); D-1558: the page is the modeller's one rules page.
$pruefe(in_array(TaxmodStock\WordPress\Admin\Pages::RULES, TaxmodStock\WordPress\Plugin::MODELLER_STYLED, true), 'the rules page loads the modeller\'s styles and scripts (confirm dialog, field refill)');
$regelSeite = Taxmod\WordPress\Admin\RulesPage::fromSetup();
$_GET       = ['page' => TaxmodStock\WordPress\Admin\Pages::RULES, 'tab' => TaxmodStock\WordPress\Admin\RulesSetup::testRulesTab()];
$drawn      = $regelSeite?->render() ?? '';
$pruefe($regelSeite === null || (str_contains($drawn, 'value="remove"') && str_contains($drawn, 'name="bulk"') && str_contains($drawn, 'value="' . Taxmod\WordPress\Admin\RulesPage::ACTION . '"')), 'rules: «Löschen» stands in the bulk actions, routed to the rules handler');
// His words 09:02: a bin per row and in the open rule, asking first; his word 21.11.: the type switched at the top.
$pruefe($regelSeite === null || (new TaxmodStock\WordPress\StockService())->testRules()->book === [] || str_contains($drawn, 'taxmod-rule-remove-'), 'rules: each row has its bin with a dialog');
$pruefe($regelSeite === null || (substr_count($drawn, 'nav-tab ') + substr_count($drawn, 'nav-tab"') >= count($regelSeite->types()) && ! str_contains($drawn, 'Gruppen übernehmen')), 'D-1558: one tab per rule type; no «Gruppen übernehmen» any more');

printf("\n%s\n", $fehler === 0 ? 'navigation-check: green' : "navigation-check: {$fehler} FAIL");
exit($fehler === 0 ? 0 : 1);
