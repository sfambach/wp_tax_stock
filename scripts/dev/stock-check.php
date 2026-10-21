<?php declare(strict_types=1);

/**
 * Stage 1 end to end against a local WordPress: count, consume, receive, materials — and the stock that follows.
 *
 *     php scripts/dev/stock-check.php [path/to/wordpress]
 *
 * Builds a small Stock structure through `taxmod/apply`, points the module's option at it, books through the
 * module's service layer and checks the stock list. Uses the modeller's no-write bracket: nothing survives the run.
 * Needs the Taxonomy Modeller active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\MaterialInput;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\Admin\StockPage;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

[$text, $int, $decimal, $bool, $date, $money] = [1175, 1171, 1173, 1179, 1183, 4232];
$field = static fn (string $ref, string $node, string $name, int|string $target, string $kind, string $mult): array
    => ['op' => 'add_field', 'ref' => $ref, 'node' => $node, 'name' => $name, 'target' => $target, 'kind' => $kind, 'multiplicity' => $mult];

$aufbau = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'stock', 'name' => '__Stock', 'parent' => 402],
    $field('label', '@stock', 'Bezeichnung', $text, 'composition', '0..1'),
    ['op' => 'create_node', 'ref' => 'mat', 'name' => 'Material', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'lot', 'name' => 'Bestandsposten', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'vg', 'name' => 'Vorgänge', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'vp', 'name' => 'Vorgänge Pos', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'lo', 'name' => 'Lagerorte', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'ord', 'name' => 'Bestellungen', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'pur', 'name' => 'Käufe', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'arten', 'name' => '__Vorgangsarten', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'k_we', 'name' => 'Wareneingang', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'k_vb', 'name' => 'Verbrauch', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'k_in', 'name' => 'Inventur', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'stat', 'name' => '__Bestellstatus', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 's_teil', 'name' => 'teilweise eingegangen', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_ganz', 'name' => 'eingegangen', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 'quel', 'name' => '__Quellen', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'q_hand', 'name' => 'von Hand', 'parent' => '@quel'],
    ['op' => 'create_node', 'ref' => 'verw', 'name' => '__Verwendungen', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'u_eigen', 'name' => 'Eigenbedarf', 'parent' => '@verw'],
    ['op' => 'create_node', 'ref' => 'teile', 'name' => '__Teile', 'parent' => '@stock'],
    $field('m_ding', '@mat', 'Bezug Ding', 402, 'aggregation', '0..1'),
    $field('m_einz', '@mat', 'Einzelstück', $bool, 'composition', '1..1'),
    $field('m_min', '@mat', 'Mindestbestand', $int, 'composition', '0..1'),
    $field('m_use', '@mat', 'Verwendung', '@verw', 'aggregation', '0..1'),
    $field('m_ort', '@mat', 'Standard-Lagerort', '@lo', 'aggregation', '0..1'),
    $field('l_mat', '@lot', 'Material', '@mat', 'aggregation', '1..1'),
    $field('l_kauf', '@lot', 'Kauf', '@pur', 'aggregation', '0..1'),
    $field('l_lo', '@lot', 'Lagerort', '@lo', 'aggregation', '0..1'),
    $field('l_wert', '@lot', 'Wert je Stück', $money, 'composition', '0..1'),
    $field('l_dat', '@lot', 'Eingegangen am', $date, 'composition', '1..1'),
    $field('v_art', '@vg', 'Art', '@arten', 'aggregation', '1..1'),
    $field('v_dat', '@vg', 'Datum', $date, 'composition', '1..1'),
    $field('v_best', '@vg', 'Bestellung', '@ord', 'aggregation', '0..1'),
    $field('v_quel', '@vg', 'Quelle', '@quel', 'aggregation', '0..1'),
    $field('v_pos', '@vg', 'Positionen', '@vp', 'aggregation', '0..*'),
    $field('p_lot', '@vp', 'Bestandsposten', '@lot', 'aggregation', '1..1'),
    $field('p_menge', '@vp', 'Menge', $decimal, 'composition', '1..1'),
    $field('p_gez', '@vp', 'Gezählt', $decimal, 'composition', '0..1'),
    $field('o_stat', '@ord', 'Status', '@stat', 'aggregation', '0..1'),
    $field('o_pos', '@ord', 'Positionen', '@pur', 'aggregation', '0..*'),
    $field('k_mat', '@pur', 'Material', '@mat', 'aggregation', '0..*'),
    $field('k_anz', '@pur', 'Anzahl', $int, 'composition', '0..1'),
    $field('k_mod', '@pur', 'Modell', 402, 'aggregation', '0..1'),
    $field('k_offen', '@pur', 'Noch nicht näher bestimmt', $bool, 'composition', '1..1'),
    $field('k_ein', '@pur', 'Einstandspreis je Stück', $money, 'composition', '0..1'),
    $field('k_preis', '@pur', 'Preis je Stück', $money, 'composition', '0..1'),
    ['op' => 'create_record', 'ref' => 'lager', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@lager', 'values' => ['@label' => 'Lager']],
    ['op' => 'create_record', 'ref' => 'lm', 'node' => '@mat'],
    ['op' => 'set_values', 'record' => '@lm', 'values' => ['@label' => 'LM2930T-5.0', '@m_einz' => '0', '@m_min' => '2']],
    ['op' => 'create_record', 'ref' => 'kaufA', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufA', 'values' => ['@label' => 'Konvolut unbestimmt', '@k_offen' => '1']],
    ['op' => 'create_record', 'ref' => 'kaufB', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufB', 'values' => ['@label' => 'LM2930T-5.0 ×5', '@k_offen' => '0', '@k_mat' => ['values' => ['@lm']], '@k_anz' => '5',
        '@k_preis' => ['neu' => ['4234' => '1.80']]]],
    ['op' => 'create_record', 'ref' => 'best', 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@best', 'values' => ['@label' => 'Bestellung 1', '@o_pos' => ['values' => ['@kaufA', '@kaufB']]]],
    // D-967: two things under Model, a material that refers to the first, and an order whose purchases name them as «Modell».
    ['op' => 'create_record', 'ref' => 't1', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@t1', 'values' => ['@label' => 'BC640']],
    ['op' => 'create_record', 'ref' => 't2', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@t2', 'values' => ['@label' => 'MPSA56']],
    ['op' => 'create_record', 'ref' => 'mt1', 'node' => '@mat'],
    ['op' => 'set_values', 'record' => '@mt1', 'values' => ['@label' => 'BC640 alt', '@m_ding' => '@t1', '@m_einz' => '0']],
    ['op' => 'create_record', 'ref' => 'kaufC', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufC', 'values' => ['@label' => 'BC640 ×3', '@k_offen' => '0', '@k_mod' => '@t1', '@k_anz' => '3']],
    ['op' => 'create_record', 'ref' => 'kaufD', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufD', 'values' => ['@label' => 'MPSA56 ×2', '@k_offen' => '0', '@k_mod' => '@t2', '@k_anz' => '2']],
    ['op' => 'create_record', 'ref' => 'best2', 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@best2', 'values' => ['@label' => 'Bestellung 2', '@o_pos' => ['values' => ['@kaufC', '@kaufD']]]],
]]);

if (is_wp_error($aufbau)) {
    echo 'setup: ', $aufbau->get_error_message(), "\n";
    exit(1);
}

$r = $aufbau['refs'];

$struktur = [
    'nodes'  => ['material' => $r['mat'], 'lot' => $r['lot'], 'transaction' => $r['vg'], 'line' => $r['vp'], 'location' => $r['lo'], 'order' => $r['ord'], 'purchase' => $r['pur'], 'money' => $money,
        'material_use' => $r['verw']],
    'fields' => [
        'material_use' => $r['m_use'], 'material_location' => $r['m_ort'], 'purchase_model' => $r['k_mod'],
        'label' => $r['label'], 'material_ref_record' => $r['m_ding'], 'material_single' => $r['m_einz'], 'material_min' => $r['m_min'],
        'lot_material' => $r['l_mat'], 'lot_purchase' => $r['l_kauf'], 'lot_location' => $r['l_lo'], 'lot_value' => $r['l_wert'], 'lot_date' => $r['l_dat'],
        'transaction_kind' => $r['v_art'], 'transaction_date' => $r['v_dat'], 'transaction_order' => $r['v_best'], 'transaction_source' => $r['v_quel'], 'transaction_lines' => $r['v_pos'],
        'line_lot' => $r['p_lot'], 'line_quantity' => $r['p_menge'], 'line_counted' => $r['p_gez'],
        'order_status' => $r['o_stat'], 'order_lines' => $r['o_pos'],
        'purchase_material' => $r['k_mat'], 'purchase_quantity' => $r['k_anz'], 'purchase_undetermined' => $r['k_offen'],
        'purchase_landed_price' => $r['k_ein'], 'purchase_unit_price' => $r['k_preis'], 'money_amount' => 4234, 'money_unit' => 4236,
    ],
    'values' => [
        'kind_receipt' => $r['k_we'], 'kind_consumption' => $r['k_vb'], 'kind_count' => $r['k_in'], 'source_manual' => $r['q_hand'],
        'status_partial' => $r['s_teil'], 'status_received' => $r['s_ganz'], 'location_default' => $r['lager'],
    ],
];
update_option(StockService::OPTION, $struktur);

// Stock count: a new material from a count line, with a value.
(new StockService())->bookInventory([new InventoryRow(null, 'BC547C', null, '40', '0,05', 'Fach 3')], '2026-10-21', 'Inventur Kasten');
$s      = new StockService();
$bc     = array_search('BC547C', $s->labels('material'), true);
$pruefe(is_int($bc) && $s->ledger()->stockOf($bc) === 40.0, 'a count of 40 makes a new material with stock 40');

// Consumption: FIFO lot, negative line.
(new StockService())->bookConsumption((int) $bc, null, '15', null, '2026-10-21', 'Verbrauch Reparatur');
$pruefe((new StockService())->ledger()->stockOf((int) $bc) === 25.0, 'consuming 15 leaves 25');

// Goods receipt: the undetermined purchase is refused, the other one goes in and the order is partly received.
try {
    (new StockService())->bookReceipt($r['best'], [$r['kaufA'] => ReceiptChoice::keep(), $r['kaufB'] => ReceiptChoice::keep()], '2026-10-21', 'WE');
    $pruefe(false, 'an undetermined purchase is refused (R1)');
} catch (StockError $e) {
    $pruefe($e->reason === 'purchase_undetermined', 'an undetermined purchase is refused (R1), nothing written');
}

$pruefe((new StockService())->ledger()->stockOf($r['lm']) === 0.0, 'the refused receipt wrote nothing');

(new StockService())->bookReceipt($r['best'], [$r['kaufB'] => ReceiptChoice::keep()], '2026-10-21', 'WE');
$s   = new StockService();
$lot = $s->ledger()->lotsOf($r['lm'])[0] ?? 0;
$pruefe($s->ledger()->stockOf($r['lm']) === 5.0, 'receiving purchase B books its 5 pieces');
$wert = $s->table('lot')->first($lot, $r['l_wert']);
$pruefe($wert !== null && $s->table('money')->first((int) $wert, 4234) === '1.8', 'the lot carries the purchase price per piece');
$pruefe($s->table('order')->first($r['best'], $r['o_stat']) === (string) $r['s_teil'], 'the order is partly received');
$pruefe($s->receiptPlan($r['best'])[$r['kaufB']]['lock'] === 'received', 'purchase B now shows as received');

// D-967 · goods receipt from «Modell»: C has a material referring to its Modell, D gets a new one.
$s    = new StockService();
$plan = $s->receiptPlan($r['best2']);
$pruefe($plan[$r['kaufC']]['proposal']?->material === $r['mt1'], 'the receipt proposes the existing material whose Bezug is the purchase\'s Modell');
$pruefe($plan[$r['kaufD']]['proposal']?->isNew() === true && $plan[$r['kaufD']]['proposal']->thing === $r['t2'], 'without such a material it proposes a new one with Bezug = Modell');

$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['best2']];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'value="' . $r['mt1'] . '" selected') && str_contains($html, 'value="new" selected') && str_contains($html, '#' . $r['t2'] . ' · MPSA56'), 'the receipt form preselects the proposals and shows the Modell');

$antwort = $s->bookReceipt($r['best2'], [$r['kaufC'] => $plan[$r['kaufC']]['proposal'], $r['kaufD'] => $plan[$r['kaufD']]['proposal']], '2026-10-21', 'WE 2');
$s       = new StockService();
$neu     = (int) (array_values(array_filter($antwort['refs'] ?? [], static fn ($ref): bool => str_starts_with((string) $ref, 'm'), ARRAY_FILTER_USE_KEY))[0] ?? 0);
$mat     = $s->table('material');
$pruefe($neu > 0 && $mat->first($neu, $r['m_ding']) === (string) $r['t2'] && $mat->first($neu, $r['m_einz']) === '0', 'the new material refers to the Modell, Einzelstück 0');
$pruefe($neu > 0 && $mat->first($neu, $r['label']) === null, 'the new material has no Bezeichnung of its own');
$pruefe($s->table('purchase')->all($r['kaufD'], $r['k_mat']) === [(string) $neu] && $s->table('purchase')->all($r['kaufC'], $r['k_mat']) === [(string) $r['mt1']], 'the receipt writes the material into the purchase');
$pruefe($s->ledger()->stockOf($r['mt1']) === 3.0 && $s->ledger()->stockOf($neu) === 2.0, 'both purchases are in stock');
$pruefe($s->table('order')->first($r['best2'], $r['o_stat']) === (string) $r['s_ganz'], 'the order is received');

// D-967 · display: «#id · name of the Bezug», the own name only without one.
$namen = $s->materialNames();
$pruefe($namen->name($r['mt1']) === '#' . $r['mt1'] . ' · BC640' && $namen->name($neu) === '#' . $neu . ' · MPSA56', 'a material shows as «#id · name from Bezug Ding»');
$pruefe($namen->name($r['lm']) === '#' . $r['lm'] . ' · LM2930T-5.0', 'without a Bezug it shows its own Bezeichnung');

// D-967 · a count row with a Bezug writes no Bezeichnung.
$antwort = (new StockService())->bookInventory([new InventoryRow(null, '', $r['t2'], '4', null, '')], '2026-10-21', 'Inventur Bezug');
$neu2    = (int) ($antwort['refs']['m1'] ?? 0);
$s       = new StockService();
$pruefe($neu2 > 0 && $s->table('material')->first($neu2, $r['label']) === null && $s->table('material')->first($neu2, $r['m_ding']) === (string) $r['t2'], 'a count row with a Bezug writes no Bezeichnung');

// Tab «Material»: create with its own name, then give it a Bezug — the own name is emptied.
$antwort = (new StockService())->saveMaterial(null, new MaterialInput(null, 'Lötzinn', false, $r['u_eigen'], $r['lager'], 2));
$loet    = (int) ($antwort['refs']['m'] ?? 0);
$mat     = (new StockService())->table('material');
$pruefe($loet > 0 && $mat->first($loet, $r['label']) === 'Lötzinn' && $mat->first($loet, $r['m_use']) === (string) $r['u_eigen'] && $mat->first($loet, $r['m_ort']) === (string) $r['lager'] && $mat->first($loet, $r['m_min']) === '2', 'the tab creates a material with Verwendung, Lagerort and Mindestbestand');
(new StockService())->saveMaterial($loet, new MaterialInput($r['t1'], 'Lötzinn', true, null, null, null));
$mat = (new StockService())->table('material');
$pruefe($mat->first($loet, $r['label']) === null && $mat->first($loet, $r['m_ding']) === (string) $r['t1'] && $mat->first($loet, $r['m_use']) === null && $mat->first($loet, $r['m_einz']) === '1', 'editing it to a Bezug empties the Bezeichnung and clears what was left empty');

// The page renders its four tabs from the same data.
foreach (['bestand' => 'LM2930T-5.0', 'material' => '#' . $r['mt1'] . ' · BC640', 'inventur' => 'BC547C', 'wareneingang' => 'Bestellung 1', 'verbrauch' => 'Rest 25'] as $tab => $erwartet) {
    $_GET = ['page' => StockPage::PAGE, 'tab' => $tab, 'order' => $r['best']];
    $html = (new StockPage())->render();
    $pruefe(str_contains($html, $erwartet) && ! str_contains($html, 'notice-error'), "tab «{$tab}» shows «{$erwartet}»");
}

// A structure from before 0.1.2 still works, and the goods receipt says what it lacks.
$alt = $struktur;
unset($alt['fields']['purchase_model']);
update_option(StockService::OPTION, $alt);
$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['best']];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'fields.purchase_model') && (new StockService())->ledger()->stockOf($r['lm']) === 5.0, 'an old structure keeps working; the receipt asks for fields.purchase_model');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'bestand'];
$pruefe(! str_contains((new StockPage())->render(), 'notice-error'), 'the stock list needs no purchase_model');

// The Configuration tab: the modeller lists it, and pasting structure-live.json stores those ids.
add_filter('taxmod_config_tabs', [TaxmodStock\WordPress\Admin\SettingsTab::class, 'register']);
$reiter = Taxmod\WordPress\Admin\SettingsScreen::moduleTabs();
$pruefe(isset($reiter['stock']), 'the module shows up as a tab on Configuration');
$pruefe(array_key_exists('version', TaxmodStock\WordPress\Admin\SettingsTab::register([])['stock']), 'the tab entry carries the module version (D-968)');
($reiter['stock']['save'])(['taxmod_stock_structure' => (string) file_get_contents(__DIR__ . '/../../structure-live.json')]);
$pruefe((new StockService())->structure()->node('material') === 149000110147 && (new StockService())->structure()->field('purchase_model') === 149000109578, 'pasting structure-live.json stores the live ids, purchase_model included');
($reiter['stock']['save'])(['taxmod_stock_structure' => '{"nodes": {}}']);
$pruefe((new StockService())->structure()->node('material') === 149000110147, 'an incomplete structure is refused and the old one stays');

echo $fehler === 0 ? "all green\n" : "{$fehler} red\n";
exit($fehler === 0 ? 0 : 1);
