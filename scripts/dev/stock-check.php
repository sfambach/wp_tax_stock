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
use TaxmodStock\Core\ConsumptionByKind;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\ReceiptReversal;
use TaxmodStock\Core\PeriodActivity;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use Taxmod\Core\Renderer\DecimalText;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\StockPage;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

require __DIR__ . '/lib/struktur.php';

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
// His report 2026-11-08 (HD15): the receipt line names its place, so a «Lagerort» changed later on the lot moves nothing booked.
$weZeile = array_values(array_filter($s->table('line')->ids(), static fn (int $l): bool => (int) $s->table('line')->first($l, $r['p_lot']) === $lot));
$pruefe(count($weZeile) === 1 && $s->table('line')->first($weZeile[0], $r['p_lo']) === (string) $r['lager'], 'the receipt line names the place the lot came in');
$wert = $s->table('lot')->first($lot, $r['l_wert']);
$pruefe($wert !== null && $s->table('money')->first((int) $wert, 4234) === '1.8', 'the lot carries the purchase price per piece');
$pruefe($s->table('order')->first($r['best'], $r['o_stat']) === (string) $r['s_teil'], 'the order is partly received');
$pruefe($s->receiptPlan($r['best'])[$r['kaufB']]['lock'] === 'received', 'purchase B now shows as received');

// D-995 · goods receipt: C keeps its Modell, D has none and the user chooses.
$s    = new StockService();
$plan = $s->receiptPlan($r['best2']);
$pruefe($plan[$r['kaufC']]['proposal']?->keep === true && $plan[$r['kaufC']]['model'] === $r['t1'], 'the receipt proposes the purchase\'s Modell');
$pruefe($plan[$r['kaufD']]['proposal'] === null, 'without a Modell the user chooses');

// D-1440: the receipt is the one table of «Angekommen»; drawn here for one order.
$einbuchen = static fn (int $order): string => (new StockPage())->receiptTable([$order => (string) ((new StockService())->labels('order')[$order] ?? '')], InboundPage::BACK, '');
$html = $einbuchen($r['best2']);
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

// D-972, D-1259 · the group's «Standard-Lagerort» (own or inherited) decides the place; the thing's own place would win.
(new StockService())->setGroupProperty($r['teile'], StockService::PLACE_PROPERTY, $r['platz']);
$orte = (new StockService())->receiptLocations($plan);
$pruefe(($orte[$r['kaufC']] ?? 0) === $r['platz'], 'the receipt proposes the place mapped to the branch the Modell lies in');
$html = $einbuchen($r['best2']);
// D-1186, A4 (his word 2026-11-06 «sollte tree chooser mit suche sein»): the place is chosen in the modeller's tree with search.
$pruefe(str_contains($html, 'name="location[' . $r['kaufC'] . ']" value="' . $r['platz'] . '" checked') && str_contains($html, 'taxmod-tree-filter'), 'the receipt form shows the proposed place in the tree chooser with search, changeable');
$pruefe(str_contains($html, 'class="taxmod-stock-receipt-place" data-drawn="' . $r['platz'] . '"'), 'D-1361: the row\'s place cell says what the server proposed, so closing the material dialog can swap only it');

// His report 2026-11-09 «wenn ich ein material neu anlege wird der lagerort aus dem neuen material nicht übernommen»: a row left at
// the place shown before the new material existed takes the new material's own (its group's); a place he changed stays.
$gezeigt = (int) ((new StockService())->receiptLocations([$r['kaufD'] => $plan[$r['kaufD']]])[$r['kaufD']] ?? 0);
$plaetze = new ReflectionMethod(StockPage::class, 'assignedPlaces');
$_POST   = ['order' => (string) $r['best2'], 'location' => [$r['kaufD'] => (string) $gezeigt], 'newgroup' => [$r['kaufD'] => (string) $r['teile']]];
$frei    = $plaetze->invoke(new StockPage());
$_POST['location'][$r['kaufD']] = (string) $r['platz'];
$eigen   = $plaetze->invoke(new StockPage());
$_POST   = [];
$pruefe($gezeigt > 0 && $gezeigt !== $r['platz'] && ($frei[$r['kaufD']] ?? -1) === 0 && ($eigen[$r['kaufD']] ?? 0) === $r['platz'],
    'D-1277: «Neues Material» in a row left at its shown place takes the group\'s place; a place chosen by hand stays');

// His wish 2026-11-05: «Menge 1, geliefert wurden 40» — the quantity is typed in the row and written to the purchase.
$pruefe(str_contains($html, 'name="quantity[' . $r['kaufD'] . ']" value="2"'), 'each open receipt row holds the purchase quantity, editable');
(new StockService())->assignPurchases($r['best2'], [], [], [], [$r['kaufD'] => '4']);
$pruefe((new StockService())->table('purchase')->first($r['kaufD'], $r['k_anz']) === '4', '«Zuordnung speichern» writes a changed quantity into the purchase');

try {
    (new StockService())->assignPurchases($r['best2'], [], [], [], [$r['kaufD'] => '0']);
    $pruefe(false, 'a quantity below 1 is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'not_a_quantity' && (new StockService())->table('purchase')->first($r['kaufD'], $r['k_anz']) === '4', 'a quantity below 1 is refused, nothing written');
}

// D-1264: the purchases carry pictures; the receipt hands them to materials without one.
(new StockService())->write([['op' => 'set_values', 'record' => (string) $r['kaufD'], 'values' => [(string) $r['bilder'] => ['values' => ['media:4711']]]],
    ['op' => 'set_values', 'record' => (string) $r['kaufC'], 'values' => [(string) $r['bilder'] => ['values' => ['media:4712']]]]]);
$vorBild = (new StockService())->picturesOf([$r['t1']])[$r['t1']]['pictures'];
$antwort = (new StockService())->bookReceipt($r['best2'], [$r['kaufC'] => ReceiptChoice::keep()->at($orte[$r['kaufC']]), $r['kaufD'] => ReceiptChoice::create(new NewThing($r['teile'], 'MPSA56 neu'))], '2026-10-21', 'WE 2', [$r['kaufD'] => '2']);
$s       = new StockService();
$neu     = (int) (array_values(array_filter($antwort['refs'], static fn ($v, $k): bool => preg_match('/^d\d+$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH))[0] ?? 0);
$bilder  = (new StockService())->picturesOf([$neu, $r['t1']]);
$pruefe($bilder[$neu]['pictures'] === ['media:4711'] && $bilder[$r['t1']]['pictures'] === ($vorBild === [] ? ['media:4712'] : $vorBild), 'D-1264: the receipt gives a material without a picture its purchase\'s, as a reference; one with pictures keeps them');
(new StockService())->write([['op' => 'set_values', 'record' => (string) $r['kaufC'], 'values' => [(string) $r['bilder'] => ['values' => ['media:4713']]]]]);
(new StockService())->takePurchasePicture($r['kaufC']);
(new StockService())->takePurchasePicture($r['kaufC']);
$pruefe((array_count_values((new StockService())->picturesOf([$r['t1']])[$r['t1']]['pictures'])['media:4713'] ?? 0) === 1, 'D-1264: «Bild übernehmen» appends the purchase\'s picture to its material, once');
$pruefe($neu > 0 && $s->tableOf($r['teile'])->first($neu, $r['label']) === 'MPSA56 neu', 'the new thing is created in the chosen group with its name');
$pruefe($s->table('purchase')->first($r['kaufD'], $r['k_mod']) === (string) $neu, 'the receipt writes the new thing into the purchase\'s Modell');
$pruefe($s->ledger()->stockOf($r['t1']) === 3.0 && $s->ledger()->stockOf($neu) === 2.0, 'both purchases are in stock');
$pruefe($s->table('purchase')->first($r['kaufD'], $r['k_anz']) === '2', 'the quantity typed at the receipt is written and counted in the same step');
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

// D-1361, his report 2026-11-14 «übernimmt er immer noch nicht den Lagerort, den ich eingestellt habe im Material»: a saved place
// that is only the group's proposal gives way to the material's own place set later; a place he chose stays.
$ort = static fn (): int => (int) ((new StockService())->receiptLocations([$r['kaufC'] => $plan[$r['kaufC']]])[$r['kaufC']] ?? 0);
(new StockService())->planPlaces([$r['kaufC'] => $r['platz']]);
(new StockService())->saveLager($r['t1'], new LagerInput(true, null, $r['lager'], null));
$folgt = $ort();
(new StockService())->planPlaces([$r['kaufC'] => $r['lager']]);
(new StockService())->saveLager($r['t1'], new LagerInput(true, null, $r['platz'], null));
$bleibt = $ort();
(new StockService())->planPlaces([$r['kaufC'] => 0]);
// D-1360: several Standard-Lagerorte, the first is the proposal; the Lager tab shows each and one free chooser.
(new StockService())->saveLager($r['t1'], new LagerInput(true, null, $r['platz'], null, null, null, null, [$r['lager']]));
$zwei   = (new StockService())->table('lager')->all((int) (new StockService())->things()->lagerOf($r['t1']), $r['m_ort']);
$erster = $ort();
$_GET   = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $r['t1'], 'view' => 'lager'];
$reiter = (new StockPage())->render();
$pruefe($zwei === [(string) $r['platz'], (string) $r['lager']] && $erster === $r['platz'], 'D-1360: a material keeps several Standard-Lagerorte in order, and the first is the receipt\'s proposal');
$bevorzugt = (new StockService())->placeChooser('probe', 0, '', [], (new StockService())->standardPlaces([$r['t1']])[$r['t1']] ?? []);
$pruefe(! defined('Taxmod\\WordPress\\Plugin::VERSION') || version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.2.95', '<') || str_contains($bevorzugt, '★ '), 'D-1360: the receipt row\'s place chooser shows the material\'s Standard-Lagerorte on top');
$pruefe(str_contains($reiter, 'name="locations[0]"') && str_contains($reiter, 'name="locations[1]"') && str_contains($reiter, 'name="locations[2]"'), 'D-1360: the Lager tab shows each Standard-Lagerort and one free chooser');
(new StockService())->saveLager($r['t1'], new LagerInput(true, null, null, null));
$pruefe($r['lager'] !== $r['platz'] && $folgt === $r['lager'] && $bleibt === $r['lager'], 'D-1361: the group\'s saved proposal gives way to the material\'s own place; a place chosen in the row stays');

// D-1039 · «Umbuchen» books − old place / + new place; the lot keeps the place it came in at (0.11.0).
$von = (new StockService())->ledger()->homeOf($lot);
(new StockService())->moveLot($lot, $von, $r['platz'], '1', '2026-11-02', 'Umbuchung');
$ledger = (new StockService())->ledger();
$pruefe($ledger->restAt($lot, $r['platz']) === 1.0 && (new StockService())->table('lot')->first($lot, $r['l_lo']) !== (string) $r['platz'], '«Umbuchen» moves one piece to the chosen place as a Vorgang, the lot keeps its own place');

// D-1414 · «Umbuchen, Quelle danach leer»: the rest left at the source goes out as a Vorgang «Inventur» in the same act,
// and one undo takes both back.
$vorher = (new StockService())->ledger()->restAt($lot, $von);
$leer   = $vorher > 1.0 ? (new StockService())->moveLot($lot, $von, $r['platz'], '1', '2026-11-16', 'Umbuchung, Quelle leer', true) : [];
$nach   = (new StockService())->ledger();
$zurueck = isset($leer['act']) ? $apply([['op' => 'undo_act', 'act' => $leer['act']]]) : [];
$wieder = (new StockService())->ledger();
$pruefe($vorher > 1.0 && $nach->restAt($lot, $von) === 0.0 && $nach->restAt($lot, $r['platz']) === 2.0
    && ! is_wp_error($zurueck) && $wieder->restAt($lot, $von) === $vorher && $wieder->restAt($lot, $r['platz']) === 1.0,
    'D-1414: «Quelle danach leer» books the rest at the source out by inventory in the same act; one undo takes both back');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $r['t1'], 'view' => 'bestand'];
$html = (new StockPage())->render();
// Changed with D-1414's successor (his word 2026-11-18 08:08 «eher … so eine Checkbox»): a checkbox, off by default, naming the quantity.
$pruefe(str_contains($html, '<input type="checkbox" name="source_empty" value="1">') && ! str_contains($html, 'name="source_empty" value="1" checked') && str_contains($html, 'Quelle danach leer: was am alten Ort übrig bleibt (jetzt'), 'D-1414 as changed 2026-11-18: «Quelle danach leer» is a checkbox, off by default, naming the quantity');
// D-1436: «Ausbuchen» of a row is a dialog with verb and subject in its title, no longer a link to the page.
$pruefe(str_contains($html, 'taxmod-stock-bookout-') && str_contains($html, 'value="verbrauch"') && str_contains($html, 'Ausbuchen: ') && str_contains($html, 'Umbuchen: '), 'D-1436: «Ausbuchen» and «Umbuchen» are dialogs whose titles name verb and subject');

// D-1438 · «Platz leer?» (his word 2026-11-18 08:18): «leer» is the moved material at that place, all its lots there; «Noch dort»
// books that much back in at the old place as «Inventur», with the Umbuchung in one act, and one undo takes both back.
$amPlatz = (new StockService())->restOfThingAt($lot, $r['platz']);
$noch    = $amPlatz > 0.0 ? (new StockService())->moveLot($lot, $r['platz'], $von, DecimalText::stored($amPlatz), '2026-11-18', 'Umbuchung, noch dort', false, '2') : [];
$nach    = (new StockService())->ledger();
$zurueck = isset($noch['act']) ? $apply([['op' => 'undo_act', 'act' => $noch['act']]]) : [];
$pruefe($amPlatz > 0.0 && $nach->restAt($lot, $r['platz']) === 2.0 && ! is_wp_error($zurueck) && (new StockService())->restOfThingAt($lot, $r['platz']) === $amPlatz,
    'D-1438: «Noch dort» books the quantity back in at the old place in the same act as the Umbuchung; one undo takes both back');
set_transient('taxmod_stock_empty_question_' . get_current_user_id(), ['thing' => $r['t1'], 'lot' => $lot, 'lots' => '', 'from' => $r['platz'], 'location' => $von,
    'quantity' => '1', 'return_tab' => '', 'return_ort' => 0], HOUR_IN_SECONDS);
$_GET  = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $r['t1'], 'view' => 'bestand'];
$frage = (new StockPage())->render();
$pruefe(str_contains($frage, 'Platz leer? ') && str_contains($frage, 'name="source_checked" value="1"') && str_contains($frage, 'name="source_answer" value="leer"')
    && str_contains($frage, 'name="source_answer" value="noch"') && str_contains($frage, 'taxmod-stock-source-empty-' . $lot . '-' . $r['platz'] . '" checked')
    && ! str_contains((new StockPage())->render(), 'Platz leer? '),
    'D-1438: an Umbuchung held back by «Platz leer?» comes back as an open dialog with both answers, once');

// The page renders its tabs from the same data.
foreach (['material' => '>' . $r['lm'] . '</a></td>', 'inventur' => 'Material suchen', 'verbrauch' => 'Bestandsposten'] as $tab => $erwartet) {
    // D-1147: the material list waits for a search; «Alle anzeigen» draws it all.
    $_GET = ['page' => StockPage::PAGE, 'tab' => $tab, 'order' => $r['best']] + ($tab === 'material' ? [ListPage::SHOW_ALL => '1'] : []);
    $html = (new StockPage())->render();
    $pruefe(str_contains($html, $erwartet) && ! str_contains($html, 'notice-error'), "tab «{$tab}» shows «{$erwartet}»");
}

$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$html = (new StockPage())->render();
$pruefe(! str_contains($html, '>' . $r['lm'] . '</a></td>') && str_contains($html, ListPage::SHOW_ALL . '=1') && ! str_contains($html, 'notice-error'), 'D-1147: without a search the material list draws no row and offers «Alle anzeigen»');

// His wish 2026-11-03: «Umbuchen» already in the material list — here the lot lies at two places, so each gets its own
// in the row's details, and the dialog leads back to the list.
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', ListPage::SHOW_ALL => '1'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'taxmod-list-lazy') && str_contains($html, 'data-source="' . StockPage::DETAILS_MATERIAL . '"'), 'D-1147: a material row loads its lots when opened');
$liste = $html;
$html  = (string) (new StockPage())->listDetails(null, StockPage::DETAILS_MATERIAL, ['thing' => (string) $r['lm']]);
$pruefe(str_contains($html, 'taxmod-stock-transfer-' . $lot . '-' . $r['platz'] . '-list') && str_contains($html, 'name="return_tab" value="materialliste"'), '«Umbuchen» sits in the material list for each place of a lot and returns to the list');
// His word 2026-11-05: «das müssten zwei positonen … sein», «bei mehreren positionen den platz in der zusammenfassung weg lassen».
$pruefe(substr_count($html, '<li>') === 2 && preg_match_all('/<li>[^<]*· Rest 1 /', $html) === 1 && substr_count($html, 'taxmod-dialog-open" for="taxmod-stock-transfer-') === 2, 'a lot at two places is two positions, each with its own quantity and «Umbuchen»');

// His wish 2026-11-03: «3 material wählen alle umbuchen nach» — all of each ticked material to one place, one Vorgang.
$pruefe(str_contains($liste, 'value="sammelumbuchen" form="taxmod-stock-bulk"') && str_contains($liste, 'name="bulk" id="taxmod-stock-bulk-action"'), 'the material list has «Umbuchen nach …» joining the bulk form');
(new StockService())->moveThings([$r['lm']], $von, '2026-11-02', 'Sammelumbuchung');
$ledger = (new StockService())->ledger();
$pruefe($ledger->restAt($lot, $von) === $ledger->restOf($lot) && $ledger->restAt($lot, $r['platz']) === 0.0, '«Umbuchen nach …» moves all of a material from every place to the one chosen');
try {
    (new StockService())->moveThings([$r['lm']], $von, '2026-11-02', 'nichts');
    $pruefe(false, 'a bulk Umbuchung with nothing left to move is refused');
} catch (TaxmodStock\Core\StockError $e) {
    $pruefe(true, 'a bulk Umbuchung with nothing left to move is refused');
}

// D-997 · one thing opened: the head fixed, the views as tabs.
foreach (['daten' => 'Taxonomy Modeller', 'einkauf' => 'LM2930T-5.0 ×5', 'lager' => 'Mindestbestand', 'bestand' => 'Umbuchen', 'verkauf' => 'später'] as $view => $erwartet) {
    $_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $r['lm'], 'view' => $view];
    $html = (new StockPage())->render();
    $pruefe(str_contains($html, '>LM2930T-5.0</h2>') && str_contains($html, 'nav-tab-active') && str_contains($html, $erwartet) && ! str_contains($html, 'notice-error'), "the thing's tab «{$view}» shows its head and «{$erwartet}»");
}

$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $r['lm'], 'view' => 'daten'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'tab=gruppenbestand&#038;group=' . $r['teile'] . '"') || str_contains($html, 'tab=gruppenbestand&amp;group=' . $r['teile'] . '"'), 'each group of the head path links to its «Gruppenbestand» (2026-11-06)');

$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$html = (new StockPage())->render();
// D-1433 G (modeller 0.2.134): the empty choice now carries WordPress' words, so the check asks for the empty option, not its text.
$pruefe(str_contains($html, '<option value="">') && str_contains($html, 'data-taxmod-ask="taxmod-stock-bulk-transfer"') && str_contains($html, 'taxmod-bulk-asks'), 'D-1202: «Umbuchen nach …» stands in the one bulk choice, its dialog opens from there');

$pruefe(! class_exists(Taxmod\Core\Renderer\DialogMarkup::class) || (str_contains($html, 'data-taxmod-ask="taxmod-stock-material-labels"') && str_contains($html, 'value="materialetiketten-pdf"') && str_contains($html, '>Material 40×30</option>')), 'D-1202: «Etiketten drucken …» for the ticked materials stands in the one bulk choice, with the material labels');

// His word 2026-11-08: «kein Standard-Int-Feld. Sondern wieder was Eigenes.» — the label count is the model's integer field.
$pruefe(! class_exists(Taxmod\WordPress\Draw\DialogNumber::class) || (str_contains($html, '<input type="number" name="copies" form="') && ! str_contains($html, 'name="copies" min="1" max="50" step="1" placeholder=')), 'the label count in «Etiketten drucken …» is the model\'s integer field');

