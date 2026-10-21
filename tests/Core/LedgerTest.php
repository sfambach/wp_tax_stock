<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** Stock is the sum of the lines, per lot and per material; a consumption takes from the oldest lot with a rest. */
final class LedgerTest extends TestCase
{
    #[Test]
    public function stock_is_the_sum_of_the_lines_of_its_lots(): void
    {
        $ledger = $this->ledger();

        self::assertSame(2.0, $ledger->restOf(11));
        self::assertSame(10.0, $ledger->restOf(12));
        self::assertSame(12.0, $ledger->stockOf(50));
        self::assertSame(1.0, $ledger->stockOf(60));
    }

    #[Test]
    public function the_oldest_lot_with_a_rest_is_proposed(): void
    {
        self::assertSame(12, $this->ledger([11 => ['5', '-5'], 12 => ['3']])->proposedLot(50), 'the empty older lot is skipped');
        self::assertSame(11, $this->ledger()->proposedLot(50), 'oldest first');
    }

    #[Test]
    public function without_any_rest_the_oldest_lot_is_proposed_and_without_a_lot_nothing(): void
    {
        self::assertSame(11, $this->ledger([11 => ['1', '-1'], 12 => ['2', '-2']])->proposedLot(50));

        $this->expectException(StockError::class);
        $this->ledger()->proposedLot(99);
    }

    /** @param array<int, list<string>>|null $moves lot → quantities */
    private function ledger(?array $moves = null): Ledger
    {
        $s    = Fixture::structure();
        $lots = new Table([
            11 => [$s->field('lot_material') => ['50'], $s->field('lot_date') => ['2026-10-01 00:00:00']],
            12 => [$s->field('lot_material') => ['50'], $s->field('lot_date') => ['2026-10-15 00:00:00']],
            13 => [$s->field('lot_material') => ['60'], $s->field('lot_date') => ['2026-10-02 00:00:00']],
        ]);

        $moves ??= [11 => ['5', '-3'], 12 => ['10'], 13 => ['1']];
        $lines = [];
        $id    = 900;

        foreach ($moves as $lot => $quantities) {
            foreach ($quantities as $q) {
                $lines[$id++] = [$s->field('line_lot') => [(string) $lot], $s->field('line_quantity') => [$q]];
            }
        }

        return new Ledger($s, $lots, new Table($lines));
    }
}
