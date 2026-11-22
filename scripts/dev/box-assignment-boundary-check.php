<?php declare(strict_types=1);

/**
 * D-1582 against a real database (Prüftor 22.11.): the four cases of an assignment in a project box, through the stock
 * module's filters and service — assign, Storno back to the project, moving out ends it, another order is kept out.
 *
 *     php scripts/dev/box-assignment-boundary-check.php [path/to/wordpress]
 *
 * Builds the small structure of lib/struktur.php, a project, two orders and a box reserved for the project holding 3
 * pieces. Uses the modeller's no-write bracket: nothing survives the run (WP_ROOT, TAXMOD_ROOT).
 *
 * Prüftor 22.11.: where the structure it needs cannot be set up on this database, it says «nichts geprüft» with the reason
 * and exits 2 — never a red that is only the set-up, never a green that checked nothing.
 */

$kind      = 'composition';
$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\Plugin;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$nichtsGeprueft = static function (string $warum): never {
    echo "NICHTS GEPRÜFT: {$warum}\n";
    exit(2);
};

require __DIR__ . '/lib/struktur.php';

$extra = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'proj', 'name' => '__Projekte', 'parent' => $r['stock']],
    ['op' => 'create_node', 'ref' => 'auf', 'name' => '__Aufträge', 'parent' => $r['stock']],
    $field('lo_proj', $r['lo'], 'Projekt', '@proj', 'aggregation', '0..1'),
    $field('lo_res', $r['lo'], 'reserviert für Produktion', $bool, 'composition', '1..1'),
    ['op' => 'create_record', 'ref' => 'atari', 'node' => '@proj'],
    ['op' => 'set_values', 'record' => '@atari', 'values' => [(string) $r['label'] => '__Atari']],
    ['op' => 'create_record', 'ref' => 'andere', 'node' => '@proj'],
    ['op' => 'set_values', 'record' => '@andere', 'values' => [(string) $r['label'] => '__Anderes Projekt']],
    ['op' => 'create_record', 'ref' => 'a1', 'node' => '@auf'],
    ['op' => 'set_values', 'record' => '@a1', 'values' => [(string) $r['label'] => '__Auftrag 1']],
    ['op' => 'create_record', 'ref' => 'a2', 'node' => '@auf'],
    ['op' => 'set_values', 'record' => '@a2', 'values' => [(string) $r['label'] => '__Auftrag 2']],
]]);

if (is_wp_error($extra)) {
    $nichtsGeprueft('project, orders and the flag could not be set up — ' . $extra->get_error_message());
}

$x = (array) ($extra['refs'] ?? []);

foreach (['proj', 'lo_proj', 'lo_res', 'atari', 'andere', 'a1', 'a2'] as $ref) {
    if ((int) ($x[$ref] ?? 0) <= 0) {
        $nichtsGeprueft("the set-up gave no «{$ref}»");
    }
}

// The roles go in by key, over whatever an older stored structure named for them (Prüftor 22.11.: role mismatch on a test database).
$struktur['nodes']['project']            = (int) $x['proj'];
$struktur['fields']['location_project']  = (int) $x['lo_proj'];
$struktur['fields']['location_reserved'] = (int) $x['lo_res'];
update_option(StockService::OPTION, $struktur);
$gelesen = (new StockService())->structure();

foreach (['location_project', 'location_reserved', 'location_parent', 'location_movable', 'transaction_reference', 'line_reverses', 'line_thing'] as $role) {
    if (! $gelesen->hasField($role) || $gelesen->field($role) !== (int) $struktur['fields'][$role]) {
        $nichtsGeprueft("the stock does not read the field role «{$role}» as set up here");
    }
}

foreach (['project', 'location', 'transaction', 'line', 'lot'] as $role) {
    if (! $gelesen->hasNode($role) || $gelesen->node($role) !== (int) $struktur['nodes'][$role]) {
        $nichtsGeprueft("the stock does not read the node role «{$role}» as set up here");
    }
}

if (! $gelesen->hasValue('kind_reservation') || ! $gelesen->hasValue('kind_transfer')) {
    $nichtsGeprueft('the stock knows no kind «Reservierung» or «Umbuchung»');
}

[$atari, $andere, $a1, $a2] = [(int) ($x['atari'] ?? 0), (int) ($x['andere'] ?? 0), (int) ($x['a1'] ?? 0), (int) ($x['a2'] ?? 0)];
try {
    $box   = (int) ((new StockService())->createPlace('__Gebinde Atari', $r['lager'], true, $atari, true)['refs']['ort'] ?? 0);
    $neu   = (new StockService())->bookInventory([new InventoryRow(null, new NewThing($r['teile'], '__Buchsenleiste 1x12'), '__Buchsenleiste 1x12', '3', null, '')], '2026-11-22', 'Inventur');
    $thing = (int) ($neu['refs']['d1'] ?? 0);
    $s     = new StockService();
    $lot   = (int) ($s->ledger()->lotsOf($thing)[0] ?? 0);
    $from  = (int) array_key_first($s->ledger()->placesOf($lot));
    $s->moveLot($lot, $from, $box, '3', '2026-11-22', 'Umbuchung ins Gebinde');
} catch (StockError $e) {
    $nichtsGeprueft('the box could not be set up — ' . $e->reason . ' ' . $e->getMessage());
} catch (\Throwable $e) {
    $nichtsGeprueft('the box could not be set up — ' . $e->getMessage());
}

