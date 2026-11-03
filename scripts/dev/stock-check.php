<?php declare(strict_types=1);

/**
 * Stage 1 end to end against a local WordPress: count, consume, receive, the thing as material (D-995) — and the stock that follows.
 *
 *     php scripts/dev/stock-check.php [path/to/wordpress]
 *
 * Builds a small Stock structure through `taxmod/apply`, points the module's option at it, books through the
 * module's service layer and checks the stock list. Uses the modeller's no-write bracket: nothing survives the run.
 * Needs the Taxonomy Modeller active (WP_ROOT, TAXMOD_ROOT override the paths).
 *
 * D-1097: runs twice, with «Positionen» of Vorgänge and Bestellungen as aggregation (as live until the switch) and as
 * composition (switched with `set_kind` after the records are written, as the live migration will) — the module books
 * alike before and after. TAXMOD_STOCK_KIND=aggregation|composition runs one of them.
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

use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\LagerInput;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\StockPage;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

[$text, $int, $decimal, $bool, $date, $money] = [1175, 1171, 1173, 1179, 1183, 4232];
$field = static fn (string $ref, int|string $node, string $name, int|string $target, string $kind, string $mult): array
    => ['op' => 'add_field', 'ref' => $ref, 'node' => $node, 'name' => $name, 'target' => $target, 'kind' => $kind, 'multiplicity' => $mult];

$aufbau = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'stock', 'name' => '__Stock', 'parent' => 402],
    // On Model itself, so the things in __Teile carry it as well as the Stock records.
    $field('label', 402, 'Bezeichnung', $text, 'composition', '0..1'),
    ['op' => 'create_node', 'ref' => 'mat', 'name' => 'Lager', 'parent' => '@stock'],
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
    ['op' => 'create_node', 'ref' => 'k_um', 'name' => 'Umbuchung', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'k_ul', 'name' => 'Umlagerung', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'stat', 'name' => '__Bestellstatus', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 's_teil', 'name' => 'teilweise eingegangen', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_ganz', 'name' => 'eingegangen', 'parent' => '@stat'],
    // D-1027: purchasing's statuses; D-1083: the reservation's kind.
    ['op' => 'create_node', 'ref' => 's_entw', 'name' => 'Entwurf', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_best', 'name' => 'bestellt', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_storn', 'name' => 'storniert', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 'k_res', 'name' => 'Reservierung', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'quel', 'name' => '__Quellen', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'q_hand', 'name' => 'von Hand', 'parent' => '@quel'],
    ['op' => 'create_node', 'ref' => 'verw', 'name' => '__Verwendungen', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'u_eigen', 'name' => 'Eigenbedarf', 'parent' => '@verw'],
    ['op' => 'create_node', 'ref' => 'teile', 'name' => '__Teile', 'parent' => 402],
    $field('m_ding', '@mat', 'Material', 402, 'aggregation', '1..1'),
    $field('m_einz', '@mat', 'Einzelstück', $bool, 'composition', '1..1'),
    $field('m_min', '@mat', 'Mindestbestand', $int, 'composition', '0..1'),
    $field('m_use', '@mat', 'Verwendung', '@verw', 'aggregation', '0..1'),
    $field('m_ort', '@mat', 'Standard-Lagerort', '@lo', 'aggregation', '0..1'),
    $field('l_mat', '@lot', 'Material', 402, 'aggregation', '1..1'),
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
    $field('p_lo', '@vp', 'Lagerort', '@lo', 'aggregation', '0..1'),
    $field('lo_vater', '@lo', 'Übergeordneter Ort', '@lo', 'aggregation', '0..1'),
    $field('lo_beweg', '@lo', 'beweglich', $bool, 'composition', '1..1'),
    $field('v_traeger', '@vg', 'Ladungsträger', '@lo', 'aggregation', '0..1'),
    $field('o_stat', '@ord', 'Status', '@stat', 'aggregation', '0..1'),
    $field('o_pos', '@ord', 'Positionen', '@pur', 'aggregation', '0..*'),
    $field('k_anz', '@pur', 'Anzahl', $int, 'composition', '0..1'),
    $field('k_mod', '@pur', 'Modell', 402, 'aggregation', '0..1'),
    $field('k_offen', '@pur', 'Noch nicht näher bestimmt', $bool, 'composition', '1..1'),
    $field('k_ein', '@pur', 'Einstandspreis je Stück', $money, 'composition', '0..1'),
    $field('k_preis', '@pur', 'Preis je Stück', $money, 'composition', '0..1'),
    $field('o_hand', '@ord', 'Händler', $text, 'composition', '0..1'),
    $field('k_bedarf', '@pur', 'Bedarf', '@ord', 'aggregation', '0..1'),
    $field('v_bezug', '@vg', 'Bezug', 402, 'aggregation', '0..1'),
    $field('p_ding', '@vp', 'Material', 402, 'aggregation', '0..1'),
    ['op' => 'create_record', 'ref' => 'lager', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@lager', 'values' => ['@label' => 'Lager']],
    ['op' => 'create_record', 'ref' => 'platz', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@platz', 'values' => ['@label' => 'Platinen']],
    // D-995: the things live in the tree; a Lager record hangs on the first.
    ['op' => 'create_record', 'ref' => 'lm', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@lm', 'values' => ['@label' => 'LM2930T-5.0']],
    ['op' => 'create_record', 'ref' => 'lmlager', 'node' => '@mat'],
    ['op' => 'set_values', 'record' => '@lmlager', 'values' => ['@m_ding' => '@lm', '@m_einz' => '0', '@m_min' => '2']],
    ['op' => 'create_record', 'ref' => 'kaufA', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufA', 'values' => ['@label' => 'Konvolut unbestimmt', '@k_offen' => '1']],
    ['op' => 'create_record', 'ref' => 'kaufB', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufB', 'values' => ['@label' => 'LM2930T-5.0 ×5', '@k_offen' => '0', '@k_mod' => '@lm', '@k_anz' => '5',
        '@k_preis' => ['neu' => ['4234' => '1.80']]]],
    ['op' => 'create_record', 'ref' => 'best', 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@best', 'values' => ['@label' => 'Bestellung 1', '@o_pos' => ['values' => ['@kaufA', '@kaufB']]]],
    // Two more things; an order whose first purchase names one as «Modell» and whose second names none.
    ['op' => 'create_record', 'ref' => 't1', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@t1', 'values' => ['@label' => 'BC640']],
    ['op' => 'create_record', 'ref' => 't2', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@t2', 'values' => ['@label' => 'MPSA56']],
    ['op' => 'create_record', 'ref' => 'kaufC', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufC', 'values' => ['@label' => 'BC640 ×3', '@k_offen' => '0', '@k_mod' => '@t1', '@k_anz' => '3']],
    ['op' => 'create_record', 'ref' => 'kaufD', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufD', 'values' => ['@label' => 'MPSA56 ×2', '@k_offen' => '0', '@k_anz' => '2']],
    ['op' => 'create_record', 'ref' => 'best2', 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@best2', 'values' => ['@label' => 'Bestellung 2', '@o_pos' => ['values' => ['@kaufC', '@kaufD']]]],
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

$struktur = [
    'nodes'  => ['lager' => $r['mat'], 'lot' => $r['lot'], 'transaction' => $r['vg'], 'line' => $r['vp'], 'location' => $r['lo'], 'order' => $r['ord'], 'purchase' => $r['pur'], 'money' => $money,
        'lager_use' => $r['verw']],
    'fields' => [
        'lager_use' => $r['m_use'], 'lager_location' => $r['m_ort'], 'purchase_model' => $r['k_mod'],
        'order_merchant' => $r['o_hand'], 'purchase_demand' => $r['k_bedarf'], 'transaction_reference' => $r['v_bezug'], 'line_thing' => $r['p_ding'],
        'label' => $r['label'], 'lager_thing' => $r['m_ding'], 'lager_single' => $r['m_einz'], 'lager_min' => $r['m_min'],
        'lot_thing' => $r['l_mat'], 'lot_purchase' => $r['l_kauf'], 'lot_location' => $r['l_lo'], 'lot_value' => $r['l_wert'], 'lot_date' => $r['l_dat'],
        'transaction_kind' => $r['v_art'], 'transaction_date' => $r['v_dat'], 'transaction_order' => $r['v_best'], 'transaction_source' => $r['v_quel'], 'transaction_lines' => $r['v_pos'],
        'line_lot' => $r['p_lot'], 'line_quantity' => $r['p_menge'], 'line_counted' => $r['p_gez'], 'line_location' => $r['p_lo'],
        'location_parent' => $r['lo_vater'], 'location_movable' => $r['lo_beweg'], 'transaction_carrier' => $r['v_traeger'],
        'order_status' => $r['o_stat'], 'order_lines' => $r['o_pos'],
        'purchase_quantity' => $r['k_anz'], 'purchase_undetermined' => $r['k_offen'],
        'purchase_landed_price' => $r['k_ein'], 'purchase_unit_price' => $r['k_preis'], 'money_amount' => 4234, 'money_unit' => 4236,
    ],
    'values' => [
        'kind_receipt' => $r['k_we'], 'kind_consumption' => $r['k_vb'], 'kind_count' => $r['k_in'], 'kind_transfer' => $r['k_um'], 'kind_relocation' => $r['k_ul'], 'source_manual' => $r['q_hand'],
        'status_partial' => $r['s_teil'], 'status_received' => $r['s_ganz'], 'location_default' => $r['lager'],
        'status_draft' => $r['s_entw'], 'status_ordered' => $r['s_best'], 'status_cancelled' => $r['s_storn'], 'kind_reservation' => $r['k_res'],
    ],
];
update_option(StockService::OPTION, $struktur);

$arten = (new StockService())->structureWithKinds();
$pruefe($arten->composes('transaction_lines') === ($kind === 'composition') && $arten->composes('order_lines') === ($kind === 'composition'), "the module reads «Positionen» as {$kind} from the model");

// Stock count: a new thing in a group from a count line, with a value (D-995).
$antwort = (new StockService())->bookInventory([new InventoryRow(null, new NewThing($r['teile'], 'BC547C'), 'BC547C', '40', '0,05', 'Fach 3')], '2026-10-21', 'Inventur Kasten');
$bc      = (int) ($antwort['refs']['d1'] ?? 0);
$s       = new StockService();
$pruefe($bc > 0 && $s->ledger()->stockOf($bc) === 40.0 && $s->tableOf($r['teile'])->first($bc, $r['label']) === 'BC547C', 'a count of 40 makes a new thing in its group with stock 40');

try {
    (new StockService())->bookInventory([new InventoryRow(null, new NewThing($r['lo'] + 999999, 'X'), 'X', '1', null, '')], '2026-10-21', 'Inventur');
    $pruefe(false, 'a new thing outside the tree is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'not_a_group', 'a new thing outside the tree is refused');
}

try {
    (new StockService())->bookInventory([new InventoryRow(null, new NewThing($r['lo'], 'X'), 'X', '1', null, '')], '2026-10-21', 'Inventur');
    $pruefe(false, 'the Lager branch itself is no group');
} catch (StockError $e) {
    $pruefe($e->reason === 'not_a_group', 'the Lager branch itself is no group');
}

// Consumption: FIFO lot, negative line.
(new StockService())->bookConsumption($bc, null, '15', Purpose::none(), '2026-10-21', 'Verbrauch Reparatur');
$pruefe((new StockService())->ledger()->stockOf($bc) === 25.0, 'consuming 15 leaves 25');

// Goods receipt: the undetermined purchase is refused, the other one goes in as its Modell and the order is partly received.
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
$pruefe($s->ledger()->stockOf($r['lm']) === 5.0, 'receiving purchase B books its 5 pieces on its Modell');
$wert = $s->table('lot')->first($lot, $r['l_wert']);
$pruefe($wert !== null && $s->table('money')->first((int) $wert, 4234) === '1.8', 'the lot carries the purchase price per piece');
$pruefe($s->table('order')->first($r['best'], $r['o_stat']) === (string) $r['s_teil'], 'the order is partly received');
$pruefe($s->receiptPlan($r['best'])[$r['kaufB']]['lock'] === 'received', 'purchase B now shows as received');

// D-995 · goods receipt: C keeps its Modell, D has none and the user chooses.
$s    = new StockService();
$plan = $s->receiptPlan($r['best2']);
$pruefe($plan[$r['kaufC']]['proposal']?->keep === true && $plan[$r['kaufC']]['model'] === $r['t1'], 'the receipt proposes the purchase\'s Modell');
$pruefe($plan[$r['kaufD']]['proposal'] === null, 'without a Modell the user chooses');

$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['best2']];
$html = (new StockPage())->render();
$hits = array_column($s->searchThings('BC640'), 'id');
$pruefe(in_array($r['t1'], $hits, true) && ! in_array($r['kaufC'], $hits, true), 'the material search finds the thing, never the purchase named like it');
// D-1011, his word «Erst Text, dann Baum»: the tree beside the search shows the groups of things, and a group its things.
$zweige = array_column($s->treeRows(), 'name', 'id');
$pruefe(isset($zweige[$r['teile']]) && ! isset($zweige[$r['pur']]), 'the tree beside the material search holds the groups of things, not the Lager\'s own branch');
$imAst = array_column($s->branchThings($r['teile']), 'id');
$pruefe(in_array($r['t1'], $imAst, true) && ! in_array($r['kaufC'], $imAst, true), 'a group of the tree gives its things, never a purchase');
// His report 2026-10-28: «LD1111V33 wird zweimal angezeigt … einmal als quellen verzeichnis» — a branch named as holding no material drops out.
update_option(StockService::NOT_MATERIAL, [$r['teile']]);
$ohne = new StockService();
$pruefe(! in_array($r['t1'], array_column($ohne->searchThings('BC640'), 'id'), true) && ! isset(array_column($ohne->treeRows(), 'name', 'id')[$r['teile']]), 'a branch without material is neither searched nor in the tree');
delete_option(StockService::NOT_MATERIAL);
// His word 2026-10-28: «eigentlich haben wir doch den tax chooser» — the row holds the modeller's chooser, no «neu anlegen».
// D-1111: the chosen material's word is a link to the row's «Material» dialog now, no longer a plain span.
$pruefe(str_contains($html, 'name="thing[' . $r['kaufC'] . ']" value="' . $r['t1'] . '" checked') && str_contains($html, 'for="taxmod-stock-material-' . $r['kaufC'] . '" style="cursor:pointer;color:#2271b1;text-decoration:underline">BC640</label>')
    && str_contains($html, 'name="thing[' . $r['kaufD'] . ']" value="" checked') && ! str_contains($html, 'name="group[' . $r['kaufD'] . ']"'), 'the receipt row holds the modeller\'s chooser with the Modell, and no field for a new thing');
$pruefe(str_contains($html, '<span class="description">3 Stück</span>'), 'each purchase says how many were bought, under its label');

// D-972 · the branch of the purchase's thing decides the place; the thing's own place would win.
update_option(StockService::LOCATIONS, ['branches' => [$r['teile'] => $r['platz']], 'unreferenced' => null]);
$orte = (new StockService())->receiptLocations($plan);
$pruefe(($orte[$r['kaufC']] ?? 0) === $r['platz'], 'the receipt proposes the place mapped to the branch the Modell lies in');
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'name="location[' . $r['kaufC'] . ']"') && str_contains($html, 'value="' . $r['platz'] . '" selected'), 'the receipt form shows the proposed place, changeable');

$antwort = $s->bookReceipt($r['best2'], [$r['kaufC'] => ReceiptChoice::keep()->at($orte[$r['kaufC']]), $r['kaufD'] => ReceiptChoice::create(new NewThing($r['teile'], 'MPSA56 neu'))], '2026-10-21', 'WE 2');
$s       = new StockService();
$neu     = (int) (array_values(array_filter($antwort['refs'], static fn ($v, $k): bool => preg_match('/^d\d+$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH))[0] ?? 0);
$pruefe($neu > 0 && $s->tableOf($r['teile'])->first($neu, $r['label']) === 'MPSA56 neu', 'the new thing is created in the chosen group with its name');
$pruefe($s->table('purchase')->first($r['kaufD'], $r['k_mod']) === (string) $neu, 'the receipt writes the new thing into the purchase\'s Modell');
$pruefe($s->ledger()->stockOf($r['t1']) === 3.0 && $s->ledger()->stockOf($neu) === 2.0, 'both purchases are in stock');
$pruefe($s->table('order')->first($r['best2'], $r['o_stat']) === (string) $r['s_ganz'], 'the order is received');
$platz = $s->table('lot');
$pruefe(array_filter($s->ledger()->lotsOf($r['t1']), static fn (int $l): bool => $platz->first($l, $r['l_lo']) === (string) $r['platz']) !== [], 'the lot lies at the chosen place');
$pruefe($s->table('lot')->first($lot, $r['l_lo']) === (string) $r['lager'], 'a receipt without a choice stays at the default place');

try {
    (new StockService())->bookReceipt($r['best'], [$r['kaufA'] => ReceiptChoice::keep()->at($r['lm'])], '2026-10-21', 'WE');
    $pruefe(false, 'a place that is not a location is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'not_a_location', 'a place that is not a location is refused');
}

// D-995 · the name is the thing's own, its number beside it.
$namen = $s->things();
$pruefe($namen->name($r['t1']) === 'BC640 (Nr. ' . $r['t1'] . ')' && $namen->name($r['lm']) === 'LM2930T-5.0 (Nr. ' . $r['lm'] . ')', 'a material is its thing, named by its own label (D-988)');
$pruefe($namen->lagerOf($r['lm']) === $r['lmlager'], 'the Lager record hangs on its thing');

// D-997 · tab «Lager»: saving creates the Lager record once, then updates it.
(new StockService())->saveLager($r['t1'], new LagerInput(false, $r['u_eigen'], $r['lager'], 2));
$s      = new StockService();
$record = $s->things()->lagerOf($r['t1']);
$lager  = $s->table('lager');
$pruefe($record !== null && $lager->first($record, $r['m_use']) === (string) $r['u_eigen'] && $lager->first($record, $r['m_ort']) === (string) $r['lager'] && $lager->first($record, $r['m_min']) === '2', 'the tab creates the Lager record with Verwendung, Lagerort and Mindestbestand');
(new StockService())->saveLager($r['t1'], new LagerInput(true, null, null, null));
$s = new StockService();
$pruefe($s->things()->lagerOf($r['t1']) === $record && $s->table('lager')->first($record, $r['m_use']) === null && $s->table('lager')->first($record, $r['m_einz']) === '1', 'saving again updates the same record and clears what was left empty');

// D-1039 · «Umbuchen» books − old place / + new place; the lot keeps the place it came in at (0.11.0).
$von = (new StockService())->ledger()->homeOf($lot);
(new StockService())->moveLot($lot, $von, $r['platz'], '1', '2026-11-02', 'Umbuchung');
$ledger = (new StockService())->ledger();
$pruefe($ledger->restAt($lot, $r['platz']) === 1.0 && (new StockService())->table('lot')->first($lot, $r['l_lo']) !== (string) $r['platz'], '«Umbuchen» moves one piece to the chosen place as a Vorgang, the lot keeps its own place');

// The page renders its tabs from the same data.
foreach (['material' => '>' . $r['lm'] . '</a></td>', 'inventur' => 'Material suchen', 'wareneingang' => 'Bestellung 1', 'verbrauch' => 'Rest 25'] as $tab => $erwartet) {
    $_GET = ['page' => StockPage::PAGE, 'tab' => $tab, 'order' => $r['best']];
    $html = (new StockPage())->render();
    $pruefe(str_contains($html, $erwartet) && ! str_contains($html, 'notice-error'), "tab «{$tab}» shows «{$erwartet}»");
}

// D-997 · one thing opened: the head fixed, the views as tabs.
foreach (['daten' => 'Taxonomy Modeller', 'einkauf' => 'LM2930T-5.0 ×5', 'lager' => 'Mindestbestand', 'bestand' => 'Umbuchen', 'verkauf' => 'später'] as $view => $erwartet) {
    $_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $r['lm'], 'view' => $view];
    $html = (new StockPage())->render();
    $pruefe(str_contains($html, '>LM2930T-5.0</h2>') && str_contains($html, 'nav-tab-active') && str_contains($html, $erwartet) && ! str_contains($html, 'notice-error'), "the thing's tab «{$view}» shows its head and «{$erwartet}»");
}

$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'group' => (string) $r['teile']];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'value="' . $r['teile'] . '" selected') && str_contains($html, 'LM2930T-5.0') && ! str_contains($html, 'notice-error'), 'the list filters by group');

// Laid out as the eBay shipping page (2026-10-25): «Wareneingang» first and default, list tables with search, filter and sorting.
$_GET = ['page' => StockPage::PAGE];
$html = (new StockPage())->render();
// His word 2026-11-02: «Lagerort mit in den Bestand verschieben und den Menüpunkt Lager entfernen» — the tabs are the menu group
// «Bestand»; «Wareneingang» is Eingang → Einbuchen and the structure a setting, both without the stock's tabs.
$pruefe(strpos($html, 'nav-tab-active') === false && str_contains($html, 'taxmod-list-details'), 'without a tab the page opens «Wareneingang», without the tabs of «Bestand»');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$tabs = (new StockPage())->render();
$pruefe(str_contains($tabs, 'tab=lagerorte') && str_contains($tabs, 'tab=reservierungen') && ! str_contains($tabs, 'tab=lagerstruktur') && ! str_contains($tabs, 'tab=wareneingang'), 'the tabs are Material, Inventur, Verbrauch, Reservierungen, Lagerorte');
$_GET = ['page' => StockPage::PAGE];
$pruefe(str_contains($html, 'Bestellung 1') && str_contains($html, 'tr class="taxmod-list-details"><td') && ! str_contains($html, 'tr class="taxmod-list-details" hidden') && ! str_contains($html, 'name="purchases[]"'), 'by default the orders stand open (shared list page, D-989), their purchases load as they scroll into view');
// D-1085: the view of the tabs is set on Logistik › Einstellungen › Grundeinstellungen, no longer on the Configuration tab.
(new TaxmodStock\WordPress\Admin\SettingsPage())->save(['taxmod_stock_views_sent' => '1', 'taxmod_stock_views' => ['material' => '1']]);
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'tr class="taxmod-list-details" hidden') && ! str_contains($html, 'tr class="taxmod-list-details"><td') && ! str_contains($html, 'name="purchases[]"'), 'with «Gleich aufgeklappt» off the purchases stay folded and unloaded');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'tr class="taxmod-list-details"><td') && ! str_contains($html, 'tr class="taxmod-list-details" hidden'), 'with «Gleich aufgeklappt» on for Material the lots stand open');
delete_option(StockService::VIEWS);
$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['best']];
$pruefe(str_contains((new StockPage())->render(), 'data-loaded="1"'), 'an order handed in is open and loaded');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'taxmod-list') && str_contains($html, 'search-box') && str_contains($html, '<tfoot>') && str_contains($html, 'sortable') && str_contains($html, 'nav-tab-active') && str_contains($html, 'Taxonomy Modeller › Lager'), 'the material list is the shared list page with tabs, search and sortable columns');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 's' => 'LM2930'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'LM2930T-5.0') && ! str_contains($html, 'BC640'), 'the search narrows the material list');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'filter' => 'instock', 'orderby' => 'stock', 'order' => 'desc'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'sorted desc') && str_contains($html, 'value="instock" selected') && ! str_contains($html, 'notice-error'), 'the material list sorts by stock and filters');

$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'status' => '', 's' => 'MPSA56'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'Bestellung 2') && ! str_contains($html, 'Bestellung 1'), 'the goods receipt finds an order by what its purchases are');

// Concept R3 · a lot purchase told apart: its parts become purchases of the order, each with its material, and can then be received.
$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['best']];
$pruefe(str_contains((new StockPage())->render(), 'split=' . $r['kaufA']), 'a lot purchase offers «Aufteilen»');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'wareneingang', 'order' => $r['best'], 'split' => $r['kaufA']];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'Konvolut aufteilen: Konvolut unbestimmt') && str_contains($html, 'name="rows[0][quantity]"') && ! str_contains($html, 'notice-error'), 'the split form names the lot purchase and offers rows');
(new StockService())->splitPurchase($r['best'], $r['kaufA'], [
    new SplitRow($r['t1'], null, 'BC640 aus Konvolut', '2', null),
    new SplitRow(null, new NewThing($r['teile'], 'MPSA42'), 'MPSA42', '3', '0,30'),
]);
$s     = new StockService();
$teile = $s->table('order')->all($r['best'], $r['o_pos']);
$plan  = $s->receiptPlan($r['best']);
$neuK  = (int) (array_values(array_diff($teile, [(string) $r['kaufA'], (string) $r['kaufB']]))[0] ?? 0);
$pruefe(count($teile) === 3 && $neuK > 0, 'the second part joins the order as a purchase of its own');
$pruefe($plan[$r['kaufA']]['lock'] === null && $plan[$r['kaufA']]['model'] === $r['t1'] && $s->table('purchase')->first($r['kaufA'], $r['label']) === 'BC640 aus Konvolut', 'the lot purchase became its first part, determined, with its material');
$pruefe(isset($plan[$neuK]) && $plan[$neuK]['lock'] === null && ($plan[$neuK]['model'] ?? 0) > 0 && $s->table('purchase')->first($neuK, $r['k_anz']) === '3', 'the new part has its new material and its quantity');
$vor = $s->ledger()->stockOf($r['t1']);
(new StockService())->bookReceipt($r['best'], [$r['kaufA'] => ReceiptChoice::keep(), $neuK => ReceiptChoice::keep()], '2026-10-28', 'WE Konvolut');
$s = new StockService();
$pruefe($s->ledger()->stockOf($r['t1']) === $vor + 2.0 && $s->ledger()->stockOf((int) $plan[$neuK]['model']) === 3.0 && $s->table('order')->first($r['best'], $r['o_stat']) === (string) $r['s_ganz'], 'both parts are received and the order is complete');

// D-1090/D-1091, his words «links baum rechts detail» and «Ja das passt»: places in the modeller's tree, a box is a carrier.
$ort = static fn (string $name, int $parent, bool $movable = false): int => (int) ((new StockService())->createPlace($name, $parent, $movable, 0)['refs']['ort'] ?? 0);
$schrank = $ort('__Schrank', $r['lager']);
$fach1   = $ort('__Schublade 1', $schrank);
$fach2   = $ort('__Schublade 2', $schrank);
$kiste   = $ort('__Kiste', $fach1, true);
$s       = new StockService();
$posten  = (int) ($s->ledger()->lotsOf($bc)[0] ?? 0);
$wo      = $s->ledger()->placesOf($posten);
$menge   = (float) reset($wo);
$s->moveLot($posten, (int) key($wo), $kiste, (string) $menge, '2026-11-02', 'Umbuchung in die Kiste');
$bestand = (new StockService())->ledger()->stockOf($bc);
$vorgaenge = count((new StockService())->table('transaction')->ids());
(new StockService())->movePlace($kiste, $fach2, '2026-11-02', 'Umlagerung');
$s = new StockService();
$pruefe($s->locationTree()->parentOf($kiste) === $fach2, 'a box from Schublade 1 to Schublade 2 of the same Schrank hangs there');
$pruefe(array_map(static fn (array $m): array => [substr($m['date'], 0, 10), $m['from'], $m['to']], $s->relocationsOf($kiste)) === [['2026-11-02', $fach1, $fach2]], 'its move is a Vorgang «Umlagerung» with the box, from and to (D-1091)');
$pruefe(($s->ledger()->placesOf($posten)[$kiste] ?? 0.0) === $menge && $s->ledger()->stockOf($bc) === $bestand, 'what lies in the box stays in it; no lot is rebooked');
(new StockService())->movePlace($fach1, 0, '2026-11-02', 'Umlagerung');
$pruefe(count((new StockService())->table('transaction')->ids()) === $vorgaenge + 1, 'a fixed place hung elsewhere is a change of the structure, no Vorgang');
(new StockService())->movePlace($fach1, $schrank, '2026-11-02', 'Umlagerung');

try {
    (new StockService())->movePlace($schrank, $kiste);
    $pruefe(false, 'a place does not go into what lies in it');
} catch (StockError $e) {
    $pruefe($e->reason === 'place_into_itself', 'a place does not go into what lies in it');
}

$_GET = ['page' => StockPage::PAGE, 'tab' => 'lagerstruktur', 'ort' => $kiste];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'taxmod-record-tree') && str_contains($html, 'data-taxmod-drag="1"') && str_contains($html, 'tab=lagerstruktur&amp;ort=__ID__') && str_contains($html, '__Kiste (1)'), 'Lagerstruktur: the tree on the left, a click opens a place, the count follows the name');
$pruefe(str_contains($html, '<h2 style="margin-top:0">__Kiste') && str_contains($html, 'Lager › __Schrank › __Schublade 2') && str_contains($html, 'Umlagerungen') && str_contains($html, 'taxmod-stock-move-place-' . $kiste) && str_contains($html, 'taxmod-stock-new-place-' . $kiste), 'Lagerstruktur: the place on the right with where it lies, its moves and its actions');
$pruefe(str_contains($html, 'data-taxmod-drop-record="0"') && str_contains($html, 'taxmod-stock-drop-place') && str_contains($html, 'name="return_ort" value="' . $kiste . '"'), 'Lagerstruktur: the top level takes a drop, a drop asks first, the forms come back to the place');
$pruefe(! str_contains($html, 'name="parent[' . $kiste . ']" value="' . $kiste . '"'), 'Verschieben does not offer the box itself');
$parts = (new StockPage())->parts();
$pruefe(str_contains($parts['side'], 'taxmod-record-tree') && str_contains($parts['side'], 'data-taxmod-drop-record="0"') && str_contains($parts['side'], 'for="taxmod-stock-new-place-0"') && ! str_contains($parts['main'], 'data-taxmod-pick=') && str_contains($parts['main'], 'id="taxmod-stock-new-place-0"'), 'D-1092: the tree goes under the navigation, the right side is only the place; «Neuer Lagerort» opens its dialog from there');
$framed = LogisticsShell::wrap(StockPage::PAGE, $parts['main'], $parts['side']);
$pruefe(str_contains($framed, 'class="taxmod-shell has-side"') && strpos($framed, 'class="taxmod-shell-side"') > strpos($framed, 'class="taxmod-shell-nav"') && strpos($framed, 'class="taxmod-shell-side"') < strpos($framed, 'class="taxmod-shell-main"'), 'D-1092: in the frame the tree is a second box in the navigation column');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'lagerorte', 'ort' => $schrank];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'data-taxmod-stock-lot="' . $posten . ':' . $kiste . ':' . rtrim(rtrim(number_format($menge, 3, '.', ''), '0'), '.') . ':' . $bc . '"') && str_contains($html, '__Schublade 2 › __Kiste') && str_contains($html, 'BC547C'), 'Lagerorte: what lies at the place and below it, each row draggable onto a place');
$pruefe(str_contains($html, 'taxmod-stock-drop-lot') && ! str_contains($html, 'taxmod-stock-move-place-'), 'Lagerorte: a lot dropped on a place asks for the Umbuchung; no structure actions here');

// D-1027 with D-1097/D-1098: a demand, a source and back, a position removed — the same before and after the switch.
$offen = static function (StockService $s): array {
    $orders = $s->table('order');

    return array_values(array_filter($orders->ids(), static fn (int $o): bool => $s->purchasing()->isDemand($o, $orders)));
};
(new StockService())->reorder([$r['t1'] => '2', $r['t2'] => '1'], null, '__Bedarf');
$s      = new StockService();
$bedarf = $offen($s)[0] ?? 0;
$pos    = array_map('intval', $s->table('order')->all($bedarf, $r['o_pos']));
$nachT1 = array_values(array_filter($pos, static fn (int $k): bool => $s->table('purchase')->first($k, $r['k_mod']) === (string) $r['t1']))[0] ?? 0;
$pruefe(count($pos) === 2 && $nachT1 > 0 && $s->table('purchase')->first($nachT1, $r['k_anz']) === '2', '«Nachbestellen» makes a demand with one purchase per thing');
(new StockService())->reorder([$r['t1'] => '3'], $bedarf, '');
$pruefe((new StockService())->table('purchase')->first($nachT1, $r['k_anz']) === '5' && count((new StockService())->table('order')->all($bedarf, $r['o_pos'])) === 2, 'the same thing again raises the quantity, no second position');
(new StockService())->sourcePosition($bedarf, $nachT1, null, 'Reichelt', '', null);
$s       = new StockService();
$entwurf = $s->purchasing()->draftOf('Reichelt', $s->table('order')) ?? 0;
$pruefe($entwurf > 0 && $s->table('order')->all($entwurf, $r['o_pos']) === [(string) $nachT1] && ! in_array((string) $nachT1, $s->table('order')->all($bedarf, $r['o_pos']), true), 'a source moves the position into the supplier\'s new draft, keeping its id (D-1098)');
$pruefe($s->table('purchase')->first($nachT1, $r['k_bedarf']) === (string) $bedarf && $s->table('purchase')->first($nachT1, $r['k_anz']) === '5', 'the position points back at its demand and keeps its values');
(new StockService())->unsourcePosition($entwurf, $nachT1);
$s = new StockService();
$pruefe(in_array((string) $nachT1, $s->table('order')->all($bedarf, $r['o_pos']), true) && $s->table('order')->all($entwurf, $r['o_pos']) === [] && $s->table('order')->first($entwurf, $r['o_stat']) === (string) $r['s_storn'], 'back into the demand; the emptied draft is cancelled');
(new StockService())->removePosition($bedarf, $nachT1);
$s = new StockService();
$pruefe(count($s->table('order')->all($bedarf, $r['o_pos'])) === 1 && ! $s->table('purchase')->has($nachT1), 'a position removed leaves its demand and is gone');

// D-1083 with D-1097: a second position joins the subject's open reservation.
(new StockService())->reserve($r['t2'], '', $bc, '1', null, '2026-11-02', 'Reservierung');
(new StockService())->reserve($r['t2'], '', $bc, '2', null, '2026-11-02', 'Reservierung');
$s     = new StockService();
$offenR = $s->reservations()->forSubject($r['t2']);
$pruefe($offenR !== null && count($s->table('transaction')->all($offenR, $r['v_pos'])) === 2 && ($s->reservations()->reservedByThing()[$bc] ?? 0.0) === 3.0, 'one reservation per subject, holding both positions');

// D-1097, his word «eine Position gehört zu genau einem Kopf»: after every booking above, each line and each purchase has one head.
$s = new StockService();
foreach ([['line', 'transaction', $r['v_pos']], ['purchase', 'order', $r['o_pos']]] as [$teil, $kopf, $liste]) {
    $koepfe = [];

    foreach ($s->table($kopf)->ids() as $k) {
        foreach ($s->table($kopf)->all($k, $liste) as $p) {
            $koepfe[(int) $p][] = $k;
        }
    }

    $ohne = array_filter($s->table($teil)->ids(), static fn (int $p): bool => count($koepfe[$p] ?? []) !== 1);
    $pruefe($ohne === [] && array_diff(array_keys($koepfe), $s->table($teil)->ids()) === [], "every {$teil} stands in exactly one {$kopf}, and no {$kopf} lists a {$teil} that is gone");
}

// A structure pasted before 0.2.0 still reads: the old keys name the same ids.
$alt = $struktur;
$alt['nodes']['material'] = $alt['nodes']['lager'];
$alt['fields']['lot_material'] = $alt['fields']['lot_thing'];
$alt['fields']['material_ref_record'] = $alt['fields']['lager_thing'];
unset($alt['nodes']['lager'], $alt['fields']['lot_thing'], $alt['fields']['lager_thing']);
update_option(StockService::OPTION, $alt);
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$pruefe(! str_contains((new StockPage())->render(), 'notice-error') && (new StockService())->ledger()->stockOf($r['lm']) === 5.0, 'an old structure keeps working');

// The Configuration tab: the modeller lists it, and pasting structure-live.json stores those ids.
add_filter('taxmod_config_tabs', [TaxmodStock\WordPress\Admin\SettingsTab::class, 'register']);
$reiter = Taxmod\WordPress\Admin\SettingsScreen::moduleTabs();
$pruefe(isset($reiter['stock']), 'the module shows up as a tab on Configuration');
$pruefe(array_key_exists('version', TaxmodStock\WordPress\Admin\SettingsTab::register([])['stock']), 'the tab entry carries the module version (D-968)');
($reiter['stock']['save'])(['taxmod_stock_structure' => (string) file_get_contents(__DIR__ . '/../../structure-live.json')]);
$pruefe((new StockService())->structure()->node('lager') === 149000110147 && (new StockService())->structure()->field('lot_thing') === 149000110118, 'pasting structure-live.json stores the live ids');
($reiter['stock']['save'])(['taxmod_stock_structure' => '{"nodes": {}}']);
$pruefe((new StockService())->structure()->node('lager') === 149000110147, 'an incomplete structure is refused and the old one stays');
// D-1085: branch → place moved from the Configuration tab to Logistik › Einstellungen › Grundeinstellungen.
(new TaxmodStock\WordPress\Admin\SettingsPage())->save(['taxmod_stock_branch' => [['node' => '500', 'location' => '7'], ['node' => '', 'location' => '3'], ['node' => '501', 'location' => '']], 'taxmod_stock_unreferenced' => '9']);
$orte = (new StockService())->locationSettings();
$pruefe($orte->branches === [500 => 7] && $orte->unreferenced === 9, 'Grundeinstellungen store branch → place rows, half-filled rows are dropped (D-972, D-1085)');

echo $fehler === 0 ? "all green ({$kind})\n" : "{$fehler} red ({$kind})\n";
exit($fehler === 0 ? 0 : 1);