// D-1439 (moved from the modeller's LabelButton, PR-9): the stock hands its list to the labels module's one render path.
if (class_exists(TaxmodLabel\WordPress\LabelButton::class)) {
    $vorlagen = (new StockService())->labelTemplates();
    $dialog   = array_diff_key($vorlagen->dialog($r['etk40'], ['record' => $r['lm']]), ['choices' => 1, 'chosen' => 1, 'printers' => 1]);
    $gefuellt = TaxmodLabel\WordPress\LabelButton::filled(new TaxmodLabel\Core\LabelContent('M-' . $r['lm'], 'LM2930T-5.0'), $dialog);
    $pruefe($gefuellt !== null && ($gefuellt->values['Bezeichnung'] ?? '') === 'LM2930T-5.0' && $gefuellt->format->widthMm === 40.0, 'D-1202: a ticked material\'s label reads its own «Bezeichnung», on the 40×30 label');
    $pdf = apply_filters('taxmod_label_render', null, [['code' => 'M-' . $r['lm'], 'title' => 'LM2930T-5.0', 'copies' => 2, 'template' => $dialog]], 'pdf');
    $pruefe(is_array($pdf) && str_starts_with((string) ($pdf['pdf'] ?? ''), '%PDF') && ($pdf['labels'] ?? 0) === 2, 'D-1439: the material labels go through taxmod_label_render, one PDF for the list');
} else {
    echo "stock: labels module not active, D-1202/D-1439 not checked\n";
}

// D-1238: «print-label» reaches the place label through the filter, not only material and lot.
$platzEtikett = apply_filters('taxmod_label_for', null, 'O', $r['platz'], $r['etk40'], 0);
$pruefe(is_array($platzEtikett) && isset($platzEtikett['template']), 'D-1238: a place label (O-) comes back through taxmod_label_for');

// D-1239: the label abilities moved to the labels module (taxmod-label/labels, label-save, label-remove; D-1296) — their check went with them.

if (method_exists(ListPage::class, 'thumbnails')) {
    $_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'group' => (string) $r['teile']];
    $mit  = (new StockPage())->render();
    update_option('taxmod_list_images', '0');
    $ohne = (new StockPage())->render();
    delete_option('taxmod_list_images');
    $pruefe(str_contains($mit, '>Bild<') && ! str_contains($ohne, '>Bild<'), 'D-1204: the material list has a picture column, and none with pictures switched off');
}

$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'group' => (string) $r['teile']];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'value="' . $r['teile'] . '" selected') && str_contains($html, 'LM2930T-5.0') && ! str_contains($html, 'notice-error'), 'the list filters by group');

// D-1174: «Gruppenbestand» — nothing until a group is picked in the tree; then everything in it and below it, with the totals.
$_GET = ['page' => StockPage::PAGE, 'tab' => 'gruppenbestand'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'Materialgruppen') && str_contains($html, 'Links eine Materialgruppe wählen') && ! str_contains($html, 'LM2930T-5.0</a>') && ! str_contains($html, 'notice-error'), 'D-1174: «Gruppenbestand» waits for a group from the tree');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'gruppenbestand', 'group' => (string) $r['teile']];
$html = (new StockPage())->render();
$pruefe(! class_exists(Taxmod\Core\Renderer\DialogMarkup::class) || str_contains($html, 'value="materialetiketten-pdf"'), 'D-1202: the group stock offers the same bulk label print');
$pruefe(str_contains($html, 'LM2930T-5.0') && str_contains($html, 'Menge gesamt') && str_contains($html, 'data-source="' . StockPage::DETAILS_MATERIAL . '"') && ! str_contains($html, 'notice-error'), 'D-1174: a group shows its materials, the totals as tiles and lots loaded when a row opens');
// D-1259, his word «wir müssen einstellungen auch an der material gruppe ermöglichen»: the group's Lager view sets its properties.
$pruefe(str_contains($html, 'value="' . StockPage::GROUP_PROPERTIES . '"') && str_contains($html, 'id="taxmod-stock-group-test"') && str_contains($html, 'name="place"'), 'D-1259: the group\'s Lager view offers «Nach Wareneingang testen» and «Standard-Lagerort»');
(new StockService())->setGroupProperty($r['teile'], StockService::TEST_PROPERTY, true);
$html = (new StockPage())->render();
// Grenzlauf 2026-11-17 (modeller D-1427, PR-9): the field is drawn by the modeller's form renderer now, which writes a bare `selected`.
$jaGewaehlt = static fn (string $html): bool => (bool) preg_match('/<option value="1" selected(?:=\'selected\')?>Ja<\/option>/', $html);
$pruefe($jaGewaehlt($html), 'D-1259: a group\'s own «Ja» stands chosen');
$above = (new StockService())->groupParents()[$r['teile']] ?? 0;
$above > 0 ? (new StockService())->setGroupProperty($above, StockService::TEST_PROPERTY, true) : null;
$html = (new StockPage())->render();
$pruefe($above > 0 && $jaGewaehlt($html), 'D-1259: an own «Ja» equal to the one above still stands as its own, so saving keeps it');
$above > 0 ? (new StockService())->setGroupProperty($above, StockService::TEST_PROPERTY, null) : null;
(new StockService())->setGroupProperty($r['teile'], StockService::TEST_PROPERTY, null);
// D-1266, his choice «Material- und Posten-Etikett»: the group's Lager view chooses both default labels from «Materialetiketten».
$pruefe(str_contains($html, 'name="' . StockService::MATERIAL_LABEL_PROPERTY . '"') && str_contains($html, 'name="' . StockService::LOT_LABEL_PROPERTY . '"') && str_contains($html, '<option value="' . $r['etk40'] . '"'), 'D-1266: the group\'s Lager view offers «Standard-Etikett Material» and «Standard-Etikett Posten» from the label category');
(new StockService())->setGroupProperty($r['teile'], StockService::MATERIAL_LABEL_PROPERTY, $r['etk40']);
$pruefe((new StockService())->groupLabelOf($r['t1'], StockService::MATERIAL_LABEL_PROPERTY) === $r['etk40'] && (new StockService())->groupLabelOf($r['t1'], StockService::LOT_LABEL_PROPERTY) === 0, 'D-1266: a material reads its group\'s default label, the lot label stays unset');
(new StockService())->setGroupProperty($r['teile'], StockService::MATERIAL_LABEL_PROPERTY, null);

// D-1440, his words 2026-11-18 11:33/11:36: «Einbuchen» is no item of its own — booking is part of «Angekommen», one row per
// purchase under its order's line, one bar, nothing to open. ⚠️ Changed with D-1440: the checks of the old order list with
// «Gleich aufgeklappt» (D-989) are gone with that list; «Wareneingang» is no longer a choice of «Ansicht der Reiter».
// Gone too: «the goods receipt finds an order by what its purchases are» — «Angekommen» searches with the cockpit's own search.
$links = array_column(array_merge(...array_column(TaxmodStock\WordPress\Admin\LogisticsShell::navigation()->areas(), 'items')), 'tab');
$pruefe(! in_array('wareneingang', $links, true) && in_array('arrived', $links, true), 'D-1440: the navigation has no «Einbuchen»; booking stands under «Angekommen»');
$pruefe(! array_key_exists('wareneingang', TaxmodStock\Core\ViewSettings::DEFAULTS), 'D-1440: «Gleich aufgeklappt» is no choice for the goods receipt any more');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$tabs = (new StockPage())->render();
// D-1243: the tabs of «Bestand» are gone; every one of its pages is still an item of the navigation on the left.
$pruefe(! str_contains($tabs, 'nav-tab-wrapper') && array_diff(['material', 'gruppenbestand', 'inventur', 'verbrauch', 'reservierungen', 'lagerorte'], $links) === [], 'D-1243: «Bestand» has no tabs; Material, Gruppenbestand, Inventur, Verbrauch, Reservierungen, Platzbestand stand in the navigation');
$html = (new StockPage())->receiptTable([$r['best'] => 'Bestellung 1', $r['best2'] => 'Bestellung 2'], InboundPage::BACK, '');
$pruefe(substr_count($html, 'taxmod-stock-order-head') === 2 && substr_count($html, 'id="taxmod-stock-receipt-all"') === 1 && substr_count($html, 'taxmod-stock-receipt-bulk-0') >= 1
    && substr_count($html, 'Je Kauf das Material:') === 1 && ! str_contains($html, 'taxmod-list-details') && str_contains($html, 'name="order_of[' . $r['kaufC'] . ']" value="' . $r['best2'] . '"'),
    'D-1440: the purchases of several orders stand in one table under their order\'s line, with one bar, one form and the explanation once');
$pruefe(str_contains($html, 'Angehakte einlagern …') && str_contains($html, 'Lagerort für alle …') && str_contains($html, 'value="zuordnen"'), 'D-1440: the one bar has «Angehakte einlagern …», «Lagerort für alle …» and saving');
// An open row drawn on its own: by now the fixture's purchases are booked, so the row is given as open (lock none, no material).
$zeile = new ReflectionMethod(StockPage::class, 'receiptRow');
$suche = new ReflectionMethod(StockPage::class, 'receiptLookups');
$offen = [$r['kaufC'] => ['model' => null, 'lock' => null, 'proposal' => null]];
delete_option(StockService::RECEIPT_INBOUND);
$seite = new StockPage();
$ohne  = (string) $zeile->invoke($seite, $r['best2'], $r['kaufC'], $offen[$r['kaufC']], ['where' => []] + $suche->invoke($seite, $offen, false), false, [], InboundPage::BACK, true);
update_option(StockService::RECEIPT_INBOUND, $r['platz'], false);
$seite = new StockPage();
$mit   = (string) $zeile->invoke($seite, $r['best2'], $r['kaufC'], $offen[$r['kaufC']], ['where' => []] + $suche->invoke($seite, $offen, false), false, [], InboundPage::BACK, true);
$pruefe(str_contains($mit, 'value="positioneinlagern-' . $r['kaufC'] . '"') && ! str_contains($ohne, 'value="positioneinlagern-' . $r['kaufC'] . '"')
    && str_contains($ohne, '«Lagerort Wareneingang» in den Einstellungen') && str_contains($mit, 'name="order_of[' . $r['kaufC'] . ']"'),
    'D-1440: each open row has «Einlagern»; without a place and without «Lagerort Wareneingang» it is greyed and says why');
delete_option(StockService::RECEIPT_INBOUND);
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'taxmod-list') && str_contains($html, 'search-box') && str_contains($html, '<tfoot>') && str_contains($html, 'sortable') && ! str_contains($html, 'taxmod-stock-pagetabs') && str_contains($html, 'Taxonomy Modeller › Lager'), 'the material list is the shared list page with search and sortable columns, without the tabs of «Bestand» (D-1243)');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 's' => 'LM2930'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'LM2930T-5.0') && ! str_contains($html, 'BC640'), 'the search narrows the material list');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'filter' => 'instock', 'orderby' => 'stock', 'order' => 'desc'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'sorted desc') && str_contains($html, 'value="instock" selected') && ! str_contains($html, 'notice-error'), 'the material list sorts by stock and filters');

// Concept R3 · a lot purchase told apart: its parts become purchases of the order, each with its material, and can then be received.
$pruefe(str_contains($einbuchen($r['best']), 'split=' . $r['kaufA']), 'a lot purchase offers «Aufteilen»');
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

// His wish 2026-11-04: «liste mit kindern und massen funktion … verschieben» — the ticked Unterorte move in one write.
$vorgaenge = count((new StockService())->table('transaction')->ids());
(new StockService())->movePlaces([$fach1, $fach2], $r['lager'], '2026-11-04', 'Umlagerung');
$s = new StockService();
$pruefe($s->locationTree()->parentOf($fach1) === $r['lager'] && $s->locationTree()->parentOf($fach2) === $r['lager'] && $s->locationTree()->parentOf($kiste) === $fach2 && count($s->table('transaction')->ids()) === $vorgaenge, 'Verschieben of ticked Unterorte: both hang there with what is in them; fixed places make no Vorgang');
(new StockService())->movePlaces([$fach1, $fach2], $schrank, '2026-11-04', 'Umlagerung');
(new StockService())->movePlaces([$kiste], $fach1, '2026-11-04', 'Umlagerung');
$pruefe(count((new StockService())->table('transaction')->ids()) === $vorgaenge + 1, 'a ticked box moved is a Vorgang «Umlagerung» (D-1091)');
(new StockService())->movePlace($kiste, $fach2, '2026-11-04', 'Umlagerung');
try {
    (new StockService())->movePlaces([$fach1], $schrank, '2026-11-04', 'Umlagerung');
    $pruefe(false, 'ticked places already there move nothing');
} catch (StockError $e) {
    $pruefe($e->reason === 'nothing_to_book', 'ticked places already there move nothing');
}

// D-1134, his word «plätze im layoout zuletzt erstellten 3 plätze ? … od er5»: the Layout tile counts the places and names the newest.
$_GET  = ['page' => TaxmodStock\WordPress\Admin\CockpitPage::PAGE, TaxmodStock\WordPress\Admin\CockpitPage::AREA => 'settings', TaxmodStock\WordPress\Admin\CockpitPage::GROUP => 'Lagerstruktur'];
$kachel = (new TaxmodStock\WordPress\Admin\CockpitPage())->render();
$_GET  = [];
$pruefe(str_contains($kachel, '<span class="taxmod-tile-label">Layout</span><span class="taxmod-tile-figure">' . count((new StockService())->places()) . '</span>') && str_contains($kachel, '<span class="taxmod-tile-line">Zuletzt angelegt:</span><span class="taxmod-tile-line">Lager › __Schrank › __Schublade 2 › __Kiste</span><span class="taxmod-tile-line">Lager › __Schrank › __Schublade 2</span>'), 'the Layout tile counts the places and names the newest first');

// D-1167, his word «eine Zeitraumübersicht … Umschalter über den Zeitraum … Tage vor- und zurückwählen»: the day of the
// receipts above shows them, against the day before, with a chart and the list of what happened.
$_GET     = ['page' => TaxmodStock\WordPress\Admin\CockpitPage::PAGE, 'span' => 'day', 'day' => '2026-10-21'];
$zeitraum = (new TaxmodStock\WordPress\Admin\CockpitPage())->render();
$_GET     = [];
$pruefe(! str_contains($zeitraum, 'notice-error') && str_contains($zeitraum, '<span class="taxmod-tile-label">Wareneingang</span>') && str_contains($zeitraum, 'class="taxmod-chart"'), 'Zeitraum: the day of the receipts has its tiles and its chart (D-1167)');
$pruefe(substr_count($zeitraum, '<td>21.10.2026</td>') >= 2 && str_contains($zeitraum, 'aria-current="true">Day</a>'), 'Zeitraum: the Vorgänge of the day are listed, the span «Day» is marked');

// D-1139, his word «projekt gebinde … haben die ja ein bom und auch evtl einen bestand … ob der übereinstimmt bzw was fehlt».
$bom = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'proj', 'name' => '__Projekte', 'parent' => $r['stock']],
    ['op' => 'create_node', 'ref' => 'plat', 'name' => '__Platinen', 'parent' => $r['stock']],
    ['op' => 'create_node', 'ref' => 'rev', 'name' => '__Revisionen', 'parent' => $r['stock']],
    ['op' => 'create_node', 'ref' => 'sl', 'name' => '__Part List Item', 'parent' => $r['stock']],
    $field('lo_proj', $r['lo'], 'Projekt', '@proj', 'aggregation', '0..1'),
    $field('pj_plat', '@proj', 'Platinen', '@plat', 'aggregation', '0..*'),
    $field('pl_rev', '@plat', 'Revisionen', '@rev', 'aggregation', '1..*'),
    $field('rv_pos', '@rev', 'Positionen', '@sl', 'aggregation', '0..*'),
    $field('sl_part', '@sl', 'Part', 402, 'aggregation', '1..1'),
    $field('sl_anz', '@sl', 'Anzahl', $int, 'composition', '1..1'),
    ['op' => 'create_record', 'ref' => 'alt', 'node' => '@sl'],
    ['op' => 'set_values', 'record' => '@alt', 'values' => ['@sl_part' => (string) $r['lm'], '@sl_anz' => '9']],
    ['op' => 'create_record', 'ref' => 'sl1', 'node' => '@sl'],
    ['op' => 'set_values', 'record' => '@sl1', 'values' => ['@sl_part' => (string) $bc, '@sl_anz' => (string) ((int) $menge + 1)]],
    ['op' => 'create_record', 'ref' => 'sl2', 'node' => '@sl'],
    ['op' => 'set_values', 'record' => '@sl2', 'values' => ['@sl_part' => (string) $r['t2'], '@sl_anz' => '1']],
    ['op' => 'create_record', 'ref' => 'r1', 'node' => '@rev'],
    ['op' => 'set_values', 'record' => '@r1', 'values' => [(string) $r['label'] => 'Rev 1.0', '@rv_pos' => ['values' => ['@alt']]]],
    ['op' => 'create_record', 'ref' => 'r2', 'node' => '@rev'],
    ['op' => 'set_values', 'record' => '@r2', 'values' => [(string) $r['label'] => 'Rev 1.1', '@rv_pos' => ['values' => ['@sl1', '@sl2']]]],
    ['op' => 'create_record', 'ref' => 'pl', 'node' => '@plat'],
    ['op' => 'set_values', 'record' => '@pl', 'values' => [(string) $r['label'] => 'Hauptplatine', '@pl_rev' => ['values' => ['@r1', '@r2']]]],
    ['op' => 'create_record', 'ref' => 'pj', 'node' => '@proj'],
    ['op' => 'set_values', 'record' => '@pj', 'values' => [(string) $r['label'] => '__Testkarte', '@pj_plat' => ['values' => ['@pl']]]],
    ['op' => 'set_values', 'record' => $schrank, 'values' => ['@lo_proj' => '@pj']],
]]);
$b = is_wp_error($bom) ? [] : $bom['refs'];
$pruefe($b !== [], 'the parts list of a project is set up' . (is_wp_error($bom) ? ': ' . $bom->get_error_message() : ''));
$struktur['nodes']  += ['project' => $b['proj'] ?? 0, 'board' => $b['plat'] ?? 0, 'board_revision' => $b['rev'] ?? 0, 'bom_position' => $b['sl'] ?? 0];
$struktur['fields'] += ['location_project' => $b['lo_proj'] ?? 0, 'project_boards' => $b['pj_plat'] ?? 0, 'board_revisions' => $b['pl_rev'] ?? 0, 'revision_positions' => $b['rv_pos'] ?? 0, 'position_part' => $b['sl_part'] ?? 0, 'position_count' => $b['sl_anz'] ?? 0];
update_option(StockService::OPTION, $struktur);
$s     = new StockService();
$boxes = $s->projectBoxes();
$bill  = $s->projectBill((int) ($b['pj'] ?? 0));
$rows  = $bill === null ? [] : array_column($bill->compare($s->presentIn($schrank)), null, 'thing');
$pruefe($boxes === [$schrank => (int) ($b['pj'] ?? 0)], 'the Schrank with a project is a project box');
$pruefe($bill?->revisions === [(int) ($b['r2'] ?? 0)] && ! isset($rows[$r['lm']]), 'the newest revision makes the list; the older one does not count');
$pruefe(($rows[$bc]['present'] ?? null) === $menge && ($rows[$bc]['missing'] ?? null) === 1.0 && ($rows[$r['t2']]['missing'] ?? null) === 1.0, 'what lies in the box inside the Schrank counts; one piece short and one part missing');
$_GET  = ['page' => StockPage::PAGE, 'tab' => 'fertigung'];
$seite = implode('', (new StockPage())->parts());
$pruefe(str_contains($seite, '__Testkarte') && str_contains($seite, '2 Teile fehlen') && str_contains($seite, 'Stückliste aus: Rev 1.1'), 'Fertigung › Projekt-Gebinde shows the box, its project and what is missing');
$summary = apply_filters('taxmod_stock_project_boxes', null, []);
$pruefe(is_array($summary) && ($summary[$schrank]['project'] ?? 0) === (int) ($b['pj'] ?? 0) && ($summary[$schrank]['pieces'] ?? null) === array_sum($s->presentIn($schrank)) && ($summary[$schrank]['label'] ?? '') === $s->locationTree()->pathOf($schrank), 'D-1301: project_boxes answers each box with its project, its path and the pieces in it and below it');
$_GET  = [];

try {
    (new StockService())->movePlace($schrank, $kiste);
    $pruefe(false, 'a place does not go into what lies in it');
} catch (StockError $e) {
    $pruefe($e->reason === 'place_into_itself', 'a place does not go into what lies in it');
}

