<?php declare(strict_types=1);
/**
 * The stock checks its own rights (D-1585 step 3, D-1586): each kind of booking, tab and page asks one right, «Wareneingang»
 * follows from purchasing or booking stock, and the navigation shows a person only what it may open.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller ab 0.2.238 active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);
define('WP_ADMIN', true);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Rights;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\StockRights;

$bad = 0;
$pruefe = static function (bool $ok, string $what) use (&$bad): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $ok ? 0 : 1;
};

Rights::ensureRoles();
$user = static fn (string $login, string $role): int => (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role]);
$stock    = $user('__sr_stock', 'taxmod_stock');
$purchase = $user('__sr_purchase', 'taxmod_purchase');
$reader   = $user('__sr_reader', 'taxmod_read_only');
$maker    = $user('__sr_maker', 'taxmod_manufacture');

$pruefe(StockRights::ofKind('umbuchen') === Rights::STOCK_BOOK && StockRights::ofKind('inventur') === Rights::STOCK_BOOK, 'booking stock needs stock_book');
$pruefe(StockRights::ofKind('einlagern') === StockRights::INBOUND && StockRights::ofKind('angekommen') === StockRights::INBOUND, 'goods arrival needs «Wareneingang»');
$pruefe(StockRights::ofKind('lager') === Rights::WRITE_DATA && StockRights::ofKind('kopieren') === Rights::WRITE_DATA, 'a material is a record: write_data (Prüftor 23.11.)');
$pruefe(StockRights::ofKind('ort') === Rights::CONFIGURE && StockRights::ofTab('lagerstruktur') === Rights::CONFIGURE, 'the structure of the store is configuration');
$pruefe(StockRights::ofKind('unbekannt') === Rights::STOCK_BOOK, 'an unnamed kind books stock');

$pruefe(user_can($stock, StockRights::INBOUND) && user_can($purchase, StockRights::INBOUND), '«Wareneingang» follows from stock_book and from purchase');
$pruefe(! user_can($reader, StockRights::INBOUND) && ! user_can($maker, StockRights::INBOUND), 'not from seeing or manufacturing');
$pruefe(isset(Rights::all()[StockRights::INBOUND]), 'the stock names its right through taxmod_rights');

$labels = static function (int $who): array {
    global $menu, $submenu;
    wp_set_current_user($who);
    $menu = $submenu = [];
    remove_all_actions('admin_menu', PHP_INT_MAX);
    add_action('admin_menu', [\Taxmod\WordPress\Admin\FullScreen::class, 'capture'], PHP_INT_MAX);
    do_action('admin_menu', '');
    $out = [];
    foreach (LogisticsShell::navigation()->areas() as $area) {
        foreach ($area['items'] as $item) {
            $out[] = $area['key'] . '/' . $item['key'];
        }
    }

    return $out;
};

$seen = $labels($stock);
$pruefe(in_array('stock/group-stock', $seen, true) && in_array('inbound/arrived', $seen, true) && ! in_array('purchasing/to-order', $seen, true), 'Lager: sees Bestand and Wareneingang, not Einkauf');
$pruefe(! in_array('masterdata/structure', $seen, true) && ! in_array('settings/basics', $seen, true) && in_array('settings/user', $seen, true), 'Lager: no Lagerstruktur, no Grundeinstellungen, but own settings');
$seen = $labels($purchase);
$pruefe(in_array('purchasing/to-order', $seen, true) && in_array('inbound/arrived', $seen, true) && ! in_array('stock/group-stock', $seen, true), 'Einkauf: sees Einkauf and Wareneingang, not Bestand');
$seen = $labels($reader);
$pruefe(in_array('cockpit/cockpit', $seen, true) && in_array('settings/user', $seen, true) && ! in_array('stock/group-stock', $seen, true) && ! in_array('purchasing/to-order', $seen, true), 'Nur lesen: Cockpit and own settings only (C4)');
$seen = $labels((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
$pruefe(in_array('masterdata/structure', $seen, true) && in_array('settings/basics', $seen, true) && in_array('purchasing/to-order', $seen, true), 'the administrator sees everything');

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
