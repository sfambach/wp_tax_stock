<?php declare(strict_types=1);

/**
 * D-1582 (Stefan 22.11. «der Auftrag, der kommt und dem ich das zuweise, der kriegt das auch»): the four cases of an
 * assignment in a project box — assign, Storno back to the project, moving out ends it, another order is kept out.
 *
 *     php scripts/dev/box-assignment-check.php
 *
 * Runs the core against tables built here; needs no WordPress. Box 3 of project 700 holds 10 of lot 60 (thing 30),
 * order 500 is given 3 of it, order 501 is another order of the project.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\BuildPlan;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\ReservationRow;
use TaxmodStock\Core\Reservations;
use TaxmodStock\Core\ReservedPlaces;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TransferRow;
use TaxmodStock\Tests\Core\Fixture;

$s      = Fixture::structure();
$red    = 0;
$check  = static function (bool $green, string $what) use (&$red): void {
    printf("  %s %s\n", $green ? 'ok  ' : 'FAIL', $what);
    $red += $green ? 0 : 1;
};
$refused = static function (callable $act): bool {
    try {
        $act();
    } catch (StockError) {
        return true;
    }

    return false;
};

$lots  = new Table([60 => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['3'], $s->field('lot_date') => ['2026-10-01']]]);
$lines = static fn (bool $storno): Table => new Table([
    80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['10']],
    81 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['3'], $s->field('line_thing') => ['30']],
] + ($storno ? [82 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-3'], $s->field('line_thing') => ['30'], $s->field('line_reverses') => ['81']]] : []));
$deals = static fn (bool $storno): Table => new Table([
    89 => [$s->field('transaction_kind') => ['201'], $s->field('transaction_lines') => ['80']],
    90 => [$s->field('transaction_kind') => ['210'], $s->field('transaction_lines') => ['81'], $s->field('transaction_reference') => ['500']],
] + ($storno ? [91 => [$s->field('transaction_kind') => ['210'], $s->field('transaction_lines') => ['82'], $s->field('transaction_reference') => ['500']]] : []));
$world = static function (bool $storno) use ($s, $lots, $lines, $deals): array {
    $ledger = new Ledger($s, $lots, $lines($storno), $deals($storno));

    return [$ledger, Reservations::from($s, $deals($storno), $lines($storno), $ledger)];
};
$box = new ReservedPlaces(new LocationTree([3 => 'Kiste', 4 => 'Regal'], [3 => 0, 4 => 0], [], [], [3 => 700]), [3 => true]);

echo "1. Zuweisen\n";
[$ledger, $list] = $world(false);
$check(! $refused(static fn () => $list->check([new ReservationRow(30, '7', 60)], $ledger, $box, 700)), 'an order of project 700 is given what lies free in its box');
$check(! $refused(static fn () => Reservations::checkRefinement(501, 700, [700 => true], [])), 'an order refines the box of its project');
$check($box->reservedByThing($ledger, $list->boundLots()) === [30 => 7.0], 'the box counts 7 for the project, the 3 given count once, with the order');

echo "2. Storno → Projekt\n";
[$ledger, $list] = $world(true);
$check($list->boundLots() === [], 'after the Storno no order holds anything in the box');
$check($box->reservedByThing($ledger, $list->boundLots()) === [30 => 10.0], 'all 10 are the project\'s again');

echo "3. Umlagern beendet\n";
[$ledger, $list] = $world(false);
$ended = $list->endedBy([new TransferRow(60, 3, 4, '10')], $ledger, $box);
$check(($ended[90][81]['quantity'] ?? 0.0) === 3.0, 'moving everything out of the box ends the order\'s 3');
$changes = (new Bookings($s, '2026-11-22'))->transfer([new TransferRow(60, 3, 4, '10')], 'U', $ledger, '', $box, [60 => true], 0, $list);
$kinds   = array_values(array_filter(array_map(static fn (array $c): ?string => $c['values'][(string) $s->field('transaction_kind')] ?? null, $changes)));
$check($kinds === ['209', '210'], 'the Umbuchung writes its Storno in the same write');
$check($list->endedBy([new TransferRow(60, 3, 3, '10')], $ledger, $box) === [], 'moving within the box ends nothing');
$check(($list->endedByHang([3], 4, $ledger, $box)[90][81]['quantity'] ?? 0.0) === 0.0, 'a box that is the area itself takes its flag along: hanging it ends nothing');
$area = new ReservedPlaces(new LocationTree([2 => 'Bereich', 3 => 'Kiste', 4 => 'Regal'], [2 => 0, 3 => 2, 4 => 0], [], [], [2 => 700]), [2 => true]);
$check(($list->endedByHang([3], 4, $ledger, $area)[90][81]['quantity'] ?? 0.0) === 3.0, 'a box hung out of its area ends the order\'s 3');

echo "4. Fremder Auftrag gesperrt\n";
$check($refused(static fn () => $list->check([new ReservationRow(30, '8', 60)], $ledger, $box, 700)), 'another order cannot be given what 500 holds (8 > 7 free)');
$check($refused(static fn () => $list->check([new ReservationRow(30, '1', 60)], $ledger, $box, 701)), 'an order of another project gets nothing from the box');
$check($refused(static fn () => Reservations::checkRefinement(700, 700, [700 => true], [])), 'the project itself does not refine its own box');
$check($refused(static fn () => Reservations::checkRefinement(800, 700, [700 => true], [800 => true])), 'a sale does not refine a project box');
$check($refused(static fn () => $list->checkConsumption(60, '8', 3, Purpose::subject(501), $ledger, $box)), 'consuming for 501 does not take what 500 was given');
$check(BuildPlan::rows($ledger, 3, [3], [30 => 7.0], [], [], [60 => 3.0]) === [['lot' => 60, 'place' => 3, 'quantity' => -7.0]], 'a build for 501 leaves the 3 of 500 in the box');
$check($refused(static fn () => BuildPlan::rows($ledger, 3, [3], [30 => 8.0], [], [], [60 => 3.0])), 'and is refused beyond them');

echo $red === 0 ? "green\n" : "{$red} red\n";
exit($red === 0 ? 0 : 1);