// D-1130: «Kopieren» — a place with or without its places inside, a material with its Lager view; nothing in stock comes along.
$s       = new StockService();
$orte    = count($s->places());
$kopie   = $s->copyPlace($schrank, '__Schrank (Kopie)', false);
$s       = new StockService();
$pruefe($kopie > 0 && count($s->places()) === $orte + 1 && $s->locationTree()->parentOf($kopie) === $r['lager'] && $s->locationTree()->pathOf($kopie) === $s->locationTree()->pathOf($r['lager']) . ' › __Schrank (Kopie)', 'Kopieren: the place stands beside itself under its new name, alone');
$tief    = (new StockService())->copyPlace($schrank, '__Schrank 2', true);
$s       = new StockService();
$baum    = $s->locationTree();
$pruefe(count($s->places()) === $orte + 1 + 4 && count($baum->withAllBelow($tief)) === 4 && in_array('Lager › __Schrank 2 › __Schublade 2 › __Kiste', array_map($baum->pathOf(...), $baum->withAllBelow($tief)), true), 'Kopieren with Unterorte: each place inside hangs in the copy of its parent');
$pruefe($baum->isMovable((int) array_values(array_filter($baum->withAllBelow($tief), static fn (int $p): bool => str_ends_with($baum->pathOf($p), '__Kiste')))[0]), 'the copied box is still a box');
$pruefe(array_sum(array_map(static fn (int $p): float => array_sum(array_map(static fn (array $at): float => (float) ($at[$p] ?? 0.0), $s->ledger()->stockByPlace())), $baum->withAllBelow($tief))) === 0.0 && $s->ledger()->stockOf($bc) === $bestand, 'nothing that lay in the original lies in the copy; the stock is unchanged');
(new StockService())->saveLager($bc, new LagerInput(true, null, $schrank, 7));
$teil  = (new StockService())->copyThing($bc);
$s     = new StockService();
$lager = $s->things()->lagerOf($teil);
$pruefe($teil > 0 && $teil !== $bc && $s->tableOf($r['teile'])->first($teil, $r['label']) === 'BC547C (Kopie)', 'Kopieren: the material stands beside itself with «(Kopie)» after its own name');
$pruefe($lager !== null && $lager !== $s->things()->lagerOf($bc) && $s->table('lager')->first($lager, $r['m_min']) === '7' && $s->table('lager')->first($lager, $r['m_ort']) === (string) $schrank && $s->table('lager')->first($lager, $r['m_einz']) === '1', 'the copy has a Lager view of its own with the same minimum, place and single flag');
$pruefe($s->ledger()->stockOf($teil) === 0.0 && $s->ledger()->stockOf($bc) === $bestand, 'the copy has no stock; the original keeps its own');

$_GET = ['page' => StockPage::PAGE, 'tab' => 'lagerstruktur', 'ort' => $kiste];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'taxmod-record-tree') && str_contains($html, 'data-taxmod-drag="1"') && str_contains($html, 'tab=lagerstruktur&amp;ort=__ID__') && str_contains($html, '__Kiste (1)'), 'Lagerstruktur: the tree on the left, a click opens a place, the count follows the name');
$pruefe(str_contains($html, '<h2 style="margin-top:0">__Kiste') && str_contains($html, 'Lager › __Schrank › __Schublade 2') && str_contains($html, 'Umlagerungen') && str_contains($html, 'taxmod-stock-move-place-' . $kiste) && str_contains($html, 'taxmod-stock-new-place-' . $kiste), 'Lagerstruktur: the place on the right with where it lies, its moves and its actions');
$pruefe(str_contains($html, 'data-taxmod-drop-record="0"') && str_contains($html, 'taxmod-stock-drop-place') && str_contains($html, 'name="return_ort" value="' . $kiste . '"'), 'Lagerstruktur: the top level takes a drop, a drop asks first, the forms come back to the place');
$pruefe(! str_contains($html, 'name="parent[' . $kiste . ']" value="' . $kiste . '"'), 'Verschieben does not offer the box itself');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'lagerstruktur', 'ort' => $schrank];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'id="taxmod-stock-children"') && str_contains($html, 'value="' . $fach1 . '" form="taxmod-stock-children"') && str_contains($html, 'value="' . $fach2 . '" form="taxmod-stock-children"') && ! str_contains($html, 'value="' . $kiste . '" form="taxmod-stock-children"'), 'Lagerstruktur: the Unterorte right inside the place stand in a list with checkboxes');
$pruefe(str_contains($html, 'taxmod-stock-children-move') && str_contains($html, 'value="orteverschieben"') && (! has_filter('taxmod_label_formats') || str_contains($html, 'value="etiketten-pdf"')), 'Lagerstruktur: «Verschieben nach …» and «Etiketten drucken …» for the ticked Unterorte');
// D-1380, his report 2026-11-15 «62x20 wird nicht angeboten … anstatt im text leer 2 oder 1 zu zeigen das sagt nix aus».
$pruefe(! has_filter('taxmod_label_formats') || (preg_match('/<option value="vorlage-\d+"[^>]*>Material 40×30 · 40 × 30 mm<\/option>.*<option value="holder62"/s', $html) === 1 && ! str_contains($html, 'leer: 2 bei beweglichen, sonst 1')
    && (str_contains($html, 'leer: je Ort die Anzahl seiner Platzart') || str_contains($html, 'wie bei der Platzart hinterlegt'))), 'D-1380: the Unterorte\'s «Etiketten drucken …» offers the label templates before the shipped formats, and the count says what the kinds hold');
$parts = (new StockPage())->parts();
$pruefe(str_contains($parts['side'], 'taxmod-record-tree') && str_contains($parts['side'], 'data-taxmod-drop-record="0"') && str_contains($parts['side'], 'for="taxmod-stock-new-place-0"') && ! str_contains($parts['main'], 'data-taxmod-pick=') && str_contains($parts['main'], 'id="taxmod-stock-new-place-0"'), 'D-1092: the tree goes under the navigation, the right side is only the place; «Neuer Lagerort» opens its dialog from there');
$framed = LogisticsShell::wrap(StockPage::PAGE, $parts['main'], $parts['side']);
$pruefe(str_contains($framed, 'class="taxmod-shell has-side"') && strpos($framed, 'class="taxmod-shell-side"') > strpos($framed, 'class="taxmod-shell-nav"') && strpos($framed, 'class="taxmod-shell-side"') < strpos($framed, 'class="taxmod-shell-main"'), 'D-1092: in the frame the tree is a second box in the navigation column');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'lagerorte', 'ort' => $schrank];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'data-taxmod-stock-lot="' . $posten . ':' . $kiste . ':' . rtrim(rtrim(number_format($menge, 3, '.', ''), '0'), '.') . ':' . $bc . '"') && str_contains($html, '__Schublade 2 › __Kiste') && str_contains($html, 'BC547C'), 'Lagerorte: what lies at the place and below it, each row draggable onto a place');
// His question 2026-11-08: «müsste dann nicht eigentlich der Bestand angezeigt werden, anstatt das Material?»
$pruefe(str_contains($html, '<a href="' . esc_url(StockPage::lotUrl($bc, $posten)) . '">#' . $posten . '</a></td><td><a href="' . esc_url(StockPage::lotUrl($bc, $posten)) . '">BC547C'), 'Platzbestand: each row starts with its lot, and lot and material both lead to that lot in the material\'s Bestand');
$pruefe(! has_filter('taxmod_label_button') || str_contains($html, 'value="P-' . $posten . '"'), 'Platzbestand: each lot has its own «Etikett drucken» (his word 2026-11-08: «das Drucken pro Posten»)');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => $bc, 'view' => 'bestand'];
$bestandHtml = (new StockPage())->render();
$pruefe(str_contains($bestandHtml, 'id="taxmod-stock-lot-' . $posten . '"'), 'the material\'s Bestand marks each lot\'s line, so the link lands on it');
$pruefe(str_contains($bestandHtml, '>#' . $posten . '</td><td>BC547C'), 'the material\'s Bestand names the material in each lot\'s line (his wish 2026-11-08)');
// D-1230, his word «drucken knopf durch icon drucker … umbuchen zwei gegenläufige pfeile … ein icon für ausbuchen».
if (defined(Taxmod\WordPress\Admin\ListPage::class . '::ICON_TRANSFER')) {
    $pruefe(str_contains($bestandHtml, 'dashicons-leftright') && str_contains($bestandHtml, 'dashicons-migrate') && str_contains($bestandHtml, '<span class="screen-reader-text">Ausbuchen</span>'), 'D-1230: Umbuchen and Ausbuchen of a lot are drawn as their icons, the word kept for the screen reader');
}

// D-1146, his choice «Ja, so bauen»: the manufacturing module books a build's consumption from the box, and takes it back.
add_filter(TaxmodStock\WordPress\Plugin::BOOK_BUILD, [TaxmodStock\WordPress\Plugin::class, 'bookBuild'], 10, 2);
$auftrag = (int) ($b['pj'] ?? 0);
$vorher  = (new StockService())->ledger()->stockOf($bc);
$vorgang = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BUILD, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => 1], 'title' => 'Fertigung Testkarte', 'date' => '2026-11-04']);
$s       = new StockService();
$pruefe(is_int($vorgang) && $s->ledger()->stockOf($bc) === $vorher - 1.0 && (int) $s->table('transaction')->first($vorgang, $s->structure()->field('transaction_reference')) === $auftrag, 'a build takes one piece from the box, one Vorgang «Verbrauch» naming the order' . ($vorgang instanceof WP_Error ? ': ' . $vorgang->get_error_message() : ''));
$zurueck = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BUILD, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => -1], 'title' => 'Fertigung Testkarte zurück', 'date' => '2026-11-04']);
$s       = new StockService();
$pruefe(is_int($zurueck) && $s->ledger()->stockOf($bc) === $vorher && ($s->presentIn($schrank)[$bc] ?? 0.0) === $menge, 'giving it back puts it into the box again' . ($zurueck instanceof WP_Error ? ': ' . $zurueck->get_error_message() : ''));
$zuviel  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BUILD, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => $menge + 5], 'title' => 'zu viel', 'date' => '2026-11-04']);
$pruefe($zuviel instanceof WP_Error && $zuviel->get_error_code() === 'taxmod_stock_not_enough_for_build' && (new StockService())->ledger()->stockOf($bc) === $vorher, 'more than lies in the box is refused, nothing booked');
$mehr    = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BUILD, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => -1], 'title' => 'nichts entnommen', 'date' => '2026-11-04']);
$pruefe($mehr instanceof WP_Error && $mehr->get_error_code() === 'taxmod_stock_not_taken_for_build', 'giving back more than the order took is refused');
// D-1146, his choice «Pickliste am Bildschirm + Druck, abgehakte Zeilen ins Gebinde umbuchen»: read where it lies free, move it in.
add_filter(TaxmodStock\WordPress\Plugin::PLACES_OF, [TaxmodStock\WordPress\Plugin::class, 'placesOf'], 10, 2);
add_filter(TaxmodStock\WordPress\Plugin::BOOK_PICK, [TaxmodStock\WordPress\Plugin::class, 'bookPick'], 10, 2);
$frei   = apply_filters(TaxmodStock\WordPress\Plugin::PLACES_OF, null, ['things' => [$r['t1'], $bc], 'except' => $schrank]);
$von    = is_array($frei) ? (int) array_key_first($frei[$r['t1']] ?? []) : 0;
$pruefe(is_array($frei) && $von > 0 && ! isset($frei[$bc][$kiste]) && ! in_array($von, (new StockService())->locationTree()->withAllBelow($schrank), true), 'the pick list reads where a part lies free, never inside the box');
$pick   = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_PICK, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [['thing' => $r['t1'], 'from' => $von, 'qty' => 1]], 'title' => 'Pickliste Testkarte', 'date' => '2026-11-04']);
$s      = new StockService();
$pruefe(is_int($pick) && ($s->presentIn($schrank)[$r['t1']] ?? 0.0) === 1.0 && (int) $s->table('transaction')->first($pick, $s->structure()->field('transaction_kind')) === $s->structure()->value('kind_transfer') && (int) $s->table('transaction')->first($pick, $s->structure()->field('transaction_reference')) === $auftrag, 'a ticked line moves into the box as an Umbuchung naming the order' . ($pick instanceof WP_Error ? ': ' . $pick->get_error_message() : ''));
$pick   = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_PICK, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [['thing' => $r['t1'], 'from' => $von, 'qty' => 999]], 'title' => 'zu viel', 'date' => '2026-11-04']);
$pruefe($pick instanceof WP_Error && $pick->get_error_code() === 'taxmod_stock_not_enough_to_pick', 'more than lies free at the place is refused, nothing moved');
// «Aus Bestand zuweisen» (his wish 2026-11-13): the materials with free stock below a group, outside the box, with their stock.
add_filter(TaxmodStock\WordPress\Plugin::THINGS_IN_STOCK, [TaxmodStock\WordPress\Plugin::class, 'thingsInStock'], 10, 2);
$gruppe  = (int) ((new StockService())->nodesOf([$r['t1']])[$r['t1']] ?? 0);
$imLager = apply_filters(TaxmodStock\WordPress\Plugin::THINGS_IN_STOCK, null, ['group' => $gruppe, 'except' => $schrank]);
$freiT1  = array_sum(is_array($frei) ? ($frei[$r['t1']] ?? []) : []) - 1;
$pruefe(is_array($imLager) && isset($imLager[$r['t1']]) && abs($imLager[$r['t1']] - $freiT1) < 0.001 && $imLager === apply_filters(TaxmodStock\WordPress\Plugin::THINGS_IN_STOCK, null, ['node' => $gruppe, 'except' => $schrank]),
    'things_in_stock: a group names its materials with free stock outside the box, «node» the same' . (is_array($imLager) ? '' : ': ' . $imLager->get_error_message()));
$pruefe(str_contains($html, 'taxmod-stock-drop-lot') && ! str_contains($html, 'taxmod-stock-move-place-'), 'Lagerorte: a lot dropped on a place asks for the Umbuchung; no structure actions here');

// D-1143: the finished board — a material created for it, brought into stock naming the order, cancelled again.
add_filter(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, [TaxmodStock\WordPress\Plugin::class, 'bookOutput'], 10, 2);
add_filter(TaxmodStock\WordPress\Plugin::CREATE_THING, [TaxmodStock\WordPress\Plugin::class, 'createThing'], 10, 2);
$gruppe  = (int) ((new TaxmodStock\WordPress\ModelGateway())->nodesOf([$bc])[$bc] ?? 0);
$platine = apply_filters(TaxmodStock\WordPress\Plugin::CREATE_THING, null, ['name' => 'Testkarte bestückt 0.7', 'group' => $gruppe]);
$pruefe(is_int($platine) && $platine > 0 && isset((new StockService())->tree()[$gruppe]), 'D-1143: a material for the finished board is created in a group of the things' . ($platine instanceof WP_Error ? ': ' . $platine->get_error_message() : ''));
$platine = is_int($platine) ? $platine : 0;
// D8 (D-1320), D-1331: a subgroup made in the Lager where the modeller can; ticked materials move into it and back, one act each.
if ((new StockService())->canCreateGroup()) {
    $andere = (new StockService())->createGroup($gruppe, '__Untergruppe');
    $pruefe($andere > 0 && isset((new StockService())->tree()[$andere]), 'D8: «+ Neue Untergruppe» stands in the tree below its group');
} else {
    $andere = (int) ((new StockService())->tree()[$gruppe]['parent'] ?? 0);
}
$hin    = (new StockService())->moveThingsToGroup([$platine], $andere);
$dort   = (int) ((new TaxmodStock\WordPress\ModelGateway())->nodesOf([$platine])[$platine] ?? 0);
(new StockService())->moveThingsToGroup([$platine], $gruppe);
$pruefe($andere > 0 && $dort === $andere && (int) ((new TaxmodStock\WordPress\ModelGateway())->nodesOf([$platine])[$platine] ?? 0) === $gruppe, 'D8: ticked materials move into another group and back, one act each');
$zugang  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$platine => 2], 'title' => 'Fertigung Testkarte fertig', 'date' => '2026-11-04']);
$s       = new StockService();
$pruefe(is_int($zugang) && ($s->presentIn($schrank)[$platine] ?? 0.0) === 2.0 && (int) $s->table('transaction')->first($zugang, $s->structure()->field('transaction_reference')) === $auftrag && (int) $s->table('transaction')->first($zugang, $s->structure()->field('transaction_kind')) === $s->structure()->value('kind_receipt'), 'D-1143: two finished boards come in at the place, one Vorgang «Zugang» naming the order' . ($zugang instanceof WP_Error ? ': ' . $zugang->get_error_message() : ''));
$neuLos  = array_values(array_filter($s->table('lot')->ids(), static fn (int $l): bool => (int) $s->table('lot')->first($l, $s->structure()->field('lot_thing')) === $platine));
$pruefe($neuLos !== [] && (! $s->structure()->hasField('lot_condition') || (int) $s->table('lot')->first($neuLos[0], $s->structure()->field('lot_condition')) === $s->structure()->value('condition_untested')), 'D-1143: the finished board comes in untested');
$storno  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$platine => -1], 'title' => 'Fertigung Testkarte Storno', 'date' => '2026-11-04']);
$pruefe(is_int($storno) && ((new StockService())->presentIn($schrank)[$platine] ?? 0.0) === 1.0, 'D-1143: a negative count takes one back from what this order brought in' . ($storno instanceof WP_Error ? ': ' . $storno->get_error_message() : ''));
$geprueft = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$platine => 1], 'title' => 'Fertigung geprüft', 'date' => '2026-11-05', 'tested' => true]);
$s        = new StockService();
$geprLos  = max(array_values(array_filter($s->table('lot')->ids(), static fn (int $l): bool => (int) $s->table('lot')->first($l, $s->structure()->field('lot_thing')) === $platine)) ?: [0]);
$pruefe(is_int($geprueft) && (! $s->structure()->hasField('lot_condition') || ! $s->structure()->hasValue('test_ok') || (int) $s->table('lot')->first($geprLos, $s->structure()->field('lot_condition')) === $s->structure()->value('test_ok')), 'D-1177: a board tested in the manufacturing comes in as «funktioniert»' . ($geprueft instanceof WP_Error ? ': ' . $geprueft->get_error_message() : ''));
$zuviel  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag + 1, 'place' => $schrank, 'lines' => [$platine => -1], 'title' => 'fremd', 'date' => '2026-11-04']);
$pruefe($zuviel instanceof WP_Error && $zuviel->get_error_code() === 'taxmod_stock_not_produced_for_order', 'D-1143: cancelling what another order brought in is refused');

// D-1168, his word «das ist dann ein verlust»: the goods of a resend leave as a consumption naming its Bezug, and come back with the Storno.
add_filter(TaxmodStock\WordPress\Plugin::LOSS_LOTS, [TaxmodStock\WordPress\Plugin::class, 'lossLots']);
add_filter(TaxmodStock\WordPress\Plugin::BOOK_LOSS, [TaxmodStock\WordPress\Plugin::class, 'bookLoss'], 10, 2);
add_filter(TaxmodStock\WordPress\Plugin::CANCEL_LOSS, [TaxmodStock\WordPress\Plugin::class, 'cancelLoss'], 10, 2);
$s       = new StockService();
$lose    = apply_filters(TaxmodStock\WordPress\Plugin::LOSS_LOTS, []);
$losLot  = (int) (array_values(array_filter(array_keys($lose), static fn (int $lot): bool => $s->ledger()->thingOf($lot) === $r['t1']))[0] ?? 0);
$pruefe($losLot > 0 && str_contains((string) $lose[$losLot], ' · '), 'a resend offers the lots with a rest, named by material, date and rest');
$vorher  = $s->ledger()->stockOf($r['t1']);
$verlust = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_LOSS, null, ['sale' => $auftrag, 'lot' => $losLot, 'quantity' => '1', 'reason' => 'Verlust: Ersatzversand', 'date' => '2026-11-04', 'title' => 'Verlust Ersatzversand']);
$s       = new StockService();
$pruefe(is_int($verlust) && $s->ledger()->stockOf($r['t1']) === $vorher - 1.0
    && (int) $s->table('transaction')->first($verlust, $s->structure()->field('transaction_kind')) === $s->structure()->value('kind_consumption')
    && (int) $s->table('transaction')->first($verlust, $s->structure()->field('transaction_reference')) === $auftrag
    && (! $s->structure()->hasField('description') || (string) $s->table('transaction')->first($verlust, $s->structure()->field('description')) === 'Verlust: Ersatzversand'), 'the goods sent again leave as one Vorgang «Verbrauch» with the Bezug and the Grund «Verlust»' . ($verlust instanceof WP_Error ? ': ' . $verlust->get_error_message() : ''));