$s     = new StockService();

if ($box <= 0 || $thing <= 0 || $lot <= 0) {
    $nichtsGeprueft('the box or its 3 pieces could not be set up');
}

$pruefe($s->reservedPlaces()->reservingPlaceOf($box) === $box && $s->ledger()->restAt($lot, $box) === 3.0, '3 pieces lie in the box reserved for __Atari');

foreach ([Plugin::RESERVE => 'reserve', Plugin::RESERVATIONS_OF => 'reservationsOf', Plugin::RELEASE => 'release', Plugin::BOOK_BUILD => 'bookBuild'] as $hook => $method) {
    add_filter($hook, [Plugin::class, $method], 10, 2);
}

$reserve = static fn (int $subject, float $pieces, int $project): mixed => apply_filters(Plugin::RESERVE, null, ['subject' => $subject, 'rows' => [['thing' => $thing, 'quantity' => $pieces, 'place' => $box]], 'date' => '2026-11-22', 'title' => 'Zuweisung', 'project' => $project]);
$given   = static fn (int $subject): float => array_sum(array_map(static fn (array $p): float => (float) ($p['quantity'] ?? 0), (array) ((apply_filters(Plugin::RESERVATIONS_OF, null, ['subjects' => [$subject]]) ?: [])[$subject] ?? [])));
$code    = static fn (mixed $answer): string => $answer instanceof WP_Error ? (string) $answer->get_error_code() : '';

echo "1. Zuweisen\n";
$antwort = $reserve($a1, 2.0, $atari);
$s       = new StockService();
$pruefe(is_array($antwort) && $given($a1) === 2.0, 'order 1 of __Atari is given 2 of the box' . ($antwort instanceof WP_Error ? ': ' . $antwort->get_error_message() : ''));
$pruefe(($s->reservedByThing()[$thing] ?? 0.0) === 3.0, 'the 3 count once: 2 for the order, 1 for the project');
$pruefe($code($reserve($atari, 1.0, $atari)) === 'taxmod_stock_not_an_order_of_project', 'the project itself refines nothing');
$pruefe($code($reserve($a2, 1.0, $andere)) !== '' && $given($a2) === 0.0, 'an order naming another project gets nothing from the box');
$pruefe($code($reserve($a2, 1.0, 0)) !== '' && $given($a2) === 0.0, 'an order naming no project gets nothing from the box');

echo "2. Storno → Projekt\n";
$frei = apply_filters(Plugin::RELEASE, null, ['subject' => $a1]);
$s    = new StockService();
$pruefe(is_array($frei) && $given($a1) === 0.0 && $s->reservations()->boundLots() === [] && ($s->reservedByThing()[$thing] ?? 0.0) === 3.0, 'after the Storno all 3 are the project\'s again, still reserved by the box');

echo "3. Umlagern beendet\n";
$reserve($a1, 2.0, $atari);
(new StockService())->moveLot($lot, $box, $r['lager'], '3', '2026-11-22', 'Umbuchung aus dem Gebinde');
$s = new StockService();
$pruefe($given($a1) === 0.0 && $s->ledger()->restAt($lot, $box) === 0.0, 'moving everything out of the box ends the order\'s 2 in the same write');
$pruefe(($s->reservedByThing()[$thing] ?? 0.0) === 0.0, 'outside the box nothing stays reserved');

echo "4. Fremder Auftrag gesperrt\n";
(new StockService())->moveLot($lot, $r['lager'], $box, '3', '2026-11-22', 'Umbuchung ins Gebinde');
$reserve($a1, 2.0, $atari);
$pruefe($code($reserve($a2, 2.0, $atari)) === 'taxmod_stock_not_enough_free' && $given($a2) === 0.0, 'order 2 cannot be given what order 1 holds (only 1 free)');
$pruefe(is_array($reserve($a2, 1.0, $atari)) && $given($a2) === 1.0, 'order 2 is given the 1 left');

try {
    (new StockService())->bookConsumption($thing, $lot, '1', Purpose::subject($atari), '2026-11-22', 'Verbrauch', $box);
    $pruefe(false, 'a consumption for the project does not take what the orders were given');
} catch (StockError $e) {
    $pruefe($e->reason === 'reserved_for_other', 'a consumption for the project does not take what the orders were given');
}

$bau = apply_filters(Plugin::BOOK_BUILD, null, ['reference' => $a2, 'place' => $box, 'lines' => [$thing => 2], 'title' => 'Bau', 'date' => '2026-11-22']);
$pruefe($bau instanceof WP_Error && (new StockService())->ledger()->restAt($lot, $box) === 3.0, 'a build of order 2 does not take order 1\'s 2');

echo $fehler === 0 ? "green\n" : "{$fehler} red\n";
exit($fehler === 0 ? 0 : 1);
