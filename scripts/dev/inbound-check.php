<?php declare(strict_types=1);

/**
 * The goods receipt cockpit end to end against a local WordPress (D-1001): stages from what the inbox wrote, the buttons.
 *
 *     php scripts/dev/inbound-check.php [path/to/wordpress]
 *
 * Builds orders with the fields the modeller's import targets name, points both options at them, fakes the DHL module's
 * filter, renders the page and presses its buttons through the service. The modeller's no-write bracket undoes it all.
 */

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
    ...$order('o2', 'Unterwegs', ['@o_best' => '2026-10-20', '@o_sn' => ['values' => ['00340434AA']]]),
    ...$order('o3', 'DHL zugestellt', ['@o_sn' => ['values' => ['00340434BB']]]),
    ...$order('o4', 'Per Mail zugestellt', ['@o_ein' => '2026-10-25', '@o_pos' => ['values' => ['@kauf']]]),
    ...$order('o5', 'Eingebucht', ['@o_stat' => '@s_ganz', '@o_pos' => ['values' => ['@kauf5']]]),
    ...$order('o6', 'Entwurf', ['@o_stat' => '@s_entw']),
    ...$order('o7', 'Amazon-Historie 2004', ['@o_best' => '2004-05-01']),
]]);

if (is_wp_error($aufbau)) {
    echo 'setup: ', $aufbau->get_error_message(), "\n";
    exit(1);
}

$r = $aufbau['refs'];

// Only what the cockpit reads; the rest of the structure is required by the reader and points at unused fields.
update_option(StockService::OPTION, [
    'nodes'  => ['lager' => $r['mat'], 'lot' => $r['lot'], 'transaction' => $r['vg'], 'line' => $r['vp'], 'location' => $r['lo'], 'order' => $r['ord'], 'purchase' => $r['pur'], 'money' => $money],
    'fields' => array_fill_keys(['lager_thing', 'lager_single', 'lager_min', 'lot_location', 'lot_value', 'transaction_kind', 'transaction_date',
        'transaction_order', 'transaction_source', 'transaction_lines', 'line_counted', 'purchase_model', 'purchase_quantity',
        'purchase_landed_price', 'purchase_unit_price'], 1) + [
        'lot_date' => $r['l_dat'], 'line_lot' => $r['p_lot'], 'line_quantity' => $r['p_menge'],
        'lot_tested_on' => $r['l_tdat'], 'lot_test_note' => $r['l_tbef'], 'lot_condition' => $r['l_zust'],
        'label' => $r['label'], 'lot_thing' => $r['l_mat'], 'lot_purchase' => $r['l_kauf'], 'order_status' => $r['o_stat'], 'order_lines' => $r['o_pos'],
        'purchase_undetermined' => $r['k_offen'], 'money_amount' => 4234, 'money_unit' => 4236,
    ],
    'values' => ['kind_receipt' => 1, 'kind_consumption' => 1, 'kind_count' => 1, 'source_manual' => 1, 'location_default' => 1,
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
$pruefe(! str_contains($html, 'Amazon-Historie 2004'), 'an order from before the start day is history, not «Bestellt»');
$_GET = ['page' => InboundPage::PAGE, 'tab' => 'all'];
$pruefe(str_contains((new InboundPage())->render(), 'Amazon-Historie 2004'), 'it still stands under «Alle»');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'booked'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'Ladegerät') && str_contains($html, 'thing=' . $r['et4000']) && str_contains($html, 'view=bestand'), '«Eingebucht» shows where each purchase went, linked to its material');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'shipped'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'Unterwegs') && ! str_contains($html, '>Bestellt</strong>'), 'the tab «Versendet» shows only its orders');
$pruefe(str_contains($html, '00340434AA') && str_contains($html, 'unterwegs') && str_contains($html, 'Köln'), 'it shows the number, DHL\'s stage and its last event');
$pruefe($asked === 0, 'drawing the page never asks DHL');

$_GET = ['page' => InboundPage::PAGE, 'tab' => 'arrived'];
$html = (new InboundPage())->render();
$pruefe(str_contains($html, 'data-back="' . InboundPage::BACK . '"') && str_contains($html, 'data-order="' . $r['o4'] . '"'), '«Angekommen» holds the Lager booking form, returning here');
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
(new InboundService())->addTracking($r['o2'], '00340434-aa');
$pruefe((new StockService())->table('order')->all($r['o2'], $r['o_sn']) === ['00340434AA'], 'the same number again adds nothing');

$pruefe((new InboundService())->refreshDhl([$r['o2'], $r['o3']]) === 2 && $asked === 2, '«DHL aktualisieren» asks DHL once per number');

// D-1002 · the test after the goods receipt.
$pruefe((new InboundService())->lotsToTest() === [], 'no tested branch, nothing to test');
update_option(StockService::TESTS, ['branches' => [$r['gk']]]);
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

update_option('taxmod_import_targets', ['order_fields' => ['received_at' => 0]]);

try {
    (new InboundService())->orders();
    // The defaults of the modeller fill a role the stored map leaves out; only a zero is missing on purpose.
    $pruefe(false, 'a missing order field is named');
} catch (StockError $e) {
    $pruefe($e->reason === 'no_import_targets' && $e->detail === 'received_at', 'a missing order field is named');
}

echo $fehler === 0 ? "inbound-check: all green\n" : "inbound-check: {$fehler} FAILED\n";
exit($fehler === 0 ? 0 : 1);