$storno  = is_int($verlust) ? apply_filters(TaxmodStock\WordPress\Plugin::CANCEL_LOSS, null, ['deal' => $verlust, 'who' => 'check']) : null;
$pruefe(is_int($storno) && (new StockService())->ledger()->stockOf($r['t1']) === $vorher, 'taking the resend back cancels the loss: the piece is in stock again' . ($storno instanceof WP_Error ? ': ' . $storno->get_error_message() : ''));
$davor   = (new StockService())->ledger()->stockOf($r['t1']);
$zuviel  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_LOSS, null, ['sale' => $auftrag, 'lot' => $losLot, 'quantity' => '999', 'reason' => 'x', 'date' => '2026-11-04']);
$pruefe($zuviel instanceof WP_Error && (new StockService())->ledger()->stockOf($r['t1']) === $davor, 'a loss larger than the lot is refused, nothing booked');
$keinLot = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_LOSS, null, ['sale' => $auftrag, 'lot' => 0, 'quantity' => '1', 'reason' => 'x', 'date' => '2026-11-04']);
$pruefe($keinLot instanceof WP_Error, 'a loss without a lot is refused');


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

// D-1317: on an order's event, a material without a picture takes its purchase's picture, as a reference, once.
$picSetup = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'pk', 'node' => (string) $r['pur'], 'values' => [(string) $r['k_mod'] => (string) $bc, (string) $r['bilder'] => ['values' => ['media:424242']]]],
    ['op' => 'create_record', 'ref' => 'po', 'node' => (string) $r['ord'], 'values' => [(string) $r['o_stat'] => (string) $r['s_best'], (string) $r['o_pos'] => ['values' => ['@pk']]]],
]]);
$picOrder  = is_array($picSetup) ? (int) ($picSetup['refs']['po'] ?? 0) : 0;
$picBefore = (new StockService())->picturesOf([$bc])[$bc]['pictures'];
do_action('taxmod_stock_event', $picOrder, 'shipped', '2026-11-13', 'mail', 'x2');
// The order's lines as parts (composition) take no refs in one act; the aggregation run proves it.
if ($kind !== 'composition') {
    $pruefe($picOrder > 0 && ! in_array('media:424242', $picBefore, true) && in_array('media:424242', (new StockService())->picturesOf([$bc])[$bc]['pictures'], true), 'D-1317: on the order\'s event the material without a picture takes its purchase\'s, as media:<id>');
}

// D-1328 (D9): the filters for the manufacturing module answer; without the connector fields no proposals, a cut without lot is refused.
$cutAnswer  = apply_filters('taxmod_stock_cut_sources', null, ['things' => [$bc => 2], 'except' => 0]);
$cutRefused = apply_filters('taxmod_stock_book_cut', null, ['reference' => 1, 'into' => $r['lager'], 'cuts' => [['from' => 999999999, 'thing' => $bc, 'pieces' => 1]], 'date' => '2026-11-13']);
$pruefe($cutAnswer === [] && $cutRefused instanceof WP_Error, 'D9: cut_sources answers none without connector fields, book_cut refuses a cut without lot (D-1328)');

// Stefan 16.11. «findet die nicht»: the picker finds a material in stock though the term names details it lacks.
$lmWord   = (new StockService())->recordLabels([$r['lm']])[$r['lm']] ?? '';
$pickHits = $lmWord === '' ? [] : (new StockService())->stockHits($lmWord . ' zzdetail zzmehr', 12);
$pruefe($lmWord !== '' && in_array($r['lm'], array_column($pickHits, 'id'), true), 'D-1387: the picker drops trailing words until a material in stock is found, stocked hits first');

// D-1398: pieces in the picker only for a strip counted in pins; any other material opens no strips.
$pruefe((new StockService())->stripsToOpen($r['lm'], [$r['lager']], 10, 6) === [], 'D-1398: a material not counted in pins opens no strips in the picker');

// D-1396: a lot whose material names no revision shows no test steps, and the read does not fail.
$pruefe((new StockService())->testStepsOf([$lot, 999999999]) === [], 'D-1396: test steps only where the material names a revision with steps');

// Performance (Fertigung 16.11.): the read filters share one service per request; a write filter starts it afresh.
$readingOf = static fn () => (new ReflectionProperty(\TaxmodStock\WordPress\Plugin::class, 'reading'))->getValue();
apply_filters('taxmod_stock_project_boxes', null, []);
$firstRead = $readingOf();
apply_filters('taxmod_stock_loss_lots', null);
apply_filters('taxmod_stock_cut_sources', null, ['things' => [$bc => 2], 'except' => 0]);
$sharedRead = $firstRead !== null && $readingOf() === $firstRead;
apply_filters('taxmod_stock_book_cut', null, ['reference' => 1, 'into' => $r['lager'], 'cuts' => [['from' => 999999999, 'thing' => $bc, 'pieces' => 1]], 'date' => '2026-11-13']);
$pruefe($sharedRead && $readingOf() === null, 'read filters share one StockService per request; a write filter forgets it');

// D-1038 (E1): the inbox's event sets the order status forward, once; a closed order keeps its status.
$evOrder = (int) (wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'create_record', 'ref' => 'evo', 'node' => (string) $r['ord'], 'values' => [(string) $r['o_stat'] => (string) $r['s_best']]]]])['refs']['evo'] ?? 0);
do_action('taxmod_stock_event', $evOrder, 'shipped', '2026-11-12', 'mail', 'x1');
$afterShipped = (new StockService())->table('order')->first($evOrder, $r['o_stat']);
do_action('taxmod_stock_event', $evOrder, 'shipped', '2026-11-12', 'mail', 'x1');
do_action('taxmod_stock_event', $evOrder, 'delivered', '2026-11-13', 'mail', 'x1');
$afterDelivered = (new StockService())->table('order')->first($evOrder, $r['o_stat']);
do_action('taxmod_stock_event', $evOrder, 'shipped', '2026-11-13', 'mail', 'x1');
$pruefe($afterShipped === (string) $r['s_vers'] && $afterDelivered === (string) $r['s_zug'] && (new StockService())->table('order')->first($evOrder, $r['o_stat']) === (string) $r['s_zug'], 'E1: «versendet» then «zugestellt» set the order status, never back (D-1038)');
(new StockService())->removePosition($bedarf, $nachT1);
$s = new StockService();
$pruefe(count($s->table('order')->all($bedarf, $r['o_pos'])) === 1 && ! $s->table('purchase')->has($nachT1), 'a position removed leaves its demand and is gone');

// D-1130: «Nochmal bestellen» — the materials and quantities of the demand as a new one; the original stays.
$vorher = $offen(new StockService());
(new StockService())->reorderOrder($bedarf);
$s      = new StockService();
$neu    = array_values(array_diff($offen($s), $vorher))[0] ?? 0;
$pruefe($neu > 0 && $s->table('order')->first($neu, $r['label']) === '__Bedarf (nochmal)' && array_map(static fn (string $k): string => (string) $s->table('purchase')->first((int) $k, $r['k_mod']), $s->table('order')->all($neu, $r['o_pos'])) === [(string) $r['t2']] && count($s->table('order')->all($bedarf, $r['o_pos'])) === 1, '«Nochmal bestellen» makes a new demand with the same materials; the original keeps its position');

// D-1083 with D-1097: a second position joins the subject's open reservation.
(new StockService())->reserve($r['t2'], '', $bc, '1', null, '2026-11-02', 'Reservierung');
(new StockService())->reserve($r['t2'], '', $bc, '2', null, '2026-11-02', 'Reservierung');
$s     = new StockService();
$offenR = $s->reservations()->forSubject($r['t2']);
$pruefe($offenR !== null && count($s->table('transaction')->all($offenR, $r['v_pos'])) === 2 && ($s->reservations()->reservedByThing()[$bc] ?? 0.0) === 3.0, 'one reservation per subject, holding both positions');

// D-1320, his word 2026-11-14 «von bedarf muss man doch auf bestellen und nicht auf nochmal bestellen»: a demand offers «Bestellen»,
// never «Nochmal bestellen»; the copy stays for what was ordered.
$_GET  = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'tab' => 'bedarf'];
$bedarfSeite = (new TaxmodStock\WordPress\Admin\PurchasePage())->render();
$_GET  = [];
$pruefe(str_contains($bedarfSeite, '>Bestellen</a>') && ! str_contains($bedarfSeite, 'Nochmal bestellen') && ! str_contains((string) file_get_contents(__DIR__ . '/../../src/WordPress/Admin/PurchasePage.php'), "__('Übernehmen'"),
    'D-1320: a demand offers «Bestellen», no «Nochmal bestellen»; choosing a merchant reads «Bestellen bei …»');

// D-1183 for Einkauf, his word 2026-11-14 «ausserdem ist hier wieder die aufklapp logik»: no row opens; each links to a page
// of its own, which leads back into its tab.
$einkaufSeiten = [];
$einkaufOk     = true;

foreach (['bedarf', 'bestellen', 'bestellt', 'verworfen'] as $reiter) {
    $_GET  = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'tab' => $reiter];
    $liste = (new TaxmodStock\WordPress\Admin\PurchasePage())->render();
    $einkaufOk = $einkaufOk && ! str_contains($liste, 'data-source="stock-purchase"');
    preg_match_all('/<a href="[^"]*(?:bedarf=|tab=' . $reiter . '&#038;order=)(\d+)"><strong>/', $liste, $treffer);
    $einkaufOk = $einkaufOk && count($treffer[1]) === substr_count($liste, 'name="selected[]"');

    foreach ($reiter === 'bedarf' ? [] : array_slice($treffer[1], 0, 1) as $id) {
        $_GET = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'tab' => $reiter, 'order' => $id];
        $einkaufSeiten[$reiter] = str_contains((new TaxmodStock\WordPress\Admin\PurchasePage())->render(), 'Zurück zu «');
    }
}

$_GET = [];
$pruefe($einkaufOk && ! in_array(false, $einkaufSeiten, true) && $einkaufSeiten !== [], 'D-1183: no row of «Einkauf» opens; each links to its own page, which leads back into its tab (' . implode(', ', array_keys($einkaufSeiten)) . ')');

// D-1320 ergänzt, his word 2026-11-14 «noch eine Position hinzufügen … einfach ein Material wählen»: a demand's page offers
// «Position hinzufügen» with the stock's material chooser, inside the form that sends it.
$einBedarf = 0;
$_GET = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'tab' => 'bedarf'];

if (preg_match('/bedarf=(\d+)"><strong>/', (new TaxmodStock\WordPress\Admin\PurchasePage())->render(), $m) === 1) {
    $einBedarf = (int) $m[1];
}

$_GET = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'bedarf' => (string) $einBedarf];
$bedarfsSeite = $einBedarf > 0 ? (new TaxmodStock\WordPress\Admin\PurchasePage())->render() : '';
$_GET = [];
$pruefe(preg_match('/<form[^>]*>(?:(?!<\/form>).)*name="op" value="add"(?:(?!<\/form>).)*Position hinzufügen(?:(?!<\/form>).)*name="thing"(?:(?!<\/form>).)*name="quantity"/s', $bedarfsSeite) === 1,
    'D-1320: a demand\'s page offers «Position hinzufügen»: material chooser and quantity inside the form that sends them');

// D-1320 ergänzt, his words 2026-11-15 «den bedarf auf beendet setzen und nach nem monat geht er nach archiviert, in beiden
// könnte man ihn kopieren als neuen bedarf»: a demand passed on to suppliers stands under «Beendet» or «Archiviert», never
// under «Bedarf», with «Als neuen Bedarf kopieren»; reckoned, never stored.
$einkaufDienst = new StockService();
$klammern      = $einkaufDienst->purchasing()->brackets($einkaufDienst->table('purchase'));
$seiten        = [];

foreach (['bedarf', 'beendet', 'archiviert'] as $reiter) {
    $_GET            = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'tab' => $reiter];
    $seiten[$reiter] = (new TaxmodStock\WordPress\Admin\PurchasePage())->render();
}

$_GET          = [];
$uebergangOk   = str_contains($seiten['beendet'], '>Beendet') && str_contains($seiten['beendet'], '>Archiviert');
$uebergegangen = 0;

foreach ($einkaufDienst->table('order')->ids() as $id) {
    if (! $einkaufDienst->purchasing()->isDemand($id, $einkaufDienst->table('order'))) {
        continue;
    }

    $weg           = $einkaufDienst->purchasing()->hasPassedOn($id, $einkaufDienst->table('order'), $klammern);
    $uebergegangen += $weg ? 1 : 0;
    $zeile         = 'bedarf=' . $id . '"><strong>';
    $uebergangOk   = $uebergangOk && str_contains($seiten['bedarf'], $zeile) === ! $weg
        && (! $weg || ((str_contains($seiten['beendet'], $zeile) || str_contains($seiten['archiviert'], $zeile)) && str_contains($seiten['beendet'] . $seiten['archiviert'], 'Als neuen Bedarf kopieren')));
}

$pruefe($uebergangOk, 'D-1320: a demand passed on stands under «Beendet» or «Archiviert» with «Als neuen Bedarf kopieren», never under «Bedarf» (' . $uebergegangen . ' passed on)');

// D-1377, his word 2026-11-15 «nach ausbuchen des verbrauch sollte der zurück springen woher er kam»: every booking form carries
// where it was opened, an outside address never; Einbuchen without one keeps its old target.
$formStart = new ReflectionMethod(StockPage::class, 'formStart');
$zurueck   = admin_url('admin.php?page=' . StockPage::PAGE . '&tab=lagerorte&ort=1');
$formenOk  = true;

foreach (['verbrauch', 'umbuchen', 'postenumbuchen', 'sammelumbuchen', 'inventur', 'wareneingang', 'einlagern'] as $buchung) {
    $_GET     = ['return' => rawurlencode($zurueck)];
    $formenOk = $formenOk && str_contains($formStart->invoke(new StockPage(), $buchung), 'name="return" value="' . esc_attr($zurueck) . '"');
    $_GET     = ['return' => rawurlencode('https://example.org/')];
    $formenOk = $formenOk && ! str_contains($formStart->invoke(new StockPage(), $buchung), 'name="return"');
}

$_GET = [];
$pruefe($formenOk && ! str_contains($formStart->invoke(new StockPage(), 'wareneingang'), 'name="return"') && ! str_contains($formStart->invoke(new StockPage(), 'ort'), 'name="return"'),
    'D-1377: every booking form carries the page it was opened from, never an outside address; Einbuchen without one and non-bookings carry none');

// His wish 2026-11-11 «links aus logistik auf tax modeller … ablösung»: no page of the module links into the modeller's screens.
$hinaus = array_filter(array_map(static fn (string $f): string => str_contains((string) file_get_contents($f), 'taxmod_open_record') ? basename($f) : '', glob(__DIR__ . '/../../src/WordPress/Admin/*.php') ?: []));
$einKauf = (int) ((new StockService())->table('purchase')->ids()[0] ?? 0);
$_GET   = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'kauf' => (string) $einKauf];
$kauf   = (new TaxmodStock\WordPress\Admin\PurchasePage())->render();
$_GET   = [];
$pruefe($hinaus === [] && str_contains($kauf, 'Kauf – ') && str_contains($kauf, 'taxmod-back'), 'no link leads into the modeller; a purchase opens in «Einkauf» with its editor and the way back' . ($hinaus === [] ? '' : ': ' . implode(', ', $hinaus)));

// D-1301: the manufacturing module reserves, reads and releases for its order in one call each.
$t1Ledger  = (new StockService())->ledger();
$t1Lot     = $t1Ledger->proposedLot($bc);
$t1Ort     = (int) array_key_first(array_filter($t1Ledger->placesOf($t1Lot), static fn (float $q, int $p): bool => $q >= 1.0 && $p > 0, ARRAY_FILTER_USE_BOTH));
// «Aus Bestand zuweisen» (his choice 2026-11-13): a row may name the bill-of-materials position it stands in for.
$posFeld   = $apply([$field('l_pos', (string) $r['vp'], '__Stücklistenposition', (string) ((new StockService())->nodesOf([$r['t1']])[$r['t1']] ?? 0), 'aggregation', '0..1')])['refs']['l_pos'] ?? 0;
$opt       = get_option('taxmod_stock_structure');
if (is_array($opt) && $posFeld > 0) {
    $opt['fields'] += ['line_position' => (int) $posFeld];
    update_option('taxmod_stock_structure', $opt);
}
$resAnswer = apply_filters('taxmod_stock_reserve', null, ['subject' => $auftrag, 'rows' => [['thing' => $bc, 'quantity' => 1, 'place' => $t1Ort], ['thing' => $r['t1'], 'quantity' => 1, 'position' => $r['t1']]], 'date' => '2026-11-13', 'title' => 'Reservierung Testkarte']);
$resOf     = apply_filters('taxmod_stock_reservations_of', null, ['subjects' => [$auftrag]]);
$posOf     = is_array($resOf) ? array_column($resOf[$auftrag] ?? [], 'position', 'thing') : [];
$pruefe(($posOf[$r['t1']] ?? null) === $r['t1'] && ($posOf[$bc] ?? null) === 0, 'a reservation row keeps the bill-of-materials position it stands in for; reservations_of gives it back, 0 without');
$resLines  = is_array($resOf) ? array_column($resOf[$auftrag] ?? [], 'line') : [];
$resPart   = apply_filters('taxmod_stock_release', null, ['subject' => $auftrag, 'lines' => array_slice($resLines, 0, 1)]);
$resAfter  = apply_filters('taxmod_stock_reservations_of', null, ['subjects' => [$auftrag]]);
$resAll    = apply_filters('taxmod_stock_release', null, ['subject' => $auftrag]);
$resEnd    = apply_filters('taxmod_stock_reservations_of', null, ['subjects' => [$auftrag]]);
$t1Pos     = array_values(array_filter(is_array($resOf) ? ($resOf[$auftrag] ?? []) : [], static fn (array $p): bool => $p['thing'] === $bc))[0] ?? [];
$pruefe(($t1Pos['lot'] ?? null) !== null && isset($t1Pos['places'][$t1Ort]), 'D-1301: a row naming a place reserves the lot lying there, which stays where it is');
$pruefe(is_array($resAnswer) && count($resLines) === 2 && is_array($resPart) && count($resAfter[$auftrag] ?? []) === 1 && is_array($resAll) && ($resEnd[$auftrag] ?? null) === [], 'D-1301: reserve two positions in one call, release one, then the rest; reservations_of reads each step' . ($resAnswer instanceof WP_Error ? ': ' . $resAnswer->get_error_message() : ''));

// D-1387 (asked by the manufacturing 2026-11-15): a row reserved at a place keeps its position, and picking it into the
// order's box takes that very lot and redeems the reservation.
$rsS     = new StockService();
$rsOrt   = (int) array_key_first(array_filter($rsS->ledger()->placesOf($t1Lot), static fn (float $q, int $p): bool => $q >= 1.0 && $p > 0, ARRAY_FILTER_USE_BOTH));
$rsBox   = (int) (array_values(array_filter(array_keys($rsS->places()), static fn (int $p): bool => $p !== $rsOrt && $rsS->reservedPlaces()->reservingPlaceOf($p) === null))[0] ?? 0);
$rsRes   = apply_filters('taxmod_stock_reserve', null, ['subject' => $auftrag, 'rows' => [['thing' => $bc, 'quantity' => 1, 'place' => $rsOrt, 'position' => $r['t1']]], 'date' => '2026-11-15', 'title' => 'Reservierung Pickprobe']);
$rsVor   = apply_filters('taxmod_stock_reservations_of', null, ['subjects' => [$auftrag]]);
$rsLot   = (int) (($rsVor[$auftrag][0]['lot'] ?? 0));
$rsPick  = apply_filters('taxmod_stock_book_pick', null, ['reference' => $auftrag, 'place' => $rsBox, 'date' => '2026-11-15', 'lines' => [['thing' => $bc, 'from' => $rsOrt, 'qty' => 1]]]);
$rsNach  = apply_filters('taxmod_stock_reservations_of', null, ['subjects' => [$auftrag]]);
$pruefe(is_array($rsRes) && ($rsVor[$auftrag][0]['position'] ?? 0) === $r['t1'] && $rsLot > 0 && is_int($rsPick) && ($rsNach[$auftrag] ?? null) === []
    && (new StockService())->ledger()->restAt($rsLot, $rsBox) >= 1.0,
    'D-1387: a row reserved at a place keeps its position; picking it into the order\'s box moves that lot and redeems the reservation' . ($rsPick instanceof WP_Error ? ': ' . $rsPick->get_error_message() : ''));

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
update_option(StockService::OPTION, $struktur);
// D-1181, his word «verschmilzt es solange die daten gleich sind»: two lots of the same data at one place stand as one line.
(new StockService())->bookInventory([new TaxmodStock\Core\InventoryRow($r['lm'], null, 'Zählung', '2', null, ''), new TaxmodStock\Core\InventoryRow($r['lm'], null, 'Zählung', '2', null, '')], '2026-11-05', 'Inventur');
$html = (string) (new StockPage())->listDetails(null, StockPage::DETAILS_MATERIAL, ['thing' => (string) $r['lm']]);
$pruefe(preg_match_all('/<li>2026-11-05 · Rest 4 · Inventur/', $html) === 1 && str_contains($html, 'name="lots" value="'), 'D-1181: two lots of the same data at one place are one line with one «Umbuchen» for both');
preg_match('/name="lots" value="([0-9,]+)"><input type="hidden" name="from" value="(\d+)"/', $html, $paar);
(new StockService())->moveLots(array_map('intval', explode(',', $paar[1] ?? '')), (int) ($paar[2] ?? 0), $r['platz'], '3', '2026-11-05', 'Umbuchung');
$ledger = (new StockService())->ledger();
$pruefe(array_sum(array_map(static fn (string $l): float => $ledger->restAt((int) $l, $r['platz']), explode(',', $paar[1] ?? '0'))) === 3.0, 'D-1181: «Umbuchen» of that line moves from both lots, oldest first');

