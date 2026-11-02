<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** D-1091: a box is a carrier, not stock; its move is a Vorgang «Umlagerung» that rebooks no lot. */
final class RelocationTest extends TestCase
{
    #[Test]
    public function an_umlagerung_names_the_box_and_writes_from_and_to_without_a_lot(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-02'))->relocate(30, 3, 4, 'Umlagerung');
        $lines   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && isset($c['values'][(string) $s->field('line_quantity')])));
        $deal    = end($changes)['values'];

        self::assertSame([['-1', '3'], ['1', '4']], array_map(static fn (array $c): array => [$c['values'][(string) $s->field('line_quantity')], $c['values'][(string) $s->field('line_location')]], $lines));
        self::assertSame([false, false], array_map(static fn (array $c): bool => isset($c['values'][(string) $s->field('line_lot')]), $lines), 'no lot is rebooked');
        self::assertSame('211', $deal[(string) $s->field('transaction_kind')]);
        self::assertSame('30', $deal[(string) $s->field('transaction_carrier')]);
    }

    #[Test]
    public function the_top_level_is_a_position_without_a_place(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-02'))->relocate(30, 0, 4, 'Umlagerung');
        $first   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && isset($c['values'][(string) $s->field('line_quantity')])))[0];

        self::assertArrayNotHasKey((string) $s->field('line_location'), $first['values']);
    }

    #[Test]
    public function the_stock_stays_where_it_is(): void
    {
        $s      = Fixture::structure();
        $lots   = new Table([60 => [$s->field('lot_thing') => ['7'], $s->field('lot_location') => ['30'], $s->field('lot_date') => ['2026-11-01']]]);
        $lines  = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['5']],
            81 => [$s->field('line_quantity') => ['-1'], $s->field('line_location') => ['3']],
            82 => [$s->field('line_quantity') => ['1'], $s->field('line_location') => ['4']],
        ]);
        $ledger = new Ledger($s, $lots, $lines);

        self::assertSame([30 => 5.0], $ledger->placesOf(60), 'the lot lies in the box as before');
        self::assertSame(5.0, $ledger->restOf(60));
    }

    #[Test]
    public function without_the_kind_in_the_model_or_to_the_same_place_it_is_refused(): void
    {
        foreach ([[Fixture::structure('transaction_carrier'), 3, 4], [Fixture::structure(), 4, 4]] as [$s, $from, $to]) {
            try {
                (new Bookings($s, '2026-11-02'))->relocate(30, $from, $to, 'U');
                self::fail('refused');
            } catch (StockError $e) {
                self::assertContains($e->reason, ['not_configured', 'same_place']);
            }
        }
    }
}
