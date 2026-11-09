<?php declare(strict_types=1);

/**
 * The goods receipt cockpit end to end against a local WordPress (D-1001): stages from what the inbox wrote, the buttons.
 *
 *     php scripts/dev/inbound-check.php [path/to/wordpress]
 *
 * Builds orders with the fields the modeller's import targets name, points both options at them, fakes the DHL module's
 * filter, renders the page and presses its buttons through the service. The modeller's no-write bracket undoes it all.
 *
 * D-1097: runs twice, with «Positionen» of Vorgänge and Bestellungen as aggregation and, switched with `set_kind` after the
 * records are written, as composition — then «Zurück» from «Eingebucht» lets the cascade take the lines (D-1099) and is
 * refused while another record points at one. TAXMOD_STOCK_KIND=aggregation|composition runs one of them.
 */

$kind = getenv('TAXMOD_STOCK_KIND') ?: '';

if ($kind === '') {
    $rot = 0;

    foreach (['aggregation', 'composition'] as $each) {
        echo "«Positionen» as {$each}:\n";
        passthru('TAXMOD_STOCK_KIND=' . $each . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', array_slice($argv, 1))), $code);
        $rot += $code === 0 ? 0 : 1;
    }

    exit($rot === 0 ? 0 : 1);
}

if (! in_array($kind, ['aggregation', 'composition'], true)) {
    echo "TAXMOD_STOCK_KIND is aggregation or composition\n";
    exit(1);
}

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\StockPage;
use TaxmodStock\WordPress\Admin\Messages;
use TaxmodStock\WordPress\InboundService;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

[$text, $bool, $date, $money] = [1175, 1179, 1183, 4232];
$field = static fn (string $ref, int|string $node, string $name, int|string $target, string $kind, string $mult): array
    => ['op' => 'add_field', 'ref' => $ref, 'node' => $node, 'name' => $name, 'target' => $target, 'kind' => $kind, 'multiplicity' => $mult];
$order = static fn (string $ref, string $label, array $values): array => [
    ['op' => 'create_record', 'ref' => $ref, 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@' . $ref, 'values' => ['@label' => $label] + $values],
];

$aufbau = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'stock', 'name' => '__Stock', 'parent' => 402],
    $field('label', 402, 'Bezeichnung', $text, 'composition', '0..1'),
    $field('bilder', 402, '__Bilder', $text, 'composition', '0..*'),
    ['op' => 'create_node', 'ref' => 'mat', 'name' => 'Lager', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'lot', 'name' => 'Bestandsposten', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'vg', 'name' => 'Vorgänge', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'vp', 'name' => 'Vorgänge Pos', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'lo', 'name' => 'Lagerorte', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'ord', 'name' => 'Bestellungen', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'pur', 'name' => 'Käufe', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'stat', 'name' => '__Bestellstatus', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 's_entw', 'name' => 'Entwurf', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_teil', 'name' => 'teilweise eingegangen', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_ganz', 'name' => 'eingegangen', 'parent' => '@stat'],
    $field('l_mat', '@lot', 'Material', 402, 'aggregation', '1..1'),
    $field('l_kauf', '@lot', 'Kauf', '@pur', 'aggregation', '0..1'),
    $field('o_stat', '@ord', 'Status', '@stat', 'aggregation', '0..1'),
    $field('o_pos', '@ord', 'Positionen', '@pur', 'aggregation', '0..*'),
    $field('o_best', '@ord', 'Bestellt am', $date, 'composition', '0..1'),
    $field('o_ein', '@ord', 'Eingegangen am', $date, 'composition', '0..1'),
    $field('o_sn', '@ord', 'Sendungsnummer', $text, 'composition', '0..*'),
    $field('o_plat', '@ord', 'Plattform', $text, 'composition', '0..1'),
    $field('o_hand', '@ord', 'Händler', $text, 'composition', '0..1'),
    $field('o_nr', '@ord', 'Bestellnummer', $text, 'composition', '0..1'),
    $field('k_offen', '@pur', 'Noch nicht näher bestimmt', $bool, 'composition', '1..1'),
    // D-1009: a receipt Vorgang as the Lager writes it, so «Zurück» from «Eingebucht» has something to take back.
    ['op' => 'create_node', 'ref' => 'art', 'name' => '__Vorgangsart', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'a_we', 'name' => 'Wareneingang', 'parent' => '@art'],
    $field('v_art', '@vg', 'Art', '@art', 'aggregation', '0..1'),
    $field('v_best', '@vg', 'Bestellung', '@ord', 'aggregation', '0..1'),
    $field('v_pos', '@vg', 'Positionen', '@vp', 'aggregation', '0..*'),
    // D-1002: a group that is tested, two used lots in it and a new one, each with a rest.
    ['op' => 'create_node', 'ref' => 'gk', 'name' => '__Grafikkarten', 'parent' => 402],
    ['op' => 'create_node', 'ref' => 'zust', 'name' => '__Zustaende', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'z_neu', 'name' => 'neu', 'parent' => '@zust'],
    ['op' => 'create_node', 'ref' => 'z_unget', 'name' => 'ungetestet', 'parent' => '@zust'],
    ['op' => 'create_node', 'ref' => 't_ok', 'name' => 'funktioniert', 'parent' => '@zust'],
    ['op' => 'create_node', 'ref' => 't_teil', 'name' => 'teilweise OK', 'parent' => '@zust'],
    ['op' => 'create_node', 'ref' => 't_wart', 'name' => 'Wartung nötig', 'parent' => '@zust'],
    ['op' => 'create_node', 'ref' => 't_def', 'name' => 'defekt', 'parent' => '@zust'],
    $field('l_tdat', '@lot', 'Getestet am', $date, 'composition', '0..1'),
    $field('l_tbef', '@lot', 'Testbefund', $text, 'composition', '0..1'),
    $field('l_zust', '@lot', 'Zustand', '@zust', 'aggregation', '0..1'),
    $field('l_dat', '@lot', 'Eingegangen am', $date, 'composition', '0..1'),
    $field('p_lot', '@vp', 'Bestandsposten', '@lot', 'aggregation', '1..1'),
    $field('p_menge', '@vp', 'Menge', 1173, 'composition', '1..1'),
    // D-1099: a record outside the receipt that points at one of its lines.
    $field('p_storno', '@vp', 'Storno von', '@vp', 'aggregation', '0..1'),
    ['op' => 'create_record', 'ref' => 'et4000', 'node' => '@gk'],
    ['op' => 'set_values', 'record' => '@et4000', 'values' => ['@label' => 'ET4000']],
    ['op' => 'create_record', 'ref' => 'lotA', 'node' => '@lot'],
    ['op' => 'set_values', 'record' => '@lotA', 'values' => ['@l_mat' => '@et4000', '@l_dat' => '2026-10-27', '@l_zust' => '@z_unget']],
    ['op' => 'create_record', 'ref' => 'lotN', 'node' => '@lot'],
    ['op' => 'create_record', 'ref' => 'kauf5', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kauf5', 'values' => ['@label' => 'Ladegerät']],
    ['op' => 'set_values', 'record' => '@lotN', 'values' => ['@l_mat' => '@et4000', '@l_dat' => '2026-10-27', '@l_zust' => '@z_neu', '@l_kauf' => '@kauf5']],
    ['op' => 'create_record', 'ref' => 'posA', 'node' => '@vp'],
    ['op' => 'set_values', 'record' => '@posA', 'values' => ['@p_lot' => '@lotA', '@p_menge' => '1']],
    ['op' => 'create_record', 'ref' => 'posN', 'node' => '@vp'],
    ['op' => 'set_values', 'record' => '@posN', 'values' => ['@p_lot' => '@lotN', '@p_menge' => '1']],
    ['op' => 'create_record', 'ref' => 'kauf', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kauf', 'values' => ['@label' => 'ISA FDC', '@k_offen' => '1']],
    ...$order('o1', 'Bestellt', ['@o_best' => '2026-10-01', '@o_plat' => 'eBay', '@o_hand' => 'retro-shop', '@o_nr' => '12-345']),
    // INF-116: a purchase that says more than its name — description and article number, shown before it arrives.
    $field('k_desc', '@pur', 'Beschreibung', $text, 'composition', '0..1'),
    $field('k_nr', '@pur', 'Artikelnummer', $text, 'composition', '0..1'),
    ['op' => 'create_record', 'ref' => 'kaufE', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufE', 'values' => ['@label' => 'Edge Card Connector S...', '@k_desc' => 'Variante: Right Angle, 62P', '@k_nr' => '1005003647067417']],
    // D-1249: a firm with its link templates, named by the order's «Plattform».
    ['op' => 'create_node', 'ref' => 'firm', 'name' => '__Firmen', 'parent' => 410, 'class' => 'Choice'],
    $field('f_name', '@firm', 'Name', $text, 'composition', '0..1'),
    $field('f_ol', '@firm', 'Bestell-Link', $text, 'composition', '0..1'),
    $field('f_pl', '@firm', 'Produkt-Link', $text, 'composition', '0..1'),
    ['op' => 'create_record', 'ref' => 'ali', 'node' => '@firm'],
    ['op' => 'set_values', 'record' => '@ali', 'values' => ['@f_name' => '__Ali', '@f_ol' => 'https://shop.test/order?id={nr}', '@f_pl' => 'https://shop.test/item/{nr}.html']],
    ...$order('o2', 'Unterwegs', ['@o_best' => '2026-10-20', '@o_plat' => '__ali', '@o_nr' => '8199', '@o_sn' => ['values' => ['00340434AA']], '@o_pos' => ['values' => ['@kaufE']]]),
    ...$order('o3', 'DHL zugestellt', ['@o_sn' => ['values' => ['00340434BB']]]),
    ...$order('o4', 'Per Mail zugestellt', ['@o_ein' => '2026-10-25', '@o_pos' => ['values' => ['@kauf']]]),
    ...$order('o5', 'Eingebucht', ['@o_stat' => '@s_ganz', '@o_pos' => ['values' => ['@kauf5']]]),
    ...$order('o6', 'Entwurf', ['@o_stat' => '@s_entw']),
    ...$order('o7', 'Amazon-Historie 2004', ['@o_best' => '2004-05-01']),
    ['op' => 'create_record', 'ref' => 'kauf8', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kauf8', 'values' => ['@label' => 'Netzteil']],
    ...$order('o8', 'Zum Zurückbuchen', ['@o_ein' => '2026-10-26', '@o_stat' => '@s_ganz', '@o_pos' => ['values' => ['@kauf8']]]),
    ['op' => 'create_record', 'ref' => 'lot8', 'node' => '@lot'],
    ['op' => 'set_values', 'record' => '@lot8', 'values' => ['@l_mat' => '@et4000', '@l_dat' => '2026-10-28', '@l_zust' => '@z_neu', '@l_kauf' => '@kauf8']],
    ['op' => 'create_record', 'ref' => 'pos8', 'node' => '@vp'],
    ['op' => 'set_values', 'record' => '@pos8', 'values' => ['@p_lot' => '@lot8', '@p_menge' => '1']],
    ['op' => 'create_record', 'ref' => 'vg8', 'node' => '@vg'],
    ['op' => 'set_values', 'record' => '@vg8', 'values' => ['@v_art' => '@a_we', '@v_best' => '@o8', '@v_pos' => ['values' => ['@pos8']]]],
    // D-1111: an arrived order with a used purchase from the tested group and one from an untested group, two places.
    $field('k_mod', '@pur', 'Modell', 402, 'aggregation', '0..1'),
    ['op' => 'create_node', 'ref' => 'misc', 'name' => '__Kabel', 'parent' => 402],
    ['op' => 'create_record', 'ref' => 'kabel', 'node' => '@misc'],
    ['op' => 'set_values', 'record' => '@kabel', 'values' => ['@label' => 'IDE-Kabel']],
    ['op' => 'create_record', 'ref' => 'ortTest', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@ortTest', 'values' => ['@label' => '__Test']],
    ['op' => 'create_record', 'ref' => 'ortRegal', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@ortRegal', 'values' => ['@label' => '__Regal']],
    ['op' => 'create_record', 'ref' => 'kaufT', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufT', 'values' => ['@label' => 'IDE-SATA-Adapterkarte', '@k_mod' => '@et4000']],
    ['op' => 'create_record', 'ref' => 'kaufU', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufU', 'values' => ['@label' => 'Kabel', '@k_mod' => '@kabel']],
    ...$order('o9', 'Einlagern mit Test', ['@o_ein' => '2026-11-03', '@o_pos' => ['values' => ['@kaufT', '@kaufU']]]),
]]);

if (is_wp_error($aufbau)) {
    echo 'setup: ', $aufbau->get_error_message(), "\n";
    exit(1);
}

$r = $aufbau['refs'];

// D-1097: the switch as it will be made live — the records stand, only the kind of the two lists changes (D-587).
if ($kind === 'composition') {
    $wechsel = wp_get_ability('taxmod/apply')->execute(['changes' => [
        ['op' => 'set_kind', 'node' => $r['vg'], 'field' => $r['v_pos'], 'kind' => 'composition'],
        ['op' => 'set_kind', 'node' => $r['ord'], 'field' => $r['o_pos'], 'kind' => 'composition'],
    ]]);

    if (is_wp_error($wechsel)) {
        echo 'set_kind: ', $wechsel->get_error_message(), "\n";
        exit(1);
    }
}

// Only what the cockpit reads; the rest of the structure is required by the reader and points at unused fields.
update_option(StockService::OPTION, [
    'nodes'  => ['lager' => $r['mat'], 'lot' => $r['lot'], 'transaction' => $r['vg'], 'line' => $r['vp'], 'location' => $r['lo'], 'order' => $r['ord'], 'purchase' => $r['pur'], 'money' => $money, 'firm' => $r['firm']],
    'fields' => array_fill_keys(['lager_thing', 'lager_single', 'lager_min', 'lot_location', 'lot_value', 'transaction_date',
        'transaction_source', 'line_counted', 'purchase_quantity',
        'purchase_landed_price', 'purchase_unit_price'], 1) + [
        'lot_date' => $r['l_dat'], 'line_lot' => $r['p_lot'], 'line_quantity' => $r['p_menge'],
        'transaction_kind' => $r['v_art'], 'transaction_order' => $r['v_best'], 'transaction_lines' => $r['v_pos'],
        'lot_tested_on' => $r['l_tdat'], 'lot_test_note' => $r['l_tbef'], 'lot_condition' => $r['l_zust'],
        'label' => $r['label'], 'images' => $r['bilder'], 'lot_thing' => $r['l_mat'], 'lot_purchase' => $r['l_kauf'], 'order_status' => $r['o_stat'], 'order_lines' => $r['o_pos'],
        'purchase_undetermined' => $r['k_offen'], 'purchase_model' => $r['k_mod'], 'money_amount' => 4234, 'money_unit' => 4236,
        'description' => $r['k_desc'], 'purchase_number' => $r['k_nr'],
        'firm_name' => $r['f_name'], 'firm_order_link' => $r['f_ol'], 'firm_product_link' => $r['f_pl'],
    ],
    'values' => ['kind_receipt' => $r['a_we'], 'kind_consumption' => 1, 'kind_count' => 1, 'source_manual' => 1, 'location_default' => $r['ortRegal'],
        'status_partial' => $r['s_teil'], 'status_received' => $r['s_ganz'], 'status_draft' => $r['s_entw'],
        'test_ok' => $r['t_ok'], 'test_partial' => $r['t_teil'], 'test_maintenance' => $r['t_wart'], 'test_defect' => $r['t_def'], 'condition_new' => $r['z_neu'], 'condition_untested' => $r['z_unget']],
]);
update_option('taxmod_import_targets', ['order_fields' => [
    'ordered_at' => $r['o_best'], 'received_at' => $r['o_ein'], 'tracking' => $r['o_sn'],
    'platform' => $r['o_plat'], 'merchant' => $r['o_hand'], 'order_number' => $r['o_nr'],
]]);

// The DHL module's filter: its last answer, and a count of the calls that asked DHL.
$asked = 0;
add_filter(InboundService::DHL_FILTER, static function (mixed $previous, string $number, string $mode = 'stored') use (&$asked): ?array {
    $asked += $mode === 'refresh' ? 1 : 0;

    return match ($number) {
        '00340434AA' => ['tracking_number' => $number, 'stage' => 'shipped', 'events' => [['at' => '2026-10-21T10:00', 'description' => 'Im Paketzentrum bearbeitet', 'place' => 'Köln']]],
        '00340434BB' => ['tracking_number' => $number, 'stage' => 'delivered', 'events' => []],
        default      => null,
    };
}, 10, 3);

// D-1132: the GLS module's filter, asked when DHL has no answer.
$askedGls = 0;
add_filter(InboundService::GLS_FILTER, static function (mixed $previous, string $number, string $mode = 'stored') use (&$askedGls): ?array {
    $askedGls += $mode === 'refresh' ? 1 : 0;

    return $number === 'ZQ123GLS' ? ['tracking_number' => $number, 'stage' => 'delivered', 'events' => [['at' => '2026-10-22T09:00', 'description' => 'Zugestellt im Paketshop', 'place' => 'Bonn']]] : null;
}, 10, 3);

$s      = (new StockService())->structure();
$orders = (new InboundService())->orders();
$stage  = static fn (string $ref): InboundStage => $orders[$r[$ref]]->stage($s);
$pruefe($stage('o1') === InboundStage::Ordered, 'an order without tracking number is «Bestellt»');
$pruefe($stage('o2') === InboundStage::Shipped, 'a tracking number makes it «Versendet»');
$pruefe($stage('o3') === InboundStage::Arrived, 'DHL «delivered» makes it «Angekommen»');
$pruefe($stage('o4') === InboundStage::Arrived, '«Eingegangen am» from a delivery mail makes it «Angekommen»');
$pruefe($stage('o5') === InboundStage::Booked, 'status «eingegangen» is «Eingebucht»');
$pruefe($stage('o6') === InboundStage::None, 'a draft stands in no stage');
$pruefe($orders[$r['o1']]->overdue($s, '2026-10-28'), 'ordered four weeks ago without a number is overdue');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'ordered'];
$html = (new InboundPage())->render();
$pruefe(! str_contains($html, 'Amazon-Historie 2004'), 'an order from before the start day is not «Bestellt»');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'archived'];
$pruefe(str_contains((new InboundPage())->render(), 'Amazon-Historie 2004'), 'it stands under «Archiviert»');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'all'];
$pruefe(! str_contains((new InboundPage())->render(), 'Amazon-Historie 2004'), '«Alle» shows what is not archived');
$pruefe(str_contains($bar = (new InboundPage())->render(), 'taxmod-step') && ! str_contains($bar, 'taxmod-steps-all'), 'His word 2026-11-09: the stages are arrows, «Alle» is no arrow any more');

// His word 2026-11-05: no rows to open any more — a click on an order opens its own view with its purchases.
$details = static function (string $tab): string {
    $html = '';

    foreach (array_keys((new InboundService())->orders()) as $id) {
        $_GET = ['page' => InboundPage::PAGE, 'tab' => $tab, 'order' => $id];
        $html .= (new InboundPage())->render();
    }

    $_GET = ['page' => InboundPage::PAGE, 'tab' => $tab];

    return $html;
};
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'booked'];
$html = (new InboundPage())->render();
$pruefe(! str_contains($html, 'button taxmod-list-toggle') && ! str_contains($html, 'Expand all') && str_contains($html, 'order=' . $r['o5']), '«Eingebucht» lists its orders without opening rows, each linked to its own view');
// His wish 2026-11-09: «bei eingebucht auch die materialbilder anzeigen … rechts neben der box oben» — a real picture of the library.
$png  = wp_upload_bits('taxmod-check-' . wp_generate_password(6, false) . '.png', null, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$bild = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'taxmod check', 'post_status' => 'inherit'], $png['file']);
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata($bild, wp_generate_attachment_metadata($bild, $png['file']));
(new StockService())->write([['op' => 'set_values', 'record' => (string) $r['et4000'], 'values' => [(string) $r['bilder'] => ['values' => ['media:' . $bild]]]]]);
$html .= $details('booked');
$pruefe(! \Taxmod\WordPress\Admin\ListPage::showsImages() || (str_contains($html, 'taxmod-stock-material-pictures') && str_contains($html, basename($png['file']) . '" class="taxmod-list-thumb"') && substr_count($html, 'taxmod-stock-material-pictures') <= count((new InboundService())->orders())), '«Eingebucht»: the material\'s title picture stands beside the box, linked to the material');
wp_delete_attachment($bild, true);
$pruefe(str_contains($html, 'Ladegerät') && str_contains($html, 'thing=' . $r['et4000']) && str_contains($html, 'view=bestand'), '«Eingebucht» shows where each purchase went, linked to its material');
// His wish 2026-11-09, his choice «Als Spalten in der Käufe-Tabelle»: material, place and day as columns, the purchase named by its number only.
$pruefe(str_contains($html, '>Material</th>') && str_contains($html, '>Lagerort</th>') && str_contains($html, '>Eingebucht am</th>')
    && preg_match('/thing=\\d+&(amp;|#038;)?view=daten/', $html) === 1 && str_contains($html, '#taxmod-stock-lot-'), '«Eingebucht»: Material, Lagerort and «Eingebucht am» as columns; the material links to the material, the day to the lot');
// The fixture's lots lie nowhere, so the link is read from the source.
$pruefe(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/InboundPage.php'), "'tab' => 'lagerorte', 'ort' => \$at"), 'his word «weiterhin fehlt der platz auf dem es jetzt liegt mit absprunglink zu platz bestand»: the place links to its stock');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'shipped'];
$html = (new InboundPage())->render();
$html .= $details('shipped');
$pruefe(str_contains($html, 'Unterwegs') && ! str_contains($html, '>Bestellt</strong>'), 'the tab «Versendet» shows only its orders');
$pruefe(str_contains($html, '00340434AA') && str_contains($html, 'unterwegs') && str_contains($html, 'Köln'), 'it shows the number, DHL\'s stage and its last event');
$pruefe(str_contains($html, 'Variante: Right Angle, 62P') && preg_match('#Artikelnummer: (<a [^>]+>)?1005003647067417#', $html) === 1, 'INF-116, his word «um zu entscheiden ob etwas angekommen ist ist das wenig info»: a purchase shows its description and article number');
$pruefe($asked === 0, 'drawing the page never asks DHL');
$pruefe(str_contains($html, 'href="https://shop.test/order?id=8199"') && str_contains($html, 'href="https://shop.test/item/1005003647067417.html"'), 'D-1249: the order and the article number link to the platform, from the template of the firm its «Plattform» names');
$pruefe(! str_contains($html, '{nr}'), 'a template is never shown as it stands');
$pruefe(preg_match('/data-order="' . $r['o2'] . '" data-back="' . InboundPage::BACK . '" data-assign="1"/', $html) === 1, 'his choice «Material schon am Kauf wählen»: an order on its way offers the choice of material before it arrives');
$assignForm = (new \ReflectionMethod(StockPage::class, 'receiptPositions'))->invoke(new StockPage(), $r['o2'], InboundPage::BACK, true);
$pruefe(str_contains($assignForm, 'class="taxmod-stock-assign"') && str_contains($assignForm, 'value="zuordnen"') && ! str_contains($assignForm, 'value="Wareneingang buchen"') && str_contains($assignForm, 'thing[' . $r['kaufE'] . ']'), 'it only keeps the choice: nothing can be booked before the order arrives');
$pruefe(str_contains($assignForm, 'data-taxmod-fill="location"') && strpos($assignForm, 'taxmod-stock-receipt-bulk-') < strpos($assignForm, 'class="taxmod-stock-assign"'), 'his wish «bulk funktion platz für alle wählen»: the bar before the form sets the place of every ticked purchase');
$pruefe(str_contains(InboundPage::orderUrl($r['o4'], 'ordered'), 'tab=arrived'), 'his word «sollte dann auf eingebucht oben springen … sollte generell so sein»: after an action the order opens at the stage it stands in now');
$plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php');
$pruefe(str_contains($plugin, "'admin_post_' . StockPage::ASSIGN"), 'the choice is saved without a reload, under its own action');
$stockPage = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/StockPage.php');
$pruefe(substr_count($stockPage, '$this->manufacturerControl(') === 2 && substr_count($stockPage, '$this->stock->manufacturerTyped(') === 2 && str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php'), "'admin_post_' . StockPage::MAKER"), 'D-1255, D-1256: both «Neues Material» dialogs ask the manufacturer of the chosen group with the modeller\'s control and pass it into the new material');
$pruefe(substr_count($stockPage, "[group]', 0, '', '', true)") === 2 && str_contains($stockPage, "groupChooser('newgroup[' . \$purchase . ']', 0, '', '', true)"), 'D-1257: every «Neues Material» chooses its group under «Material»');
// D-1254: no act ends on an empty page.
preg_match_all("/add_action\('admin_post_' \. [A-Za-z]+::[A-Z_]+, (.*)\);/", $plugin, $handlers);
$pruefe($handlers[1] !== [] && array_filter($handlers[1], static fn (string $h): bool => ! str_starts_with($h, 'static fn () => Acts::run(') && ! str_starts_with($h, 'static fn () => Acts::json(')) === [], 'D-1254: every handler runs through Acts, a failure becomes a message on the page it came from');
$admin = implode('', array_map('file_get_contents', glob(dirname(__DIR__, 2) . '/src/WordPress/Admin/*.php')));
$pruefe(! preg_match('/\bwp_die\(|check_admin_referer\(|check_ajax_referer\(/', str_replace((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/Acts.php'), '', $admin)), 'refusals (capability, nonce) say why and lead back, no empty page');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'arrived', 'order' => $r['o4']];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'data-back="' . InboundPage::BACK . '"') && str_contains($html, 'data-order="' . $r['o4'] . '"') && str_contains($html, 'Zurück zu «Angekommen»'), 'the view of an arrived order holds the Lager booking form, returning here');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'ordered', 'order' => $r['o4']];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'Zurück zu «Angekommen»') && preg_match('/is-active[^"]*" href="[^"]*tab=arrived[^"]*" aria-current="step"/', $html) === 1, 'his word «der blaue Pfeil ist immer noch bei bestellt»: an opened order stands at its own stage');
$pruefe(! str_contains($html, 'data-assign'), 'an arrived order has the booking form, not the choice alone');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'arrived'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, '1 unbestimmt'), 'it counts the purchases not yet determined (R1)');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'ordered', 'filter' => 'overdue', 's' => 'retro'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'eBay · retro-shop · 12-345') && str_contains($html, 'überfällig'), 'search and the filter «Überfällig» find the late order with platform, seller and number');

$inbound = new InboundService();
$inbound->markArrived([$r['o1'], $r['o4']], '2026-10-28');
$t = (new StockService())->table('order');
$pruefe(substr((string) $t->first($r['o1'], $r['o_ein']), 0, 10) === '2026-10-28', '«Angekommen» writes today into «Eingegangen am»');
$pruefe(substr((string) $t->first($r['o4'], $r['o_ein']), 0, 10) === '2026-10-25', 'a date the mail wrote stays');

(new InboundService())->markNotArrived($r['o1']);
$pruefe(in_array((new StockService())->table('order')->first($r['o1'], $r['o_ein']), [null, ''], true), '«Zurück» empties «Eingegangen am» again');

(new InboundService())->addTracking($r['o1'], '0034 0434 CC');
$pruefe((new StockService())->table('order')->all($r['o1'], $r['o_sn']) === ['00340434CC'], 'a tracking number typed by hand is added');
(new InboundService())->removeTracking($r['o1'], '00340434CC');
$pruefe((new StockService())->table('order')->all($r['o1'], $r['o_sn']) === [], '«Entfernen» takes the number off again (D-1009)');
(new InboundService())->addTracking($r['o2'], '00340434-aa');
$pruefe((new StockService())->table('order')->all($r['o2'], $r['o_sn']) === ['00340434AA'], 'the same number again adds nothing');

$pruefe((new InboundService())->refreshTracking([$r['o2'], $r['o3']]) === 2 && $asked === 2 && $askedGls === 0, '«Sendungsstatus aktualisieren» asks DHL once per number it knows, GLS not');

(new InboundService())->addTracking($r['o1'], 'ZQ123GLS');
$gls = (new InboundService())->trackingAnswer('ZQ123GLS');
$pruefe(($gls['carrier'] ?? '') === 'GLS' && ($gls['stage'] ?? '') === 'delivered', 'D-1132: a number DHL does not know is answered by GLS');
$pruefe((new InboundService())->orders()[$r['o1']]->stage($s) === InboundStage::Arrived, 'GLS «delivered» makes it «Angekommen»');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'arrived'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'ZQ123GLS</code> <span class="description">GLS zugestellt'), 'the page names the carrier GLS with its stage');
$asked = 0;
$pruefe((new InboundService())->refreshTracking([$r['o1']]) === 1 && $asked === 0 && $askedGls === 1, 'refreshing a GLS number asks GLS only');
(new InboundService())->removeTracking($r['o1'], 'ZQ123GLS');

// D-1002 · the test after the goods receipt.
$pruefe((new InboundService())->lotsToTest() === [], 'no tested group, nothing to test');
// D-1259: «Nach Wareneingang testen» is a group property, set at the group, inherited below.
(new StockService())->setGroupProperty($r['gk'], StockService::TEST_PROPERTY, true);
$pruefe(((new StockService())->groupProperty(StockService::TEST_PROPERTY, [$r['gk']])[$r['gk']] ?? null) === true, 'D-1259: the group carries «Nach Wareneingang testen»');
$pruefe((new InboundService())->lotsToTest() === [$r['lotA']], 'the «ungetestet» lot of a tested group waits; the new one does not');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'test'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'Zu testen') && str_contains($html, 'ET4000') && str_contains($html, 'name="result"'), 'the tab «Zu testen» lists it with the result form');