// D-1213: an Inventur taken back — one position, the lot stands at zero, the pair is hidden; a lot booked since is refused.
$zaehlung = (new StockService())->bookInventory([new TaxmodStock\Core\InventoryRow($r['lm'], null, 'Dublette', '7', null, ''), new TaxmodStock\Core\InventoryRow($r['lm'], null, 'Dublette', '1', null, '')], '2026-11-07', 'Inventur Dublette');
$s        = new StockService();
$inventur = max(array_filter($s->table('transaction')->ids(), static fn (int $d): bool => (string) $s->table('transaction')->first($d, $s->structure()->field('label')) === 'Inventur Dublette'));
[$zeile7, $zeile1] = array_map('intval', $s->table('transaction')->all($inventur, $s->structure()->field('transaction_lines')));
$vorher   = $s->ledger()->stockOf($r['lm']);
$s->cancelCount($inventur, $zeile7, '2026-11-07 10:00:00', 'Stefan');
$s        = new StockService();
$posten7  = (int) $s->table('line')->first($zeile7, $s->structure()->field('line_lot'));
$pruefe($s->ledger()->stockOf($r['lm']) === $vorher - 7.0 && $s->ledger()->restOf($posten7) === 0.0, 'D-1213: the Storno of one count position takes its 7 out, the lot stands at zero');
$pruefe(isset((new TaxmodStock\Core\Storno($s->structure()))->pairedLines($s->table('line'))[$zeile7]), 'D-1213: the count line and its Storno are a hidden pair');
$posten1  = (int) $s->table('line')->first($zeile1, $s->structure()->field('line_lot'));
$s->bookConsumption($r['lm'], $posten1, '1', Purpose::none(), '2026-11-07', 'Verbrauch Dublette');

try {
    (new StockService())->cancelCount($inventur, null, '2026-11-07 10:01:00', 'Stefan');
    $pruefe(false, 'D-1213: a lot consumed since the count keeps it');
} catch (StockError $e) {
    $pruefe($e->reason === 'lot_booked_since_count' && $e->detail === (string) $posten1, 'D-1213: a lot consumed since the count keeps it, the lot is named');
}

// D-1158: without sizes on the kinds of place nothing is said — the warning never stands in the way of a move.
$ohneMass = $ort('Passt-Regal', 0);
// D-1093, his word 2026-11-09 «wenn wir den löschen muss der gesamte bestand in den vater wechseln»: deleting a place moves what lies there up.
$alt     = $ort('__Alt', $r['lager']);
$altKind = $ort('__Alt-Kind', $alt);
$s       = new StockService();
$wo      = array_key_first(array_filter($s->ledger()->placesOf($posten), static fn (float $q): bool => $q > 0.0));
$s->moveLotsWhole([[$posten, (int) $wo]], $alt, '2026-11-09', 'vor dem Löschen');
$rest    = (new StockService())->ledger()->restAt($posten, $alt);
$_GET    = ['page' => StockPage::PAGE, 'tab' => 'lagerstruktur', 'ort' => (string) $alt];
$html    = (new StockPage())->render();
$pruefe(str_contains($html, 'value="ortloeschen-' . $alt . '"') && str_contains($html, '1 Posten wird nach') && str_contains($html, '1 Unterort hängt danach an'), 'D-1093: the bin of a place says first what goes where');
$zurueck = (new StockService())->deletePlace($alt, '2026-11-09', 'Lagerort gelöscht');
$s       = new StockService();
$pruefe($zurueck === $r['lager'] && ! $s->table('location')->has($alt) && $s->locationTree()->parentOf($altKind) === $r['lager'] && $rest > 0.0 && abs(($s->ledger()->placesOf($posten)[$alt] ?? 0.0)) < 1e-9,
    'D-1093: deleting a place books what lay there into its parent, hangs its places there and removes it in one act');
$pruefe(str_contains($s->locationTree()->pathOf($alt), 'gelöschter Ort #' . $alt), 'D-1093: a deleted place is named «gelöschter Ort #id» where old bookings still name it');
// His word 2026-11-09 «unterort mit löschen»: the places inside go too, what lay in any of them goes to the parent.
$baum  = $ort('__Baum', $r['lager']);
$ast   = $ort('__Ast', $baum);
$blatt = $ort('__Blatt', $ast);
(new StockService())->moveLotsWhole([[$posten, $r['lager']]], $blatt, '2026-11-09', 'in das Blatt');
$_GET  = ['page' => StockPage::PAGE, 'tab' => 'lagerstruktur', 'ort' => (string) $baum];
$html  = (new StockPage())->render();
$pruefe(str_contains($html, 'name="below" value="1"') && str_contains($html, '2 Unterorte werden mitgelöscht'), 'D-1093: with places inside, the dialog offers both ways and says what each does');
$pruefe(preg_match('/taxmod-stock-copy-place-' . $baum . '.{0,600}?dashicons-' . preg_quote(ListPage::ICON_COPY, '/') . '/s', $html) === 1, 'D-1273: «Kopieren» of a place is the copy icon, the word its tooltip');
(new StockService())->deletePlace($baum, '2026-11-09', 'Lagerort gelöscht', true);
$s     = new StockService();
$pruefe(! $s->table('location')->has($baum) && ! $s->table('location')->has($ast) && ! $s->table('location')->has($blatt) && abs(($s->ledger()->placesOf($posten)[$r['lager']] ?? 0.0) - $rest) < 1e-9 && ! isset($s->ledger()->placesOf($posten)[$blatt]),
    'D-1093: «Unterorte mitlöschen» removes the whole branch, what lay deep inside lies in the parent');
$oben    = $ort('__Oben', 0);
(new StockService())->moveLotsWhole([[$posten, $r['lager']]], $oben, '2026-11-09', 'nach oben');
try {
    (new StockService())->deletePlace($oben, '2026-11-09', 'x');
    $pruefe(false, 'D-1093: a top place with stock is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'place_in_use' && (new StockService())->table('location')->has($oben), 'D-1093: a top place with stock is refused, nothing done');
}
(new StockService())->moveLotsWhole([[$posten, $oben]], (int) $wo, '2026-11-09', 'zurück');
$pruefe((new StockService())->misfits([$ort('Passt-Box', $ohneMass, true)], $ohneMass) === [] && (new StockService())->fitsNew(0, $ohneMass) === null, 'D-1158: places without a kind or sizes give no fit warning');

// His report 2026-11-09 «speichern knopf lager ansicht materil imme noch text nicht icon»: every save is the modeller's diskette.
$quelle = implode('', array_map('file_get_contents', glob(dirname(__DIR__, 2) . '/src/WordPress/Admin/*.php')));
$pruefe(preg_match("/get_submit_button\\([^)]*speichern/i", $quelle) === 0 && str_contains(StockPage::saveButton(), 'taxmod-save'), 'D-1275: every «Speichern» of the module is the save icon, the word its tooltip');

// His report 2026-11-09 «bestand ohne material ?»: a material without «Bezeichnung» is named by the start of its «Beschreibung».
$ohneName = (new StockService())->createThing('__ohne Namen', $r['teile']);
$st       = (new StockService())->structure();
$beschr   = $st->hasField('description') ? $st->field('description') : (int) (wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'add_field', 'ref' => 'b', 'node' => (string) $r['teile'], 'name' => '__Beschreibung', 'target' => 1175, 'kind' => 'composition', 'multiplicity' => '0..1']]])['refs']['b'] ?? 0);
wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'set_values', 'record' => (string) $ohneName, 'values' => [(string) $st->field('label') => '', (string) $beschr => str_repeat('Laut AliExpress-Angebot: 100 Stück 0805 ', 3)]]]]);
$opt      = get_option('taxmod_stock_structure');
if (is_array($opt) && ! $st->hasField('description')) {
    $opt['fields']['description'] = $beschr;
    update_option('taxmod_stock_structure', $opt);
}
$genannt  = (new StockService())->recordLabels([$ohneName])[$ohneName] ?? '';
$pruefe(str_starts_with($genannt, 'Laut AliExpress-Angebot: 100 Stück') && str_ends_with($genannt, '…') && mb_strlen($genannt) <= 60, 'D-1279: a material without «Bezeichnung» is named by the start of its «Beschreibung»');

// His word 2026-11-09 «smd is eine eigenschaft»: the default label follows the Bauform, «SMD» for «0603», before the group's.
$bf    = $apply([
    ['op' => 'create_node', 'ref' => 'root', 'name' => '__Bauformen', 'parent' => 402],
    ['op' => 'create_node', 'ref' => 'smd', 'name' => '__SMD', 'parent' => '@root'],
    ['op' => 'create_node', 'ref' => 'f0603', 'name' => '__0603', 'parent' => '@smd'],
])['refs'] ?? [];
$bfFeld = (int) ($apply([['op' => 'add_field', 'ref' => 'f', 'node' => (string) $r['teile'], 'name' => '__Bauform', 'target' => (int) $bf['root'], 'kind' => 'aggregation', 'multiplicity' => '0..1']])['refs']['f'] ?? 0);
$apply([['op' => 'set_values', 'record' => (string) $r['t1'], 'values' => [(string) $bfFeld => (string) $bf['f0603']]]]);
$opt = get_option('taxmod_stock_structure');
if (is_array($opt)) {
    $opt['fields']['thing_form'] = $bfFeld;
    update_option('taxmod_stock_structure', $opt);
}
(new StockService())->setGroupProperty($r['teile'], StockService::LOT_LABEL_PROPERTY, $r['etk40']);
$pruefe((new StockService())->formRoot() === (int) $bf['root'] && (new StockService())->labelSourceOf($r['t1'], StockService::LOT_LABEL_PROPERTY) === [$r['etk40'], 'group', $r['teile']], 'D-1284: without a label at the Bauform the group\'s stands, and says so');
(new StockService())->setGroupProperty((int) $bf['smd'], StockService::LOT_LABEL_PROPERTY, $r['etk40'] + 0);
$quelle = (new StockService())->labelSourceOf($r['t1'], StockService::LOT_LABEL_PROPERTY);
$pruefe($quelle === [$r['etk40'], 'form', (int) $bf['f0603']], 'D-1284: a label set at «SMD» is read for a material of «0603», before the group\'s');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'etikettenvorlagen'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'value="' . StockPage::FORM_LABELS . '"') && str_contains($html, '__0603') && preg_match('/name="' . StockService::LOT_LABEL_PROPERTY . '\[' . $bf['smd'] . '\]".{0,400}?value="' . $r['etk40'] . '" selected/s', $html) === 1,
    'D-1284: «Etiketten je Bauform» lists the Bauformen with their own labels to choose');
// D-1296: the label dialog left the modeller; that it names the preselection's origin is now the labels module's (PR-9, moved).
(new StockService())->setGroupProperty((int) $bf['smd'], StockService::LOT_LABEL_PROPERTY, null);
(new StockService())->setGroupProperty($r['teile'], StockService::LOT_LABEL_PROPERTY, null);

// His report 2026-11-09 «findet kostanten aber nicht jst-xh stecker»: the search in the tree dialog finds materials too.
$pruefe(str_contains((string) file_get_contents(WP_PLUGIN_DIR . '/wp-taxonomy-modeler/assets/admin.js'), 'saetzeSuchen( dialog, this )')
    && str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/StockPage.php'), "'Material oder Gruppe suchen …'"), 'D-1285: the tree dialog\'s search field finds materials, not only groups');

// His wish 2026-11-09 «material nummern eingabe sollte auch möglich sein»: a number finds the material first, «0603» stays text.
$nr     = (new StockService())->searchThings('#' . $r['t1']);
$nrM    = (new StockService())->searchThings('M-' . $r['t1']);
$apply([['op' => 'set_values', 'record' => (string) $r['t1'], 'values' => [(string) (new StockService())->structure()->field('label') => '__Widerstand 0603']]]);
$text   = (new StockService())->searchThings('0603');
$pruefe(($nr[0]['id'] ?? 0) === $r['t1'] && str_ends_with((string) $nr[0]['node'], 'M-' . $r['t1']) && ($nrM[0]['id'] ?? 0) === $r['t1']
    && in_array($r['t1'], array_column($text, 'id'), true) && ! str_contains((string) ($text[0]['node'] ?? ''), 'M-'), 'D-1287: a number finds the material first, written as on the label; «0603» still finds by text');

// His report 2026-11-09 «preis pro stück wurde nicht umgerechnet auf 50 stück»: a pack corrected to pieces at the receipt keeps
// its total; «Preis je Stück» follows it, and so does the lot's value. «Einstandspreis» is computed live (D-1022), not written.
$st     = (new StockService())->structure();
$gesamt = $st->hasField('purchase_total_price') ? $st->field('purchase_total_price') : (int) ($apply([['op' => 'add_field', 'ref' => 'g', 'node' => (string) $r['pur'], 'name' => '__Preis gesamt', 'target' => 4232, 'kind' => 'composition', 'multiplicity' => '0..1']])['refs']['g'] ?? 0);
$opt    = get_option('taxmod_stock_structure');
if (is_array($opt) && ! $st->hasField('purchase_total_price')) {
    $opt['fields']['purchase_total_price'] = $gesamt;
    update_option('taxmod_stock_structure', $opt);
}
$kaufWerte = [(string) $r['label'] => 'Stiftleiste · Packung 50 St', (string) $r['k_offen'] => '0', (string) $r['k_mod'] => (string) $r['t1'], (string) $r['k_anz'] => '1',
    (string) $r['k_preis'] => ['neu' => ['4234' => '2.2']], (string) $r['k_ein'] => ['neu' => ['4234' => '2.2']], (string) $gesamt => ['neu' => ['4234' => '2.2']]];
$pack = $apply([['op' => 'create_record', 'ref' => 'k', 'node' => (string) $r['pur']], ['op' => 'set_values', 'record' => '@k', 'values' => $kaufWerte],
    ['op' => 'create_record', 'ref' => 'b', 'node' => (string) $r['ord']], ['op' => 'set_values', 'record' => '@b', 'values' => [(string) $r['label'] => 'Packung', (string) $r['o_pos'] => ['values' => ['@k']]]]])['refs']
    // «Positionen» as composition: the purchase is a part of its order.
    ?? $apply([['op' => 'create_record', 'ref' => 'b', 'node' => (string) $r['ord']], ['op' => 'set_values', 'record' => '@b', 'values' => [(string) $r['label'] => 'Packung', (string) $r['o_pos'] => ['k1' => $kaufWerte]]]])['refs'] ?? [];
$pack['k'] ??= (int) ((new StockService())->table('order')->all((int) ($pack['b'] ?? 0), $r['o_pos'])[0] ?? 0);
(new StockService())->bookReceipt((int) $pack['b'], [(int) $pack['k'] => ReceiptChoice::keep()], '2026-11-09', 'WE Packung', [(int) $pack['k'] => '50']);
$s      = new StockService();
$betrag = static fn (?string $teil): ?string => $teil === null ? null : $s->table('money')->first((int) $teil, 4234);
$posten = array_values(array_filter($s->table('lot')->ids(), static fn (int $l): bool => (int) $s->table('lot')->first($l, $r['l_kauf']) === (int) $pack['k']))[0] ?? 0;
$pruefe($s->table('purchase')->first((int) $pack['k'], $r['k_anz']) === '50' && (float) $betrag($s->table('purchase')->first((int) $pack['k'], $r['k_preis'])) === 0.044
    && (float) $betrag($s->table('purchase')->first((int) $pack['k'], $gesamt)) === 2.2 && (float) $betrag($s->table('lot')->first($posten, $r['l_wert'])) === 0.044,
    'D-1290: 1 pack corrected to 50 pieces at the receipt: «Preis je Stück» 0.044, total 2.2, the lot worth 0.044 a piece');
// His word 2026-11-09 «auch fehlt die einheit (€)»: the receipt's prices as the modeller words them.
$worte  = $s->fieldWords([(int) $pack['k']], ['purchase_unit_price', 'purchase_total_price']);
$pruefe(preg_match('/0[.,]044/', $worte['purchase_unit_price'][(int) $pack['k']] ?? '') === 1 && ($worte['purchase_total_price'][(int) $pack['k']] ?? '') !== '', 'D-1291: the prices come as the modeller words them');
$quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/InboundPage.php');
$pruefe(str_contains($quelle, "\$words['purchase_unit_price'][\$purchase] ?? \$shown(\$each)"), 'D-1291: the receipt\'s purchases show the modeller\'s word, its own number only without one');

// The Configuration tab: the modeller lists it, and pasting structure-live.json stores those ids.
add_filter('taxmod_config_tabs', [TaxmodStock\WordPress\Admin\SettingsTab::class, 'register']);
$reiter = Taxmod\WordPress\Admin\SettingsScreen::moduleTabs();
$pruefe(isset($reiter['stock']), 'the module shows up as a tab on Configuration');
$pruefe(array_key_exists('version', TaxmodStock\WordPress\Admin\SettingsTab::register([])['stock']), 'the tab entry carries the module version (D-968)');
($reiter['stock']['save'])(['taxmod_stock_structure' => (string) file_get_contents(__DIR__ . '/../../structure-live.json')]);
$pruefe((new StockService())->structure()->node('lager') === 149000110147 && (new StockService())->structure()->field('lot_thing') === 149000110118, 'pasting structure-live.json stores the live ids');
($reiter['stock']['save'])(['taxmod_stock_structure' => '{"nodes": {}}']);
$pruefe((new StockService())->structure()->node('lager') === 149000110147, 'an incomplete structure is refused and the old one stays');
// D-1259: the Grundeinstellungen keep the place for purchases without material; the place of a group is a group property.
(new TaxmodStock\WordPress\Admin\SettingsPage())->save(['taxmod_stock_places_sent' => '1', 'taxmod_stock_unreferenced' => '9']);
$pruefe(get_option(StockService::LOCATIONS) === ['unreferenced' => 9], 'Grundeinstellungen store the place for purchases without material, no branch rows (D-972, D-1259)');

// His report 2026-11-05: «zurückbuchen … nicht möglich» on a card only moved — an Umbuchung does not keep the receipt.
update_option(StockService::OPTION, $struktur);
$s      = new StockService();
$von    = $s->structure();
$zwei   = array_values(array_filter($s->table('lot')->ids(), static fn (int $l): bool => in_array((int) $s->table('lot')->first($l, $von->field('lot_purchase')), [$r['kaufC'], $r['kaufD']], true)));
$dLot   = array_values(array_filter($zwei, static fn (int $l): bool => (int) $s->table('lot')->first($l, $von->field('lot_purchase')) === $r['kaufD']))[0] ?? 0;
$dOrt   = (int) $s->table('lot')->first($dLot, $von->field('lot_location'));
$s->moveLots([$dLot], $dOrt, $dOrt === $r['platz'] ? $r['lager'] : $r['platz'], '1', '2026-11-05', 'Umbuchung vor Zurück');
$zeilen = static fn (StockService $x): array => array_values(array_filter($x->table('line')->ids(), static fn (int $l): bool => in_array((int) $x->table('line')->first($l, $r['p_lot']), $zwei, true)));
$vorher = $zeilen(new StockService());
$x      = new StockService();
$x->write((new ReceiptReversal($x->structureWithKinds()))->reverse($r['best2'], $x->table('order'), $x->table('lot'), $x->table('line'), $x->table('transaction')));
$nach   = new StockService();
$pruefe(count($vorher) === 8 && $zeilen($nach) === [] && array_filter($zwei, static fn (int $l): bool => $nach->table('lot')->has($l)) === [], 'a receipt whose lot was only moved goes back, its Umbuchung lines with it');
// D-1301 «Box leer räumen»: a box of its own gets one part picked in; emptying it moves the part back to where it was picked.
$rueckBox  = (new StockService())->copyPlace($schrank, '__Rückgabebox', false);
$rueckFrei = apply_filters(TaxmodStock\WordPress\Plugin::PLACES_OF, null, ['things' => [$bc, $r['t1'], $r['t2']], 'except' => $rueckBox]);
$rueckDing = is_array($rueckFrei) ? (int) array_key_first(array_filter($rueckFrei)) : 0;
$rueckVon  = $rueckDing > 0 ? (int) array_key_first($rueckFrei[$rueckDing]) : 0;
apply_filters(TaxmodStock\WordPress\Plugin::BOOK_PICK, null, ['reference' => $auftrag, 'place' => $rueckBox, 'lines' => [['thing' => $rueckDing, 'from' => $rueckVon, 'qty' => 1]], 'title' => 'Pickliste Rückgabe', 'date' => '2026-11-13']);
$voll      = (new StockService())->presentIn($rueckBox);
$leer      = apply_filters('taxmod_stock_book_return', null, ['box' => $rueckBox, 'reference' => $auftrag, 'date' => '2026-11-13']);
$s         = new StockService();
$pruefe($voll === [$rueckDing => 1.0] && is_int($leer) && $s->presentIn($rueckBox) === [] && (int) $s->table('transaction')->first($leer, $s->structure()->field('transaction_kind')) === $s->structure()->value('kind_transfer'), 'D-1301: Box leer räumen moves the picked part back to its place, one Umbuchung' . ($leer instanceof WP_Error ? ': ' . $leer->get_error_message() : ''));

