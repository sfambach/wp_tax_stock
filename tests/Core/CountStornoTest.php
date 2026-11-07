<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Storno;
use TaxmodStock\Core\Table;

/** D-1036: an Inventur is taken back by a Storno, whole or one position; a lot booked since keeps its count. */
final class CountStornoTest extends TestCase
{
    #[Test]
    public function one_position_of_a_count_goes_out_again(): void
    {
        $s    = Fixture::structure();
        $rows = (new Storno($s))->countRows(90, 81, $this->deals(), $this->lines());

        self::assertSame([['lot' => 60, 'place' => null, 'quantity' => '-5', 'reverses' => 81]], $rows);

        $changes = (new Bookings($s, '2026-11-07 10:00:00'))->cancelCount($rows, 'Storno: Inventur', 'Stefan, 2026-11-07 10:00:00');
        $line    = $changes[1]['values'];
        self::assertSame('-5', $line[(string) $s->field('line_quantity')]);
        self::assertSame('81', $line[(string) $s->field('line_reverses')]);
        self::assertArrayNotHasKey((string) $s->field('line_counted'), $line);
        self::assertSame('203', end($changes)['values'][(string) $s->field('transaction_kind')]);
    }

    #[Test]
    public function the_whole_count_goes_out_and_the_lots_stand_at_zero(): void
    {
        $s    = Fixture::structure();
        $rows = (new Storno($s))->countRows(90, null, $this->deals(), $this->lines());

        self::assertSame([81, 82], array_column($rows, 'reverses'));

        $lines = $this->lines([91 => [60, '-5', 81], 92 => [61, '-2', 82]]);
        $deals = $this->deals([93 => [203, [91, 92]]]);
        $ledger = new Ledger($s, $this->lots(), $lines, $deals);
        self::assertSame(0.0, $ledger->restOf(60));
        self::assertSame(0.0, $ledger->restOf(61));

        $this->expectException(StockError::class);
        (new Storno($s))->countRows(90, null, $deals, $lines);
    }

    #[Test]
    public function a_lot_booked_since_the_count_keeps_it(): void
    {
        $s     = Fixture::structure();
        $lines = $this->lines([91 => [60, '-1', null]]);

        try {
            (new Storno($s))->countRows(90, 81, $this->deals([92 => [202, [91]]]), $lines);
            self::fail('lot 60 was consumed since');
        } catch (StockError $e) {
            self::assertSame('lot_booked_since_count', $e->reason);
            self::assertSame('60', $e->detail);
        }

        // The other position is free; once the consumption is cancelled, lot 60 is free too.
        self::assertCount(1, (new Storno($s))->countRows(90, 82, $this->deals([92 => [202, [91]]]), $lines));
        $lines = $this->lines([91 => [60, '-1', null], 92 => [60, '1', 91]]);
        self::assertCount(1, (new Storno($s))->countRows(90, 81, $this->deals([93 => [202, [91]], 94 => [202, [92]]]), $lines));
    }

    #[Test]
    public function only_a_count_and_only_its_own_lines(): void
    {
        $s = Fixture::structure();

        foreach ([[93, null], [90, 99]] as [$deal, $line]) {
            try {
                (new Storno($s))->countRows($deal, $line, $this->deals([93 => [202, [81]]]), $this->lines());
                self::fail('not cancellable');
            } catch (StockError $e) {
                self::assertSame('not_cancellable', $e->reason);
            }
        }
    }

    /** @param array<int, array{0: int, 1: list<int>}> $more deal → kind, lines */
    private function deals(array $more = []): Table
    {
        $s    = Fixture::structure();
        $rows = [];

        foreach ([90 => [203, [81, 82]]] + $more as $deal => [$kind, $lines]) {
            $rows[$deal] = [$s->field('label') => ['Inventur'], $s->field('transaction_kind') => [(string) $kind], $s->field('transaction_lines') => array_map('strval', $lines)];
        }

        return new Table($rows);
    }

    /** @param array<int, array{0: int, 1: string, 2: int|null}> $more line → lot, quantity, cancelled line */
    private function lines(array $more = []): Table
    {
        $s    = Fixture::structure();
        $rows = [];

        foreach ([81 => [60, '5', null], 82 => [61, '2', null]] + $more as $line => [$lot, $quantity, $reverses]) {
            $rows[$line] = [$s->field('line_lot') => [(string) $lot], $s->field('line_quantity') => [$quantity]]
                + ($reverses === null ? [] : [$s->field('line_reverses') => [(string) $reverses]]);
        }

        return new Table($rows);
    }

    private function lots(): Table
    {
        $s = Fixture::structure();

        return new Table([
            60 => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['207']],
            61 => [$s->field('lot_thing') => ['31'], $s->field('lot_location') => ['207']],
        ]);
    }
}