try {
    (new InboundService())->recordTest($r['lotA'], \TaxmodStock\Core\TestResult::Partial, '', '2026-10-28');
    $pruefe(false, '«Teilweise OK» without a reason is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'test_without_reason', '«Teilweise OK» without a reason is refused');
}

(new InboundService())->recordTest($r['lotA'], \TaxmodStock\Core\TestResult::Maintenance, 'Elkos tauschen', '2026-10-28');
$t = (new StockService())->table('lot');
$pruefe($t->first($r['lotA'], $r['l_zust']) === (string) $r['t_wart'] && $t->first($r['lotA'], $r['l_tbef']) === 'Elkos tauschen' && substr((string) $t->first($r['lotA'], $r['l_tdat']), 0, 10) === '2026-10-28', 'the result, its reason and the day stand at the lot');
$pruefe((new InboundService())->lotsToTest() === [], 'a tested lot waits no more');

// D-1111 · «Lagerort für Test» is proposed at the receipt for what will wait for its test.
$stock = new StockService();
$plan  = $stock->receiptPlan($r['o9']);
$pruefe($stock->receiptLocations($plan) === [$r['kaufT'] => $r['ortRegal'], $r['kaufU'] => $r['ortRegal']], 'without a test place both purchases keep the usual proposal');

$settings = new \TaxmodStock\WordPress\Admin\SettingsPage();
$_GET     = ['page' => \TaxmodStock\WordPress\Admin\SettingsPage::PAGE];
$pruefe(str_contains($settings->render(), 'name="taxmod_stock_test_place"'), 'Einstellungen › Test nach dem Wareneingang offers «Lagerort für Test»');
$pruefe(! str_contains($settings->render(), 'taxmod_stock_tests[') && ! str_contains($settings->render(), 'taxmod_stock_branch['), 'D-1259: no table of node ids in the Grundeinstellungen any more');
$settings->save(['taxmod_stock_tests_sent' => '1', 'taxmod_stock_test_place' => (string) $r['ortTest']]);
$pruefe(get_option(StockService::TESTS) === ['place' => $r['ortTest']] && (new StockService())->testSettings([$r['gk']])->toArray() === ['branches' => [$r['gk']], 'place' => $r['ortTest']], 'the test place is stored in ' . StockService::TESTS . ', the tested groups come from the group property');
$pruefe(str_contains($settings->render(), 'value="' . $r['ortTest'] . '" selected'), 'the page shows the chosen test place');

$stock = new StockService();
$pruefe($stock->receiptLocations($plan) === [$r['kaufT'] => $r['ortTest'], $r['kaufU'] => $r['ortRegal']], 'the used purchase of the tested group is proposed for the test place, the other keeps its proposal');

$_GET = ['page' => \TaxmodStock\WordPress\Admin\StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['o9']];
$html = (new \TaxmodStock\WordPress\Admin\StockPage())->render();
$pruefe(preg_match('/name="location\[' . $r['kaufT'] . '\]">(.*?)<\/select>/s', $html, $select) === 1
    && str_contains($select[1], 'value="' . $r['ortTest'] . '" selected') && str_contains($select[1], 'value="' . $r['ortRegal'] . '"'), 'the Einlagern row preselects the test place and still offers the others');
$pruefe(str_contains($html, 'id="taxmod-stock-material-' . $r['kaufT'] . '"')
    && str_contains($html, 'taxmod-stock-material-link" for="taxmod-stock-material-' . $r['kaufT'] . '"') && str_contains($html, '>ET4000</label>'), 'the material text opens the same dialog as the row\'s «Material» button');

$stock->planPlaces([$r['kaufT'] => $r['ortRegal']]);
$pruefe((new StockService())->receiptLocations($plan)[$r['kaufT']] === $r['ortRegal'], 'a place saved with «Zuordnung speichern» stays his choice');
$stock->planPlaces([$r['kaufT'] => 0]);

update_option(StockService::TESTS, ['place' => 999999999]);
$pruefe((new StockService())->receiptLocations($plan)[$r['kaufT']] === $r['ortRegal'], 'a test place that is no place proposes nothing');
update_option(StockService::TESTS, ['branches' => [(string) $r['gk']], 'place' => (string) $r['ortTest']]);
$rest = rest_do_request(new WP_REST_Request('GET', '/wp/v2/settings'))->get_data();
$pruefe(get_option(StockService::TESTS) === ['place' => $r['ortTest']] && ($rest[StockService::TESTS] ?? null) === ['place' => $r['ortTest']], 'the option is cleaned on write and readable over REST (/wp/v2/settings)');

// D-1259 · the old branch rows move to the groups once.
(new StockService())->setGroupProperty($r['gk'], StockService::TEST_PROPERTY, null);
update_option(StockService::TESTS, ['place' => $r['ortTest']]);
update_option(StockService::LOCATIONS, ['branches' => [$r['gk'] => $r['ortRegal']], 'unreferenced' => null]);
(new StockService())->moveBranchSettingsToGroups();
$pruefe(((new StockService())->groupProperty(StockService::PLACE_PROPERTY, [$r['gk']])[$r['gk']] ?? null) === $r['ortRegal'] && ! array_key_exists('branches', (array) get_option(StockService::LOCATIONS)), 'D-1259: a branch → place row of the old option becomes the group\'s «Standard-Lagerort» and leaves the option');
// The option as an older version stored it, past today's sanitizer, which no longer keeps branch rows.
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->options, ['option_value' => maybe_serialize(['branches' => [(string) $r['gk']]])], ['option_name' => StockService::TESTS]);
wp_cache_delete('alloptions', 'options');
wp_cache_delete(StockService::TESTS, 'options');
(new StockService())->moveBranchSettingsToGroups();
$pruefe(get_option(StockService::TESTS, 'gone') === 'gone' && ((new StockService())->ownGroupProperty($r['gk'], StockService::TEST_PROPERTY)) === true, 'D-1259: an option the move empties is deleted, the group holds its own «Ja»');
(new StockService())->moveBranchSettingsToGroups();
$pruefe(get_option(StockService::TESTS, 'gone') === 'gone' && ((new StockService())->ownGroupProperty($r['gk'], StockService::TEST_PROPERTY)) === true, 'D-1259: a second run of the move changes nothing');
update_option(StockService::TESTS, ['place' => $r['ortTest']]);
(new StockService())->setGroupProperty($r['gk'], StockService::PLACE_PROPERTY, null);
(new StockService())->setGroupProperty($r['gk'], StockService::TEST_PROPERTY, true);