// D-1260 for the sales module: a sale leaves as «Verkauf»; an old sale without stock is backfilled and leaves in one act.
$s       = new StockService();
$kinds   = static fn (StockService $s, int $deal): int => (int) $s->table('transaction')->first($deal, $s->structure()->field('transaction_kind'));
$reste   = apply_filters(TaxmodStock\WordPress\Plugin::LOTS_OF, null, ['materials' => [$r['t1']]]);
$losLot  = is_array($reste) ? (int) array_key_first($reste[$r['t1']] ?? []) : 0;
$pruefe(is_array($reste) && $losLot > 0 && abs($reste[$r['t1']][$losLot] - $s->ledger()->restOf($losLot)) < 1e-9, 'D-1260: lots_of answers material → lot → rest');
$vorher  = $s->ledger()->stockOf($r['t1']);
$verkauf = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_SALE, null, ['sale' => $auftrag, 'lines' => [$losLot => '1'], 'date' => '2026-11-05', 'title' => 'Verkauf Test']);
$s       = new StockService();
$pruefe(is_int($verkauf) && $s->ledger()->stockOf($r['t1']) === $vorher - 1.0 && $kinds($s, $verkauf) === $r['k_vk']
    && (int) $s->table('transaction')->first($verkauf, $s->structure()->field('transaction_reference')) === $auftrag, 'D-1260: a sale leaves as one Vorgang «Verkauf» naming the sale' . ($verkauf instanceof WP_Error ? ': ' . $verkauf->get_error_message() : ''));
$storno  = is_int($verkauf) ? apply_filters(TaxmodStock\WordPress\Plugin::CANCEL_SALE, null, ['deal' => $verkauf, 'who' => 'check']) : null;
$pruefe(is_int($storno) && (new StockService())->ledger()->stockOf($r['t1']) === $vorher, 'D-1260: the Storno of a sale puts the piece back' . ($storno instanceof WP_Error ? ': ' . $storno->get_error_message() : ''));
$eins    = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_SALE, null, ['sale' => $auftrag, 'lines' => [$losLot => '1'], 'date' => '2026-11-06']);
$zwei    = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_SALE, null, ['sale' => $auftrag, 'lines' => [$losLot => '1'], 'date' => '2026-11-07']);
$alle    = apply_filters(TaxmodStock\WordPress\Plugin::CANCEL_SALE, null, ['sale' => $auftrag, 'who' => 'check']);
$wieder  = apply_filters(TaxmodStock\WordPress\Plugin::CANCEL_SALE, null, ['sale' => $auftrag, 'who' => 'check']);
$pruefe(is_int($eins) && is_int($zwei) && $alle === 2 && $wieder === 0 && (new StockService())->ledger()->stockOf($r['t1']) === $vorher, 'D-1262: taking a sale back by the sale cancels its open «Verkauf» Vorgänge in one act, the one cancelled before left out; a second time finds none' . ($alle instanceof WP_Error ? ': ' . $alle->get_error_message() : ''));
$zuviel  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_SALE, null, ['sale' => $auftrag, 'lines' => [$losLot => '9999'], 'date' => '2026-11-05']);
$pruefe($zuviel instanceof WP_Error && $zuviel->get_error_code() === 'taxmod_stock_sale_too_large' && (new StockService())->ledger()->stockOf($r['t1']) === $vorher, 'D-1260: a sale larger than the lot is refused, nothing booked');
$vorher  = (new StockService())->ledger()->stockOf($r['t1']);
$nach    = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BACKFILL, null, ['material' => $r['t1'], 'quantity' => '2', 'date' => '2024-03-01', 'reference' => $auftrag, 'value' => '4.5', 'place' => $schrank, 'title' => 'Altverkauf']);
$s       = new StockService();
$deals   = $s->table('transaction');
$both    = array_values(array_filter($deals->ids(), static fn (int $d): bool => (int) $deals->first($d, $s->structure()->field('transaction_reference')) === $auftrag && substr((string) $deals->first($d, $s->structure()->field('transaction_date')), 0, 10) === '2024-03-01'));
$pruefe(is_int($nach) && $s->ledger()->stockOf($r['t1']) === $vorher && $s->ledger()->restOf($nach) === 0.0 && $s->ledger()->thingOf($nach) === $r['t1']
    && array_map(static fn (int $d): int => $kinds($s, $d), $both) === [$r['k_we'], $r['k_vk']], 'D-1260: an old sale is backfilled on its day and leaves at once — «Nacherfassung» in, «Verkauf» out, the stock of today unchanged' . ($nach instanceof WP_Error ? ': ' . $nach->get_error_message() : ''));
$amOrt   = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BACKFILL, null, ['material' => $r['t1'], 'quantity' => '1', 'date' => '2024-03-02', 'reference' => $auftrag]);
$pruefe(is_int($amOrt) && (int) (new StockService())->table('lot')->first($amOrt, $von->field('lot_location')) === $r['platz'], 'D-1260: without a place given, the backfill lies at the group\'s «Standard-Lagerort» (D-1259)');
(new StockService())->setGroupProperty($r['teile'], StockService::PLACE_PROPERTY, null);
update_option(StockService::LOCATIONS, ['unreferenced' => null]);
$ohne    = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BACKFILL, null, ['material' => $r['t1'], 'quantity' => '1', 'date' => '2024-03-02', 'reference' => $auftrag]);
$pruefe($ohne instanceof WP_Error && $ohne->get_error_code() === 'taxmod_stock_no_backfill_place', 'D-1260: without any place to backfill at, it is refused and no place is made up');

// D-1209: the stock as an ability — at a place and inside it, and per material, the same numbers as the ledger.
$frage = wp_get_ability('taxmod-stock/stock');
$nun   = new StockService();
$oben  = (int) ($nun->locationTree()->ordered()[0][0] ?? 0);
$dort  = $frage?->execute(['place' => $oben, 'limit' => 1000]);
$soll  = 0;

foreach ($nun->ledger()->stockByPlace() as $at) {
    $soll += count(array_intersect_key($at, array_fill_keys($nun->locationTree()->withAllBelow($oben), true)));
}

$pruefe($frage !== null && ($frage->get_meta()['annotations']['readonly'] ?? false) === true, 'taxmod-stock/stock is registered and only reads');
$pruefe(is_array($dort) && $dort['total_lots'] === $soll, 'the ability answers with every lot at the place and inside it');
$pruefe(is_array($ein = $frage?->execute(['materials' => [$bc]])) && abs(($ein['materials'][0]['quantity'] ?? -1.0) - $nun->ledger()->stockOf($bc)) < 1e-9, 'per material: the quantity is the ledger\'s stock');
$pruefe(is_wp_error($frage?->execute(['limit' => 0]) ?? new WP_Error()), 'a wrong limit is refused');
$pruefe(is_array($seite = $frage?->execute(['limit' => 1])) && count($seite['materials']) <= 1 && $seite['total_materials'] >= count($seite['materials']), 'D-1324: the totals per material are paged by limit, total_materials counts them all');

// D-1297, his choice: «Verbrauch» holds kinds below it; a consumption names a leaf, the Ersatzversand books «Verlust/Schwund».
$vorBau = (new StockService())->ledger()->stockOf($r['t1']);
$alt    = ConsumptionByKind::from((new StockService())->structureWithKinds(), (new StockService())->table('transaction'), (new StockService())->table('line'), (new StockService())->table('lot'), (new StockService())->table('money'))->cells();
$arten  = $apply([
    ['op' => 'create_node', 'ref' => 'bb', 'name' => '__Betriebsbedarf', 'parent' => (string) $r['k_vb'], 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'gr', 'name' => '__Abgang', 'parent' => (string) $r['k_vb'], 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'vs', 'name' => '__Verlust/Schwund', 'parent' => '@gr', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'da', 'name' => '__Defekt/Ausschuss', 'parent' => '@gr', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'pr', 'name' => '__Projekt', 'parent' => (string) $r['k_vb'], 'class' => 'Constant'],
])['refs'] ?? [];
$opt = get_option('taxmod_stock_structure');
if (is_array($opt)) {
    $opt['values']['kind_loss']  = (int) $arten['vs'];
    $opt['values']['kind_scrap'] = (int) $arten['da'];
    $opt['values']['kind_build'] = (int) $arten['pr'];
    update_option('taxmod_stock_structure', $opt);
}
$s = new StockService();
$pruefe(array_diff([(int) $arten['bb'], (int) $arten['vs']], $s->consumptionLeaves()) === [] && ! in_array((int) $arten['gr'], $s->consumptionLeaves(), true), 'D-1297: the leaves below «Verbrauch» are the kinds to choose');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'verbrauch', 'thing' => (string) $r['t1']];
$html = (new StockPage())->render();
$artFeld = (int) $s->structure()->field('transaction_kind');
// D-1298: the kind is the modeller's own control of «Art», narrowed to the branch under «Verbrauch».
$pruefe(str_contains($html, 'vorgang[' . $artFeld . ']') && str_contains($html, '__Betriebsbedarf') && ! str_contains($html, '>Wareneingang<') && ! str_contains($html, 'name="art"'),
    'D-1298: the consumption form asks the kind with the modeller\'s chooser, only the branch under «Verbrauch»');
foreach ([0 => 'without a kind', (int) $arten['gr'] => 'with an inner kind'] as $art => $wie) {
    try {
        (new StockService())->bookConsumption($r['t1'], null, '1', Purpose::none(), '2026-11-10', 'Verbrauch ohne Art', null, '', $art);
        $pruefe(false, 'D-1297: a consumption ' . $wie . ' is refused');
    } catch (StockError $e) {
        $pruefe($e->reason === 'no_consumption_kind' && (new StockService())->ledger()->stockOf($r['t1']) === $vorBau, 'D-1297: a consumption ' . $wie . ' is refused, nothing booked');
    }
}
$bb   = (new StockService())->bookConsumption($r['t1'], null, '1', Purpose::none(), '2026-11-10', 'Verbrauch Betrieb', null, '', (int) $arten['bb']);
$s    = new StockService();
$deal = (int) ($bb['refs']['v1'] ?? array_values(array_filter($bb['refs'] ?? [], static fn ($v, $k): bool => str_starts_with((string) $k, 'v'), ARRAY_FILTER_USE_BOTH))[0] ?? 0);
$pruefe($s->ledger()->stockOf($r['t1']) === $vorBau - 1.0 && (int) $s->table('transaction')->first($deal, $s->structure()->field('transaction_kind')) === (int) $arten['bb'], 'D-1297: a consumption books the kind chosen and takes the piece');
$events = (new PeriodActivity($s->structureWithKinds(), $s->table('transaction'), $s->table('line'), $s->table('lot'), '2026-11-10', '2026-11-11'))->events('2026-11-10', '2026-11-11');
$hier   = array_values(array_filter($events, static fn (array $e): bool => $e['deal'] === $deal))[0] ?? [];
$pruefe(($hier['kind'] ?? 0) === $s->structure()->value('kind_consumption') && ($hier['art'] ?? 0) === (int) $arten['bb'], 'D-1297: the period counts a kind below «Verbrauch» as «Verbrauch», keeping its kind');
$bericht = ConsumptionByKind::from($s->structureWithKinds(), $s->table('transaction'), $s->table('line'), $s->table('lot'), $s->table('money'))->cells();
$zelle   = $bericht[(int) $arten['bb']]['2026'] ?? null;
$pruefe($zelle !== null && ($zelle['value'] > 0.0 || $zelle['unvalued'] === 1) && ($bericht[$r['k_vb']] ?? null) == ($alt[$r['k_vb']] ?? null), 'D-1297: the report counts the new kind per year, a lot without value apart; the old «Verbrauch» stays as it was');
(new StockService())->cancelConsumption($deal, '2026-11-10 12:00', 'check');
$pruefe((new StockService())->ledger()->stockOf($r['t1']) === $vorBau, 'D-1297: a consumption of a kind below «Verbrauch» is cancelled like any other');
$losLot2 = (new StockService())->ledger()->proposedLot($r['t1']);
$verlust = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_LOSS, null, ['sale' => $auftrag, 'lot' => $losLot2, 'quantity' => '1', 'reason' => 'Ersatzversand', 'date' => '2026-11-10', 'title' => 'Verlust Ersatzversand']);
$s       = new StockService();
$pruefe(is_int($verlust) && (int) $s->table('transaction')->first($verlust, $s->structure()->field('transaction_kind')) === (int) $arten['vs'], 'D-1297: the Ersatzversand books «Verlust/Schwund», named by the structure' . ($verlust instanceof WP_Error ? ': ' . $verlust->get_error_message() : ''));
// D-1302: scrap from the box, as «Defekt/Ausschuss» naming the order, the reason as description.
$vorAus  = (new StockService())->presentIn($schrank)[$bc] ?? 0.0;
$aus     = apply_filters('taxmod_stock_book_scrap', null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => 1], 'reason' => 'Ausschuss Fertigung: Test', 'date' => '2026-11-13', 'title' => 'Ausschuss Testkarte']);
$s       = new StockService();
$pruefe(is_int($aus) && ($s->presentIn($schrank)[$bc] ?? 0.0) === $vorAus - 1.0 && (int) $s->table('transaction')->first($aus, $s->structure()->field('transaction_kind')) === (int) $arten['da'] && (int) $s->table('transaction')->first($aus, $s->structure()->field('transaction_reference')) === $auftrag && (! $s->structure()->hasField('description') || $s->table('transaction')->first($aus, $s->structure()->field('description')) === 'Ausschuss Fertigung: Test'), 'D-1302: book_scrap takes from the box as «Defekt/Ausschuss», naming the order and the reason' . ($aus instanceof WP_Error ? ': ' . $aus->get_error_message() : ''));
if (is_int($aus)) {
    (new StockService())->cancelConsumption($aus, '2026-11-13 12:00', 'check');
}
// D-1330: a build is booked as «Projekt» below «Verbrauch».
$bau = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BUILD, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => 1], 'title' => 'Fertigung Projekt', 'date' => '2026-11-13']);
$s   = new StockService();
$pruefe(is_int($bau) && (int) $s->table('transaction')->first($bau, $s->structure()->field('transaction_kind')) === (int) $arten['pr'], 'D-1330: a build books its consumption as «Projekt»' . ($bau instanceof WP_Error ? ': ' . $bau->get_error_message() : ''));
if (is_int($bau)) {
    apply_filters(TaxmodStock\WordPress\Plugin::BOOK_BUILD, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$bc => -1], 'title' => 'Fertigung Projekt zurück', 'date' => '2026-11-13']);
}
// D-1303: without the stage field the stages are refused, not guessed; the production's receipt place is a setting.
$stufen = apply_filters('taxmod_stock_lot_stages', null, ['lots' => [1]]);
update_option(StockService::PRODUCTION_INBOUND, $schrank, false);
$weProd = apply_filters('taxmod_stock_production_inbound', null);
delete_option(StockService::PRODUCTION_INBOUND);
$pruefe(($s->structure()->hasField('lot_stage') || ($stufen instanceof WP_Error && $stufen->get_error_code() === 'taxmod_stock_not_configured')) && $weProd === $schrank && apply_filters('taxmod_stock_production_inbound', null) === 0, 'D-1303: lot_stages needs the stage field; production_inbound answers the place set, 0 without');
// D-1303 with the stage field: a finished board comes in at its stage; the place answers its lots per stage; a stage is set.
$stufeNeu = $apply([
    ['op' => 'create_node', 'ref' => 'st', 'name' => '__Stufe', 'parent' => (string) $r['mat'], 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'st1', 'name' => '__Aus Fertigung', 'parent' => '@st', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'st2', 'name' => '__In Test', 'parent' => '@st', 'class' => 'Constant'],
    ['op' => 'add_field', 'ref' => 'stf', 'node' => (string) $r['lot'], 'target' => '@st', 'name' => '__Stufe', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
])['refs'] ?? [];
$opt = get_option('taxmod_stock_structure');
if (is_array($opt) && isset($stufeNeu['stf'])) {
    $opt['fields']['lot_stage']             = (int) $stufeNeu['stf'];
    $opt['values']['stage_from_production'] = (int) $stufeNeu['st1'];
    $opt['values']['stage_in_test']         = (int) $stufeNeu['st2'];
    update_option('taxmod_stock_structure', $opt);
}
$weBox   = (new StockService())->copyPlace($schrank, '__WE-PROD', false);
$fertig  = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag, 'place' => $weBox, 'lines' => [$platine => 1], 'title' => 'Fertig WE-PROD', 'date' => '2026-11-13', 'stage' => 'stage_from_production']);
$linie   = apply_filters('taxmod_stock_lot_stages', null, ['place' => $weBox]);
$weLot   = is_array($linie) ? (int) array_key_first($linie['lots']) : 0;
$gesetzt = apply_filters('taxmod_stock_set_lot_stage', null, ['lots' => [$weLot], 'stage' => (int) ($stufeNeu['st2'] ?? 0)]);
$danach  = apply_filters('taxmod_stock_lot_stages', null, ['lots' => [$weLot]]);
$pruefe(is_int($fertig) && is_array($linie) && count($linie['lots']) === 1 && ($linie['lots'][$weLot] ?? []) === ['stage' => (int) ($stufeNeu['st1'] ?? -1), 'thing' => $platine, 'quantity' => 1.0] && is_array($gesetzt) && is_array($danach) && $danach['lots'] === [$weLot => (int) ($stufeNeu['st2'] ?? -1)] && array_keys($danach['stages']) === ['stage_from_production', 'stage_in_test'], 'D-1303: a board comes in «Aus Fertigung», the place lists it per stage, «In Test» is set' . ($fertig instanceof WP_Error ? ': ' . $fertig->get_error_message() : ''));

// D-1299, his question 2026-11-10 «baum in dem ich bestand wähle»: Ausbuchen picks the lot in one chooser (groups, then the
// lots of the group's materials); a preselected lot stands chosen, the material follows from it.
$s      = new StockService();
$einLot = $s->ledger()->proposedLot($r['t1']);
$_GET   = ['page' => StockPage::PAGE, 'tab' => 'verbrauch', 'lot' => (string) $einLot];
$html   = (new StockPage())->render();
$mengeF = (int) $s->structure()->field('line_quantity');
$pruefe(str_contains($html, 'taxmod-record-pick') && preg_match('/name="lot" value="' . $einLot . '" checked/', $html) === 1 && ! str_contains($html, 'name="thing"')
    && str_contains($html, StockPage::LOT_BRANCH) && str_contains($html, StockPage::LOT_SEARCH) && str_contains($html, 'menge[' . $mengeF . ']') && str_contains($html, 'taxmod-stock-unit'),
    'D-1299: Ausbuchen picks the lot in the tree chooser; «Menge» is the modeller\'s control with the material\'s unit beside it');
$ast    = [];
foreach ($s->treeRows() as $reihe) {
    $ast = in_array($einLot, $ast, true) ? $ast : array_column($s->branchLots($reihe['id']), 'id');
}
$such   = array_column($s->searchLots('#' . $einLot), 'id');
$pruefe(in_array($einLot, $ast, true) && ($such[0] ?? 0) === $einLot && array_filter($ast, static fn (int $l): bool => $s->ledger()->restOf($l) <= 0.0) === [],
    'D-1299: a group\'s lots with a rest, and a lot\'s own number finds it first');
