<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\BuildPlan;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** D-1146: a build takes from its box, oldest lot first, and gives back to it what it took. */
final class BuildPlanTest extends TestCase
{
    #[Test]
    public function it_takes_the_oldest_lot_first_and_only_inside_the_box(): void
    {
        // Lot 60 (old) lies 2 in the box 3, lot 61 (newer) 5 in the drawer 4 inside it, lot 62 (oldest) outside at 9.
        $rows = BuildPlan::rows($this->ledger(), 3, [3, 4], [30 => 4.0], []);

        self::assertSame([['lot' => 60, 'place' => 3, 'quantity' => -2.0], ['lot' => 61, 'place' => 4, 'quantity' => -2.0]], $rows);
    }

    #[Test]
    public function what_does_not_lie_in_the_box_is_refused_whole(): void
    {
        $this->expectException(StockError::class);
        BuildPlan::rows($this->ledger(), 3, [3, 4], [30 => 8.0], []);
    }

    #[Test]
    public function what_the_box_lacks_comes_from_the_orders_reservation_at_most_its_open_quantity(): void
    {
        // D-1301: the box holds 7; lot 62 outside at 9 is reserved for the order, 3 of it open.
        $rows = BuildPlan::rows($this->ledger(), 3, [3, 4], [30 => 8.0], [], [30 => [['lot' => 62, 'quantity' => 3.0]]]);

        self::assertSame([['lot' => 60, 'place' => 3, 'quantity' => -2.0], ['lot' => 61, 'place' => 4, 'quantity' => -5.0], ['lot' => 62, 'place' => 9, 'quantity' => -1.0]], $rows);

        $this->expectException(StockError::class);
        BuildPlan::rows($this->ledger(), 3, [3, 4], [30 => 8.0], [], [30 => [['lot' => 62, 'quantity' => 0.5]]]);
    }

    #[Test]
    public function giving_back_returns_to_the_box_what_the_order_took_newest_lot_first(): void
    {
        $rows = BuildPlan::rows($this->ledger(), 3, [3, 4], [30 => -3.0], [30 => [60 => 2.0, 61 => 2.0]]);

        self::assertSame([['lot' => 61, 'place' => 3, 'quantity' => 2.0], ['lot' => 60, 'place' => 3, 'quantity' => 1.0]], $rows);

        $this->expectException(StockError::class);
        BuildPlan::rows($this->ledger(), 3, [3, 4], [30 => -5.0], [30 => [60 => 2.0, 61 => 2.0]]);
    }

    #[Test]
    public function the_vorgang_is_a_consumption_naming_the_order(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-04'))->build([['lot' => 60, 'place' => 3, 'quantity' => -2.0], ['lot' => 61, 'place' => 3, 'quantity' => 0.5]], 700, 'Fertigung');
        $deal    = end($changes)['values'];
        $lines   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && isset($c['values'][(string) $s->field('line_quantity')])));

        self::assertSame('700', $deal[(string) $s->field('transaction_reference')]);
        self::assertSame((string) $s->value('kind_consumption'), $deal[(string) $s->field('transaction_kind')]);
        self::assertSame(['-2', '0.5'], array_map(static fn (array $c): string => $c['values'][(string) $s->field('line_quantity')], $lines));
    }

    private function ledger(): Ledger
    {
        $s    = Fixture::structure();
        $lot  = static fn (string $date): array => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['3'], $s->field('lot_date') => [$date]];
        $line = static fn (int $lot, string $q, int $place): array => [$s->field('line_lot') => [(string) $lot], $s->field('line_quantity') => [$q], $s->field('line_location') => [(string) $place]];

        return new Ledger($s, new Table([60 => $lot('2026-10-01'), 61 => $lot('2026-10-05'), 62 => $lot('2026-09-01')]), new Table([
            80 => $line(60, '2', 3),
            81 => $line(61, '5', 4),
            82 => $line(62, '9', 9),
        ]));
    }
}