// D-1009 · back from «Eingebucht».
$pruefe((new InboundService())->orders()[$r['o8']]->stage((new StockService())->structure()) === InboundStage::Booked, 'a booked order stands under «Eingebucht»');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'booked'];
$pruefe(str_contains((new InboundPage())->render(), 'wieder ausgebucht'), 'its «Zurück» warns that the stock goes out again');
$pruefe((new InboundService())->stock()->structureWithKinds()->composes('transaction_lines') === ($kind === 'composition'), "the module reads «Positionen» as {$kind} from the model");

if ($kind === 'composition') {
    // D-1099, his word «f3 verweigern»: while another record points at a line of the receipt, the receipt is not deleted.
    $fremd = wp_get_ability('taxmod/write-data')->execute(['changes' => [
        ['op' => 'create_record', 'ref' => 'storno', 'node' => $r['vp']],
        ['op' => 'set_values', 'record' => '@storno', 'values' => [(string) $r['p_storno'] => (string) $r['pos8'], (string) $r['p_menge'] => '0']],
    ]]);

    try {
        (new InboundService())->unbook($r['o8']);
        $pruefe(false, 'a receipt whose line another record points at is refused (D-1099)');
    } catch (StockError $e) {
        $pruefe($e->reason === 'parts_referenced' && str_contains(Messages::of($e), 'Position #' . $r['pos8'] . ' ← #' . (int) ($fremd['refs']['storno'] ?? 0)), 'a receipt whose line another record points at is refused, naming who, in the notice\'s words (D-1099)');
    }

    $pruefe((new StockService())->table('line')->has($r['pos8']) && (new StockService())->table('transaction')->has($r['vg8']), 'the refusal wrote nothing');
    wp_get_ability('taxmod/write-data')->execute(['changes' => [['op' => 'remove_record', 'record' => (int) ($fremd['refs']['storno'] ?? 0)]]]);
}