$pruefe(($s->unitWords([$r['t1']])[$r['t1']] ?? '') !== '', 'D-1298: every material has a unit word, «Stück» where none is set');
$vorLot = $s->ledger()->restOf($einLot);
$getippt = $s->consumptionTyped([$mengeF => '1'], [$artFeld => (string) $arten['bb']]);
$s->bookConsumption(0, $einLot, $getippt['quantity'], Purpose::none(), '2026-11-10', 'Verbrauch aus dem Baum', null, '', $getippt['kind']);
$pruefe(abs((new StockService())->ledger()->restOf($einLot) - ($vorLot - 1.0)) < 1e-9, 'D-1299: a picked lot books without a material named, the typed values read back by the modeller');

// His word «eingeschränkt auf den material zweig»: the chooser offers only the branch under «Material».
$opt = get_option('taxmod_stock_structure');
$vor = $opt;
if (is_array($opt)) {
    $opt['nodes']['material_root'] = $r['teile'];
    update_option('taxmod_stock_structure', $opt);
}
$s      = new StockService();
$ast    = array_keys($s->pickerTree());
$reihen = array_column($s->treeRows(), 'id');
$pruefe(in_array($r['teile'], $ast, true) && array_diff($reihen, $ast) === [] && count($s->pickerTree()) < count($s->tree()) && in_array($r['t1'], array_column($s->searchThings('#' . $r['t1']), 'id'), true),
    'the material chooser offers only the branch under «Material»; the views keep the whole tree');
update_option('taxmod_stock_structure', $vor);

// His wish 2026-11-11 «Absprung zum Kauf … in einem neuen Tab»: a lot's purchase in the Bestand list links to the purchase.
$mitKauf = 0;
foreach ((new StockService())->ledger()->lotsOf($r['t1']) as $l) {
    $mitKauf = $mitKauf ?: (int) ((new StockService())->table('lot')->first($l, (new StockService())->structure()->field('lot_purchase')) ?? 0);
}
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $r['t1'], 'view' => 'bestand'];
$html = (new StockPage())->render();
$pruefe($mitKauf === 0 || str_contains($html, '#taxmod-stock-purchase-' . $mitKauf . '" target="_blank"') || str_contains($html, 'taxmod_open_record=' . $mitKauf . '" target="_blank"'),
    'D-1310: the Bestand list links a lot\'s purchase to its order\'s view at the purchase, in a new tab');
// D-1310: Material › Einkauf links purchase and order to the order's own view in the Wareneingang.
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $r['t1'], 'view' => 'einkauf'];
$html = (new StockPage())->render();
$pruefe(! str_contains($html, '<table class="widefat striped"><thead><tr><th>Kauf') || str_contains($html, '#taxmod-stock-purchase-'), 'D-1310: Material › Einkauf links to the internal order view');

// D-1309 (S14): the receipt's «Material» dialog is the modeller's one view dialog; the module draws no frame of its own.
$quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/StockPage.php');
$pruefe(str_contains($quelle, "ListPage::viewDialog('taxmod-stock-material-'") && ! str_contains($quelle, '<iframe') && ! str_contains($quelle, 'iframe[data-src]'), 'D-1309: «Material» in the receipt opens in the modeller\'s view dialog');

// D-1308, his word 2026-11-11 «die Buttons oben wie so eine Toolbar arrangiert. Und nicht mehr unten»: the forms carry their acts
// in the toolbar above the fields, and no submit row below.
$oben = static fn (string $html, string $vor): bool => ($t = strpos($html, 'taxmod-toolbar')) !== false && ($f = strpos($html, $vor)) !== false && $t < $f && ! str_contains($html, '<p class="submit"');
$_GET = ['page' => TaxmodStock\WordPress\Admin\SettingsPage::PAGE];
$pruefe($oben((new TaxmodStock\WordPress\Admin\SettingsPage())->render(), 'form-table'), 'D-1308: Grundeinstellungen save in the toolbar above the form');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'inventur'];
$pruefe($oben((new StockPage())->render(), 'rows[0][quantity]'), 'D-1308: «Inventur buchen» in the toolbar above the rows');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'verbrauch'];
$pruefe($oben((new StockPage())->render(), 'Bestandsposten'), 'D-1308: «Verbrauch buchen» in the toolbar above the fields');

// D-1315 (Gesamtkonzept D3): «Platzart ändern …» of a place with places inside — claimed in the editor (D-1334), refused
// without a way, then the new places stand, the stock moved by an Umbuchung, the old places gone.
$arten = $apply([
    ['op' => 'create_node', 'ref' => 'pt', 'name' => '__Platzarten', 'parent' => (string) $r['lo']],
    ['op' => 'create_node', 'ref' => 'ptt', 'name' => '__Platzart-Teile', 'parent' => (string) $r['lo']],
    $field('lo_art', (string) $r['lo'], '__Platzart', '@pt', 'aggregation', '0..1'),
    $field('pt_teile', '@pt', '__Teile', '@ptt', 'aggregation', '0..*'),
    $field('ptt_art', '@ptt', '__Art', '@pt', 'aggregation', '0..1'),
    $field('ptt_anz', '@ptt', '__Anzahl', $int, 'composition', '0..1'),
])['refs'] ?? [];
$lbl     = (string) (new StockService())->structure()->field('label');
$artRoh = isset($arten['ptt_anz']) ? wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'fach', 'node' => (string) $arten['pt'], 'values' => [$lbl => '__Fach']],
    ['op' => 'create_record', 'ref' => 'z1', 'node' => (string) $arten['ptt'], 'values' => [(string) $arten['ptt_art'] => '@fach', (string) $arten['ptt_anz'] => '2']],
    ['op' => 'create_record', 'ref' => 'zwei', 'node' => (string) $arten['pt'], 'values' => [$lbl => '__Zwei', (string) $arten['pt_teile'] => ['values' => ['@z1']]]],
    ['op' => 'create_record', 'ref' => 'd1', 'node' => (string) $arten['ptt'], 'values' => [(string) $arten['ptt_art'] => '@fach', (string) $arten['ptt_anz'] => '3']],
    ['op' => 'create_record', 'ref' => 'drei', 'node' => (string) $arten['pt'], 'values' => [$lbl => '__Drei', (string) $arten['pt_teile'] => ['values' => ['@d1']]]],
]]) : [];
$artSaetze = is_array($artRoh) ? (array) ($artRoh['refs'] ?? []) : [];
$opt = get_option('taxmod_stock_structure');
if (is_array($opt) && isset($artSaetze['drei'])) {
    $opt['nodes']  += ['location_type' => (int) $arten['pt'], 'location_type_part' => (int) $arten['ptt']];
    $opt['fields'] += ['location_place_type' => (int) $arten['lo_art'], 'type_parts' => (int) $arten['pt_teile'], 'part_type' => (int) $arten['ptt_art'], 'part_count' => (int) $arten['ptt_anz']];
    update_option('taxmod_stock_structure', $opt);
}
$zwei   = (int) ($artSaetze['zwei'] ?? 0);
$drei   = (int) ($artSaetze['drei'] ?? 0);
$regal  = (int) ((new StockService())->createPlace('__Regal', $schrank, false, 0, false, $zwei)['refs']['ort'] ?? 0);
$faecher = (new StockService())->locationTree()->childrenOf($regal);
(new StockService())->moveLot($weLot, $weBox, $faecher[1] ?? 0, '1', '2026-11-13', 'D3 vorher');
$claim  = apply_filters('taxmod_claimed_fields', [], $regal, (int) $r['lo']);
try {
    (new StockService())->changePlaceKind($regal, $drei, '', '2026-11-13', 'D3 ohne Weg');
    $ohneWeg = false;
} catch (TaxmodStock\Core\StockError $e) {
    $ohneWeg = $e->reason === 'no_way_chosen';
}
$s      = new StockService();
$ohneWeg = $ohneWeg && $s->kindOf($regal) === $zwei && $s->locationTree()->childrenOf($regal) === $faecher;
$s->changePlaceKind($regal, $drei, TaxmodStock\Core\PlaceKindChange::WAY_FIT, '2026-11-13', 'D3 Art wechseln');
$s      = new StockService();
$neu    = $s->locationTree()->childrenOf($regal);
$pruefe(count($faecher) === 2 && isset($claim[(int) $arten['lo_art']]) && str_contains((string) $claim[(int) $arten['lo_art']]['url'], 'artwechsel=1') && $ohneWeg
    && $s->kindOf($regal) === $drei && count($neu) === 3 && array_intersect($neu, $faecher) === [] && ! $s->table('location')->has($faecher[0])
    && $s->ledger()->restAt($weLot, $neu[1]) === 1.0 && $s->ledger()->restAt($weLot, $faecher[1]) === 0.0,
    'D-1315: «Platzart ändern …» is claimed, refused without a way, then the new places stand, the stock moved 1:1 by an Umbuchung, the old places gone');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'lagerstruktur', 'ort' => (string) $regal, 'artwechsel' => '1'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'value="artwechsel-' . $regal . '"') && preg_match('/id="taxmod-stock-kind-' . $regal . '"[^>]*checked/', $html) === 1 && ! preg_match('/name="way"[^>]*checked/', $html),
    'D-1315: the place opens with «Platzart ändern …» open, no way preselected');

// C1 (D-1320): «Bezugsquellen» live in the material's «Einkauf», in the modeller's controls (D-1250); saving writes only them.
$quellen = $apply([
    ['op' => 'create_node', 'ref' => 'fi', 'name' => '__Firmen', 'parent' => (string) $r['mat']],
    ['op' => 'create_node', 'ref' => 'qu', 'name' => '__Bezugsquellen', 'parent' => (string) $r['mat']],
    $field('qu_firma', '@qu', '__Firma', '@fi', 'aggregation', '0..1'),
    $field('qu_nr', '@qu', '__Nummer', 1175, 'composition', '0..1'),
    $field('qu_inhalt', '@qu', '__Packungsinhalt', 1173, 'composition', '0..1'),
    $field('m_quellen', (string) $r['mat'], '__Bezugsquellen', '@qu', 'aggregation', '0..*'),
])['refs'] ?? [];
$firma = isset($quellen['fi']) ? (int) ($apply([['op' => 'create_record', 'ref' => 'f', 'node' => (string) $quellen['fi'], 'values' => [(string) $r['label'] => '__Reichelt']]])['refs']['f'] ?? 0) : 0;
$opt = get_option('taxmod_stock_structure');
if (is_array($opt) && $firma > 0) {
    $opt['nodes']  += ['source' => (int) $quellen['qu'], 'firm' => (int) $quellen['fi']];
    $opt['fields'] += ['source_firm' => (int) $quellen['qu_firma'], 'source_number' => (int) $quellen['qu_nr'], 'lager_sources' => (int) $quellen['m_quellen'], 'source_content' => (int) $quellen['qu_inhalt']];
    update_option('taxmod_stock_structure', $opt);
}
$minVorher = (new StockService())->things()->lagerOf($r['t1']) === null ? null : (new StockService())->table('lager')->first((int) (new StockService())->things()->lagerOf($r['t1']), (int) $r['m_min']);
try {
    (new StockService())->saveSources($r['t1'], [0 => [(string) $quellen['qu_firma'] => (string) $firma, (string) $quellen['qu_nr'] => 'ABC-1']]);
    $gespeichert = '';
} catch (Throwable $e) {
    $gespeichert = $e->getMessage();
}
$s      = new StockService();
$zeilen = $s->sourcesOf($s->things()->lagerOf($r['t1']));
$_GET   = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $r['t1'], 'view' => 'einkauf'];
$html   = (new StockPage())->render();
$_GET   = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $r['t1'], 'view' => 'lager'];
$lagerHtml = (new StockPage())->render();
$pruefe($gespeichert === '' && count($zeilen) === 1 && $zeilen[0]->number === 'ABC-1' && $zeilen[0]->firm === $firma
    && $s->table('lager')->first((int) $s->things()->lagerOf($r['t1']), (int) $r['m_min']) === $minVorher
    && str_contains($html, 'value="quellen"') && str_contains($html, 'name="quelle[0][part]"') && str_contains($html, 'quelle[1]') && ! str_contains($lagerHtml, '<h3>Bezugsquellen'),
    'C1: «Bezugsquellen» stand in «Einkauf» in the modeller\'s controls, saving writes only them' . ($gespeichert === '' ? '' : ': ' . $gespeichert));
$einheit = (new StockService())->unitWords([$r['t1']])[$r['t1']];
$pruefe(str_contains($html, '<th>Packungsinhalt</th>') && str_contains($html, '<span class="taxmod-stock-unit">' . esc_html($einheit) . '</span>')
    && str_contains($html, 'placeholder="' . ($einheit === 'Stück' ? '1' : '—') . '"') && str_contains($html, 'Letzter Preis je ' . esc_html($einheit)),
    'D-1019: «Packungsinhalt» shows the material\'s base unit beside the number, empty means 1 only for «Stück»');

// C2: on request each source shows the supplier's current offer with its number (taxmod_supplier_offers).
$angebot = static fn (mixed $o, string $q): array => ['list' => $q === 'ABC-1' ? [['merchant' => '__Reichelt', 'number' => 'ABC-1', 'price' => '0.42', 'currency' => 'EUR', 'stock' => 7]] : [], 'errors' => []];
add_filter('taxmod_supplier_offers', $angebot, 10, 2);
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $r['t1'], 'view' => 'einkauf', 'angebote' => '1'];
$html = (new StockPage())->render();
remove_filter('taxmod_supplier_offers', $angebot, 10);
$pruefe(str_contains($html, '__Reichelt 0.42 EUR') && str_contains($html, 'Angebote holen'), 'C2: «Angebote holen» shows each source\'s current offer');

// D-1337/D-1338: «Kurzzeichen erzeugen» from the kinds' Kürzel, typed ones stay; the place's colour, else its kind's.
$kz = $apply([
    $field('pt_kurz', (string) $arten['pt'], '__Kürzel', 1175, 'composition', '0..1'),
    $field('lo_kurz', (string) $r['lo'], '__Kurzzeichen', 1175, 'composition', '0..1'),
    ['op' => 'create_node', 'ref' => 'fa', 'name' => '__Farben', 'parent' => (string) $r['lo']],
    $field('lo_farbe', (string) $r['lo'], '__Farbe', '@fa', 'aggregation', '0..*'),
    $field('pt_farbe', (string) $arten['pt'], '__Farbe', '@fa', 'aggregation', '0..*'),
])['refs'] ?? [];
$farben = isset($kz['fa']) ? $apply([
    ['op' => 'create_record', 'ref' => 'blau', 'node' => (string) $kz['fa'], 'values' => [$lbl => '__Blau']],
    ['op' => 'create_record', 'ref' => 'rot', 'node' => (string) $kz['fa'], 'values' => [$lbl => '__Rot']],
])['refs'] ?? [] : [];
$opt = get_option('taxmod_stock_structure');
if (is_array($opt) && isset($farben['rot'])) {
    $opt['fields'] += ['type_short' => (int) $kz['pt_kurz'], 'location_short' => (int) $kz['lo_kurz'], 'location_colour' => (int) $kz['lo_farbe'], 'type_colour' => (int) $kz['pt_farbe']];
    update_option('taxmod_stock_structure', $opt);
}
$neu = (new StockService())->locationTree()->childrenOf($regal);
$apply([
    ['op' => 'set_values', 'record' => (string) $drei, 'values' => [(string) $kz['pt_kurz'] => 'R', (string) $kz['pt_farbe'] => ['values' => [(string) $farben['blau']]]]],
    ['op' => 'set_values', 'record' => (string) $artSaetze['fach'], 'values' => [(string) $kz['pt_kurz'] => 'F']],
    ['op' => 'set_values', 'record' => (string) ($neu[2] ?? 0), 'values' => [(string) $kz['lo_kurz'] => 'X9', (string) $kz['lo_farbe'] => ['values' => [(string) $farben['rot']]]]],
]);
$vorschlag = (new StockService())->shortProposals($regal);
(new StockService())->fillShortMarks($regal);
$s = new StockService();
$t = $s->locationTree();
$pruefe($vorschlag === [$regal => 'R1', $neu[0] => 'R1-F1', $neu[1] => 'R1-F2'] && $t->shortOf($neu[1]) === 'R1-F2' && $t->shortOf($neu[2]) === 'X9'
    && $s->coloursOf($regal) === ['colours' => ['__Blau'], 'inherited' => true] && $s->coloursOf($neu[2]) === ['colours' => ['__Rot'], 'inherited' => false],
    'D-1337/D-1338: short marks proposed from the Kürzel along the path, a typed one stays; a place\'s own colour, else its kind\'s');

// C5 (D-1320): a demand on its own page; ticked positions move together into a new demand, keeping their ids.
$s       = new StockService();
$offene  = array_values(array_filter($s->table('order')->ids(), static fn (int $o): bool => $s->purchasing()->isDemand($o, $s->table('order')) && count($s->table('order')->all($o, $r['o_pos'])) > 0));
$quelleB = $offene[0] ?? 0;
$posB    = array_map('intval', $s->table('order')->all($quelleB, $r['o_pos']));
$_GET    = ['page' => TaxmodStock\WordPress\Admin\PurchasePage::PAGE, 'bedarf' => (string) $quelleB];
$seite   = (new TaxmodStock\WordPress\Admin\PurchasePage())->render();
$antwort = $posB === [] ? [] : (new StockService())->moveDemandLines($quelleB, [$posB[0]], null, '__C5 Rest');
$s       = new StockService();
$neuB    = (int) ($antwort['refs']['b1'] ?? 0);
$pruefe($quelleB > 0 && str_contains($seite, 'Bedarf – ') && str_contains($seite, 'name="positions[]" value="' . $posB[0] . '"') && str_contains($seite, 'Verschieben nach')
    && $neuB > 0 && $s->table('order')->all($neuB, $r['o_pos']) === [(string) $posB[0]] && ! in_array((string) $posB[0], $s->table('order')->all($quelleB, $r['o_pos']), true),
    'C5: a demand has its own page; ticked positions move into a new demand, keeping their ids');

// D-1342 (QK status): untested from production, one lot per piece, each blocked — shown with a red ⊘ — until its result.
$qk = $apply([
    ['op' => 'create_node', 'ref' => 'zu', 'name' => '__Zustand', 'parent' => (string) $r['mat'], 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'zu_un', 'name' => '__ungetestet', 'parent' => '@zu', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'zu_ok', 'name' => '__funktioniert', 'parent' => '@zu', 'class' => 'Constant'],
    $field('l_zust', (string) $r['lot'], '__Zustand', '@zu', 'aggregation', '0..1'),
])['refs'] ?? [];
$opt = get_option('taxmod_stock_structure');
if (is_array($opt) && isset($qk['l_zust'])) {
    $opt['fields'] += ['lot_condition' => (int) $qk['l_zust']];
    $opt['values'] += ['condition_untested' => (int) $qk['zu_un'], 'test_ok' => (int) $qk['zu_ok']];
    update_option('taxmod_stock_structure', $opt);
}
$qkVorgang = apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$platine => 2], 'title' => 'QK zwei', 'date' => '2026-11-13']);
$s       = new StockService();
$qkLots  = array_values(array_filter($s->ledger()->lotsOf($platine), static fn (int $l): bool => (string) $s->table('lot')->first($l, (int) $qk['l_zust']) === (string) $qk['zu_un'] && $s->ledger()->restAt($l, $schrank) > 0.0));
try {
    (new StockService())->moveLot($qkLots[0] ?? 0, $schrank, $weBox, '1', '2026-11-13', 'QK');
    $gesperrt = false;
} catch (TaxmodStock\Core\StockError $e) {
    $gesperrt = $e->reason === 'lot_blocked';
}
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $platine, 'view' => 'bestand'];
$html = (new StockPage())->render();
$apply([['op' => 'set_values', 'record' => (string) ($qkLots[0] ?? 0), 'values' => [(string) $qk['l_zust'] => (string) $qk['zu_ok']]]]);
try {
    (new StockService())->moveLot($qkLots[0] ?? 0, $schrank, $weBox, '1', '2026-11-13', 'QK frei');
    $frei = true;
} catch (TaxmodStock\Core\StockError $e) {
    $frei = false;
}
$pruefe(is_int($qkVorgang) && count($qkLots) === 2 && $gesperrt && str_contains($html, 'taxmod-stock-blocked') && $frei && (new StockService())->blockedLots($qkLots) === [$qkLots[1]],
    'D-1342: two untested boards are two blocked lots with a red ⊘; a test result frees one, it moves');

