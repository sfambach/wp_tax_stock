<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TestQueue;
use TaxmodStock\Core\TestResult;
use TaxmodStock\Core\TestSettings;

/** D-1002: used goods of tested groups wait for their test; the result stays at the lot, with a reason where one is due. */
final class TestQueueTest extends TestCase
{
    #[Test]
    public function a_lot_waits_when_its_group_is_tested_it_has_a_rest_no_result_and_is_not_new(): void
    {
        $s       = $this->structure();
        $f       = static fn (string $key): int => $s->field($key);
        // Lots 1–5 on things in node 30 (below the tested 20), lot 6 in node 40 (not tested).
        $lots    = new Table([
            1 => [$f('lot_thing') => ['501']],
            2 => [$f('lot_thing') => ['502'], $f('lot_condition') => ['301']],
            3 => [$f('lot_thing') => ['503'], $f('lot_condition') => ['400']],
            4 => [$f('lot_thing') => ['504'], $f('lot_condition') => ['401']],
            5 => [$f('lot_thing') => ['505']],
            6 => [$f('lot_thing') => ['506']],
        ]);
        $lines   = new Table([
            11 => [$f('line_lot') => ['1'], $f('line_quantity') => ['1']],
            12 => [$f('line_lot') => ['2'], $f('line_quantity') => ['1']],
            13 => [$f('line_lot') => ['3'], $f('line_quantity') => ['1']],
            14 => [$f('line_lot') => ['4'], $f('line_quantity') => ['1']],
            15 => [$f('line_lot') => ['5'], $f('line_quantity') => ['1']],
            16 => [$f('line_lot') => ['5'], $f('line_quantity') => ['-1']],
            17 => [$f('line_lot') => ['6'], $f('line_quantity') => ['1']],
        ]);
        $nodes   = [1 => 30, 2 => 30, 3 => 30, 4 => 30, 5 => 30, 6 => 40];
        $parents = [30 => 20, 20 => 10, 40 => 10];

        $waiting = (new TestQueue($s))->waiting(new Ledger($s, $lots, $lines), $lots, $nodes, $parents, TestSettings::fromArray(['branches' => [20]]));

        self::assertSame([1, 4], $waiting, '2 has a result, 3 is new, 5 is gone, 6 is in an untested group; 4 is «ungetestet»');
        self::assertSame([], (new TestQueue($s))->waiting(new Ledger($s, $lots, $lines), $lots, $nodes, $parents, TestSettings::fromArray([])), 'no tested branch, no test');
        self::assertSame([], (new TestQueue(Fixture::structure()))->waiting(new Ledger($s, $lots, $lines), $lots, $nodes, $parents, TestSettings::fromArray(['branches' => [20]])), 'without the fields in the structure there is no test');
    }

    #[Test]
    public function a_result_is_written_to_the_lot_and_partial_or_maintenance_need_a_reason(): void
    {
        $s = $this->structure();
        $q = new TestQueue($s);

        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => [
            (string) $s->field('lot_condition')   => '303',
            (string) $s->field('lot_tested_on')   => '2026-10-28',
            (string) $s->field('lot_test_note')   => 'Elkos tauschen',
        ]]], $q->record(7, TestResult::Maintenance, ' Elkos tauschen ', '2026-10-28 17:00'));
        self::assertSame((string) $s->value('test_defect'), $q->record(7, TestResult::Defect, '', '2026-10-28')[0]['values'][(string) $s->field('lot_condition')]);
        self::assertSame(TestResult::Partial, TestResult::ofValue($s, '302'));

        $this->expectException(StockError::class);
        $q->record(7, TestResult::Partial, '  ', '2026-10-28');
    }

    #[Test]
    public function the_tested_branches_survive_a_round_trip_without_doubles_or_junk(): void
    {
        $settings = TestSettings::fromArray(['branches' => [20, '20', 0, 'x', 35]]);

        self::assertSame(['branches' => [20, 35]], $settings->toArray());
        self::assertTrue($settings->covers(35, []));
        self::assertFalse($settings->covers(36, [36 => 37, 37 => 36]), 'a loop in the tree ends');
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['fields'] += ['lot_condition' => 153, 'lot_tested_on' => 151, 'lot_test_note' => 152];
        $raw['values'] += ['test_ok' => 301, 'test_partial' => 302, 'test_maintenance' => 303, 'test_defect' => 304, 'condition_new' => 400, 'condition_untested' => 401];

        return Structure::fromArray($raw);
    }
}