(new InboundService())->unbook($r['o8']);
$pruefe(! (new StockService())->table('line')->has($r['pos8']) && ! (new StockService())->table('transaction')->has($r['vg8']), 'the receipt goes with its line — as parts by the cascade, as records one by one');
$after = new InboundService();
$pruefe($after->orders()[$r['o8']]->stage($after->stock()->structure()) === InboundStage::Arrived && $after->lotsOf($r['kauf8']) === [], '«Zurück» takes the lot out and the order stands under «Angekommen» again');

try {
    (new InboundService())->unbook($r['o5']);
    $pruefe(false, 'a lot with another line keeps its receipt');
} catch (StockError $e) {
    $pruefe($e->reason === 'lot_already_used', 'a lot with another line keeps its receipt');
}

update_option('taxmod_import_targets', ['order_fields' => ['received_at' => 0]]);

try {
    (new InboundService())->orders();
    // The defaults of the modeller fill a role the stored map leaves out; only a zero is missing on purpose.
    $pruefe(false, 'a missing order field is named');
} catch (StockError $e) {
    $pruefe($e->reason === 'no_import_targets' && $e->detail === 'received_at', 'a missing order field is named');
}

echo $fehler === 0 ? "inbound-check: all green ({$kind})\n" : "inbound-check: {$fehler} FAILED ({$kind})\n";
exit($fehler === 0 ? 0 : 1);