// D-1419: the production's step «Test» — the result of ticked lots through one filter, one act, undone by its number;
// «Verschrotten …» books the lots out as «Defekt/Ausschuss» with their report «defekt»; the «In Test» list shows the same lots.
$qk2 = $apply([
    ['op' => 'create_node', 'ref' => 'zu_te', 'name' => '__teilweise OK', 'parent' => (string) $qk['zu'], 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'zu_wa', 'name' => '__Wartung nötig', 'parent' => (string) $qk['zu'], 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'zu_de', 'name' => '__defekt', 'parent' => (string) $qk['zu'], 'class' => 'Constant'],
    $field('l_am', (string) $r['lot'], '__Getestet am', 1183, 'composition', '0..1'),
    $field('l_bef', (string) $r['lot'], '__Testbefund', 1175, 'composition', '0..1'),
])['refs'] ?? [];
$opt = get_option('taxmod_stock_structure');
if (is_array($opt) && isset($qk2['l_am'])) {
    $opt['fields'] += ['lot_tested_on' => (int) $qk2['l_am'], 'lot_test_note' => (int) $qk2['l_bef']];
    $opt['values'] += ['test_partial' => (int) $qk2['zu_te'], 'test_maintenance' => (int) $qk2['zu_wa'], 'test_defect' => (int) $qk2['zu_de']];
    update_option('taxmod_stock_structure', $opt);
}
apply_filters(TaxmodStock\WordPress\Plugin::BOOK_OUTPUT, null, ['reference' => $auftrag, 'place' => $schrank, 'lines' => [$platine => 3], 'title' => 'D-1419 drei', 'date' => '2026-11-17']);
$s       = new StockService();
$neu3    = array_values(array_filter($s->ledger()->lotsOf($platine), static fn (int $l): bool => (string) $s->table('lot')->first($l, (int) $qk['l_zust']) === (string) $qk['zu_un'] && $s->ledger()->restAt($l, $schrank) > 0.0 && $l !== ($qkLots[1] ?? 0)));
$imTest  = (new TaxmodStock\WordPress\InboundService($s))->lotsToTest();
$act     = apply_filters(TaxmodStock\WordPress\Plugin::TEST_RESULT, null, ['lots' => array_slice($neu3, 0, 2), 'result' => 'passed', 'note' => '', 'date' => '2026-11-17']);
$s       = new StockService();
$nachOk  = $s->blockedLots($neu3);
$zurueck = is_int($act) && $act > 0 ? $apply([['op' => 'undo_act', 'act' => $act]]) : null;
$wieder  = (new StockService())->blockedLots($neu3);
$falsch  = apply_filters(TaxmodStock\WordPress\Plugin::TEST_RESULT, null, ['lots' => [$neu3[0] ?? 0], 'result' => 'vielleicht', 'date' => '2026-11-17']);
$pruefe(count($neu3) === 3 && array_diff($neu3, $imTest) === [] && is_int($act) && $act > 0 && $nachOk === [$neu3[2]]
    && is_array($zurueck) && count($wieder) === 3 && $falsch instanceof WP_Error,
    'D-1419: «Test bestanden» for two ticked boards frees them in one act, its undo blocks them again; the «In Test» list holds the same lots');
$fail    = apply_filters(TaxmodStock\WordPress\Plugin::TEST_RESULT, null, ['lots' => [$neu3[0] ?? 0], 'result' => 'failed', 'note' => 'Kurzschluss', 'date' => '2026-11-17']);
$s       = new StockService();
$befund  = (string) $s->table('lot')->first($neu3[0] ?? 0, (int) $qk2['l_bef']);
$zustand = (string) $s->table('lot')->first($neu3[0] ?? 0, (int) $qk['l_zust']);
$schrott = apply_filters(TaxmodStock\WordPress\Plugin::SCRAP_LOTS, null, ['lots' => [$neu3[1] ?? 0], 'reference' => $auftrag, 'reason' => 'Ausschuss Fertigung: verbrannt', 'title' => 'Ausschuss', 'date' => '2026-11-17']);
$s       = new StockService();
$pruefe(is_int($fail) && $befund === 'Kurzschluss' && $zustand === (string) $qk2['zu_de'] && is_int($schrott)
    && $s->ledger()->restOf($neu3[1] ?? 0) === 0.0 && (string) $s->table('lot')->first($neu3[1] ?? 0, (int) $qk['l_zust']) === (string) $qk2['zu_de']
    && (string) $s->table('transaction')->first($schrott, $s->structure()->field('transaction_kind')) === (string) $s->structureWithKinds()->value('kind_scrap'),
    'D-1419: «Test nicht bestanden» sets «defekt» with the note; «Verschrotten» books the untested board out as «Defekt/Ausschuss», its report «defekt», one act');
$tl = apply_filters(TaxmodStock\WordPress\Plugin::TEST_LOTS, null, ['reference' => $auftrag]);
$pruefe(is_array($tl) && ($tl[$neu3[0] ?? 0]['state'] ?? '') === 'failed' && ($tl[$neu3[0] ?? 0]['note'] ?? '') === 'Kurzschluss' && ($tl[$neu3[0] ?? 0]['tested_on'] ?? '') === '2026-11-17'
    && ($tl[$neu3[1] ?? 0]['state'] ?? '') === 'scrapped' && ($tl[$neu3[1] ?? 0]['quantity'] ?? -1) === 0.0
    && ($tl[$neu3[2] ?? 0]['state'] ?? '') === 'untested' && ($tl[$neu3[2] ?? 0]['place'] ?? 0) === $schrank && ($tl[$neu3[2] ?? 0]['thing'] ?? 0) === $platine,
    'D-1419: taxmod_stock_test_lots names each board of the order with material, place, rest and its state from the test report');

$pruefe(apply_filters('taxmod_stock_test_place', null) === (int) ((new StockService())->testSettings([])->place ?? 0) || apply_filters('taxmod_stock_test_place', null) === 0, 'D-1342: taxmod_stock_test_place answers the place «Test», 0 without');

// D-1357: the Lager's searches take the one rule from the modeller; the core gets it handed in, never a copy of its own.
$quelle = static fn (string $datei): string => (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $datei);
// D-1444, his choice 2026-11-18 12:26 «Button wie überall»: no place chooser of the Lager stands open as a tree; each has its button.
$pruefe(! preg_match('/inline:\s*true/', $quelle('WordPress/Admin/StockPage.php') . $quelle('WordPress/Admin/InboundPage.php')), 'D-1444: every place chooser of the Lager is the field with its tree button, none inline');

// Changed with D-1440: the old order list of «Einbuchen» and its search are gone; the material list keeps its own.
$pruefe(substr_count($quelle('WordPress/Admin/StockPage.php'), 'Wildcard::matches(...)') >= 1 && str_contains($quelle('WordPress/Admin/ContactsPage.php'), 'Wildcard::matches(...)')
    && ! str_contains($quelle('Core/StockList.php'), 'mb_stripos') && ! str_contains($quelle('Core/Contacts.php'), 'str_contains'), 'D-1357: stock and contact search use «*» through Wildcard, the core holds no rule of its own');

// D-1368 (ergänzt D-1337), his report 2026-11-14 «das r1 wird nicht erzeugt … und er setzt es auf b6»: the next number follows
// the highest one typed, the prefix is the mark above, a place without a kind gets none.
$baum = new \TaxmodStock\Core\LocationTree([1 => 'R1 Regal 1', 2 => 'R1-F1 Fach 1', 3 => 'R1-F1-B6 Din', 4 => 'Pinheader', 5 => 'Ablage'], [2 => 1, 3 => 2, 4 => 2, 5 => 2],
    titles: [1 => 'Regal 1', 2 => 'Fach 1', 3 => 'Din'], shorts: [1 => 'R1', 2 => 'R1-F1', 3 => 'R1-F1-B6']);
$arten = [1 => 10, 2 => 20, 3 => 40, 4 => 40];
$pruefe(\TaxmodStock\Core\ShortMarks::proposed(4, $baum, $arten, [10 => 'R', 20 => 'F', 40 => 'B']) === 'R1-F1-B7'
    && \TaxmodStock\Core\ShortMarks::proposed(5, $baum, $arten, [10 => 'R', 20 => 'F', 40 => 'B']) === '', 'D-1368: siblings typed up to B6 make the next proposal B7; a place without a kind gets none');

// D-1095, his word «order fehlt wird nach name sortier»: a place with its own rank stands first among its siblings, the rest by
// short mark and name; dragged above or below a sibling, the level is renumbered in one write and the tree drops before/after.
$baum = new \TaxmodStock\Core\LocationTree([1 => 'A1 Alex', 2 => 'A1-S1 Schublade', 3 => 'A1-S2 Schublade', 4 => 'A1-S3 Schublade'], [2 => 1, 3 => 1, 4 => 1], orders: [4 => 1]);
$pruefe($baum->childrenOf(1) === [4, 2, 3] && $baum->orderOf(2) === null && array_column($baum->ordered(), 0) === [1, 4, 2, 3],
    'D-1095: a place with its own rank stands first among its siblings, the others follow by short mark and name');
$pruefe(str_contains($quelle('WordPress/StockService.php'), 'public function rankPlace(') && str_contains($quelle('WordPress/Admin/StockPage.php'), "'rank_drop'")
    && str_contains($quelle('WordPress/Admin/StockPage.php'), "'reihenfolge'  => \$this->stock->rankPlace("), 'D-1095: «davor»/«danach» in the place tree reorders the level through rankPlace');

// D-1127: the reservations list offers its row action «Stornieren» for every ticked reservation in the shared bulk bar.
$pruefe(str_contains($quelle('WordPress/Admin/StockPage.php'), "'reservierungensammelstorno' => \$this->cancelReservations(")
    && str_contains($quelle('WordPress/Admin/StockPage.php'), "'taxmod-stock-reservations-storno' => [__('Stornieren …'"), 'D-1127: «Stornieren …» over the ticked reservations, in the shared bulk bar');

// D-1381, his words 2026-11-15 «ein bestand der unterbestände hat», card «Beides»: a sub-lot names its set lot; the set's
// Bestand lists what each set lot holds against the set's wanted content, the sub-lot links back to its set.
$setNeu = $apply([
    ['op' => 'create_node', 'ref' => 'sinh', 'name' => '__Set-Inhalt', 'parent' => (string) $r['mat']],
    ['op' => 'add_field', 'ref' => 'sinh_mat', 'node' => '@sinh', 'target' => 402, 'name' => '__Material', 'kind' => 'aggregation', 'multiplicity' => '1..1'],
    ['op' => 'add_field', 'ref' => 'sinh_menge', 'node' => '@sinh', 'target' => 1173, 'name' => '__Menge', 'kind' => 'composition', 'multiplicity' => '1..1'],
    ['op' => 'add_field', 'ref' => 'sinh_feld', 'node' => (string) $r['mat'], 'target' => '@sinh', 'name' => '__Set-Inhalt', 'kind' => 'composition', 'multiplicity' => '0..*'],
    ['op' => 'add_field', 'ref' => 'ausset', 'node' => (string) $r['lot'], 'target' => (string) $r['lot'], 'name' => '__aus Set', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
])['refs'] ?? [];
$opt = get_option('taxmod_stock_structure');
$vor = $opt;
if (is_array($opt) && isset($setNeu['ausset'])) {
    $opt['nodes']['set_content']            = (int) $setNeu['sinh'];
    $opt['fields']['lager_set_content']     = (int) $setNeu['sinh_feld'];
    $opt['fields']['set_content_thing']     = (int) $setNeu['sinh_mat'];
    $opt['fields']['set_content_quantity']  = (int) $setNeu['sinh_menge'];
    $opt['fields']['lot_from_set']          = (int) $setNeu['ausset'];
    update_option('taxmod_stock_structure', $opt);
}
$s       = new StockService();
$kopfLos = (int) ($s->ledger()->lotsOf($r['t1'])[0] ?? 0);
$unter   = 0;
foreach ($s->table('lot')->ids() as $l) {
    $unter = $unter ?: ((int) $s->table('lot')->first($l, $s->structure()->field('lot_thing')) !== $r['t1'] && $s->ledger()->restOf($l) > 0.0 ? $l : 0);
}
$unterDing = (int) $s->table('lot')->first($unter, $s->structure()->field('lot_thing'));
$apply([['op' => 'set_values', 'record' => (string) $unter, 'values' => [(string) ($setNeu['ausset'] ?? 0) => (string) $kopfLos]]]);
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $unterDing, 'view' => 'bestand'];
$html = (new StockPage())->render();
$pruefe(str_contains($html, 'aus Set <a href="') && str_contains($html, '#taxmod-stock-lot-' . $kopfLos . '">P-' . $kopfLos . '</a>'), 'D-1381: a sub-lot links to the set lot it came from');
$_GET = ['page' => StockPage::PAGE, 'tab' => 'material', 'thing' => (string) $r['t1'], 'view' => 'bestand'];
$html = (new StockPage())->render();
$pruefe($kopfLos > 0 && $unter > 0 && str_contains($html, 'Inhalt von Set-Posten P-' . $kopfLos) && str_contains($html, '#taxmod-stock-lot-' . $unter . '">P-' . $unter . '</a>') && str_contains($html, 'nicht im Set'),
    'D-1381: the set\'s Bestand shows what its set lot holds, the sub-lot linked, a kind not wanted marked «nicht im Set»');
$setTab = (string) strstr((string) strstr($html, 'Inhalt von Set-Posten P-' . $kopfLos), '</table>', true);
$zeile  = (string) strstr((string) strstr($setTab, '<tbody>'), '</tr>', true);
$wort   = $s->things()->word($unterDing);
$pruefe($wort !== '' && str_contains($zeile, '>' . esc_html($wort) . '</a>') && substr_count($zeile, '<td') === 6,
    'D-1381: a set row names each material by its own label, not only the one the view is scoped to, and fills the row to the «Actions» column');
$apply([['op' => 'set_values', 'record' => (string) $unter, 'values' => [(string) ($setNeu['ausset'] ?? 0) => '']]]);
update_option('taxmod_stock_structure', $vor);

// D-1382: a correction over MCP runs through the inventory's own booking — a line on an existing lot, a new lot at its
// place with its purchase; refused whole without a note or with an unknown place, the error naming the row.
$s        = new StockService();
$korrLos  = (int) ($s->ledger()->lotsOf($r['t1'])[0] ?? 0);
$korrOrt  = (int) array_key_first(array_filter($s->ledger()->placesOf($korrLos), static fn (float $q): bool => $q > 0.0) ?: [$s->ledger()->homeOf($korrLos) => 0]);
$vorher   = $s->ledger()->restOf($korrLos);
$vorAnz   = count($s->ledger()->lotsOf($r['t1']));
$ability  = wp_get_ability(TaxmodStock\WordPress\CorrectionAbility::NAME);
$ohne     = $ability?->execute(['note' => ' ', 'rows' => [['lot' => $korrLos, 'place' => $korrOrt, 'quantity' => '1']]]);
$falsch   = $ability?->execute(['note' => 'Prüfung', 'rows' => [['lot' => $korrLos, 'place' => $korrOrt, 'quantity' => '1'], ['thing' => $r['t1'], 'place' => 999999999, 'quantity' => '1']]]);
$s        = new StockService();
$unberuehrt = abs($s->ledger()->restOf($korrLos) - $vorher) < 1e-9 && count($s->ledger()->lotsOf($r['t1'])) === $vorAnz;
$gebucht  = $ability?->execute(['note' => 'D-1381: Set-Kopf wiederhergestellt', 'date' => '2026-11-15', 'rows' => [['lot' => $korrLos, 'place' => $korrOrt, 'quantity' => '1'], ['thing' => $r['t1'], 'place' => $korrOrt, 'quantity' => '1', 'value' => '0']]]);
$s        = new StockService();
$pruefe($ability !== null && $ohne instanceof WP_Error && $falsch instanceof WP_Error && str_contains($falsch->get_error_message(), 'row 2') && $unberuehrt
    && is_array($gebucht) && abs($s->ledger()->restOf($korrLos) - ($vorher + 1)) < 1e-9 && count($s->ledger()->lotsOf($r['t1'])) === $vorAnz + 1,
    'D-1382: taxmod-stock/book-correction books +1 on a lot and a new lot through the inventory booking; without a note or with an unknown place nothing is booked, the error names the row' . ($gebucht instanceof WP_Error ? ': ' . $gebucht->get_error_message() : ''));

// D-1385, his wish 2026-11-15 «im menü bestand unten suchmaske für material»: the search under «Bestand» finds «µ» and «u»
// alike, the number from the label opens the material, each hit with its stock — and it stands in the navigation.
$s       = new StockService();
$lblFeld = (string) $s->structure()->field('label');
$altName = (string) ($s->recordLabels([$r['t1']])[$r['t1']] ?? '');
$apply([['op' => 'set_values', 'record' => (string) $r['t1'], 'values' => [$lblFeld => '__47 µF Suchelko']]]);
$s       = new StockService();
$ids     = static fn (array $hits): array => array_column($hits, 'id');
$mitMy   = $ids($s->searchThings('47 µF Suchelko'));
$mitU    = $ids($s->searchThings('47uf suchelko'));
$perNr   = $s->withStock($s->searchThings('M-' . $r['t1']));
$_GET    = ['page' => StockPage::PAGE, 'tab' => 'material'];
$navi    = TaxmodStock\WordPress\Admin\LogisticsShell::wrap(StockPage::PAGE, '<div></div>');
$_GET    = ['page' => TaxmodStock\WordPress\Admin\Pages::COCKPIT, TaxmodStock\WordPress\Admin\CockpitPage::AREA => 'stock'];
$seite   = (new TaxmodStock\WordPress\Admin\CockpitPage())->render();
$pruefe(in_array($r['t1'], $mitMy, true) && in_array($r['t1'], $mitU, true) && ($perNr[0]['id'] ?? 0) === $r['t1']
    && ($perNr[0]['quantity'] ?? '') === rtrim(rtrim(number_format($s->ledger()->stockOf($r['t1']), 3, ',', ''), '0'), ',')
    && str_contains($seite, 'id="taxmod-material-search"') && str_contains($seite, 'stock=1') && ! str_contains($navi, 'taxmod-material-search"'),
    'D-1385: the material search on the page «Bestand» opens (not in the navigation) finds «µ» and «u» alike, the label number finds the material first, each hit with its stock');
$apply([['op' => 'set_values', 'record' => (string) $r['t1'], 'values' => [$lblFeld => $altName]]]);

// D-1387 (asked by the manufacturing 2026-11-15): the material search as a picker for another module's dialog — prefilled,
// the preferred materials first, several on one page, the choice as fields; the preferred hits name each material.
$pk1  = (string) apply_filters('taxmod_stock_material_picker', '', ['key' => 'pos1', 'name' => 'wahl[1]', 'term' => 'BC327', 'preferred' => [$r['t1']]]);
$pk2  = (string) apply_filters('taxmod_stock_material_picker', '', ['key' => 'pos2', 'name' => 'wahl[2]']);
$pkHt = (new StockService())->thingHits([$r['t1'], 999999999]);
$pruefe(str_contains($pk1, 'value="BC327"') && str_contains($pk1, 'name="wahl[1][thing]"') && str_contains($pk1, 'name="wahl[1][place]"') && str_contains($pk1, 'name="wahl[1][quantity]"')
    && str_contains($pk1, 'preferred=' . $r['t1']) && str_contains($pk1, 'action=' . TaxmodStock\WordPress\Admin\MaterialPicker::PLACES)
    && str_contains($pk2, 'id="taxmod-material-pick-pos2"') && substr_count($pk1 . $pk2, 'window.taxmodMaterialSearch') <= 1 && count($pkHt) === 1 && $pkHt[0]['id'] === $r['t1'],
    'D-1387: the picker filter draws a prefilled search with the preferred materials first, place and quantity as fields, several on one page');

// D-1139 (CD-7), asked by the manufacturing 2026-11-15: presentInEach reads once for many boxes and says for each what presentIn says.
$s      = new StockService();
$kisten = array_values(array_slice(array_keys($s->places()), 0, 3));
$jede   = $s->presentInEach($kisten);
$pruefe($kisten !== [] && array_filter($kisten, static fn (int $k): bool => ($jede[$k] ?? null) != (new StockService())->presentIn($k)) === [] && $s->presentInEach([]) === [],
    'D-1139: presentInEach gives each box what presentIn gives for it, one read for all');

// D-1408 (modeller live.js): a form sent with .submit() passes the background send by; set-up scripts run again after a swap.
$quellen = implode('', array_map('file_get_contents', glob(dirname(__DIR__, 2) . '/src/WordPress/Admin/*.php')));
$pruefe(! preg_match('/[.]submit\(\)/', $quellen) && substr_count($quellen, 'taxmod:replaced') >= 2 && ! str_contains($quellen, 'dataset.ready'),
    'D-1408: no form is sent past the background send, and the Lager scripts set up again (once per element) after a swap');

echo $fehler === 0 ? "all green ({$kind})\n" : "{$fehler} red ({$kind})\n";
exit($fehler === 0 ? 0 : 1);
