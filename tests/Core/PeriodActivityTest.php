<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PeriodActivity;
use TaxmodStock\Core\Table;

/** D-1167: what happened between two days — Vorgänge per kind, in and out per step, things moved most. */
final class PeriodActivityTest extends TestCase
{
    #[Test]
    public function only_the_vorgaenge_of_the_span_count_newest_first(): void
    {
        $a = $this->activity();

        self::assertSame([34, 33, 32, 31], array_column($a->events(), 'deal'));
        self::assertSame([201 => 1, 202 => 1, 209 => 1, 210 => 1], $a->countsByKind());
        self::assertSame([202 => 1, 210 => 1], $a->countsByKind('2026-11-03', '2026-11-04'));
    }

    #[Test]
    public function a_transfer_and_a_reservation_move_no_stock_in_or_out(): void
    {
        $flow = $this->activity()->flow(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04']);

        self::assertSame([10.0, 0.0, 0.0, 0.0], $flow['in']);
        self::assertSame([0.0, 0.0, 3.0, 0.0], $flow['out']);
        self::assertSame(['in' => [10.0], 'out' => [3.0]], $this->activity()->flow(['2026-11']));
    }

    #[Test]
    public function the_things_moved_most_add_up_in_and_out(): void
    {
        self::assertSame([50 => 17.0], $this->activity()->mostMoved(5), '10 in, 3 out, 2 + 2 transferred; the reservation moved nothing');
    }

    private function activity(): PeriodActivity
    {
        $s     = Fixture::structure();
        $deal  = static fn (int $kind, string $date, int ...$lines): array => [
            $s->field('transaction_kind')  => [(string) $kind],
            $s->field('transaction_date')  => [$date],
            $s->field('transaction_lines') => array_map('strval', $lines),
        ];
        $line  = static fn (string $q): array => [$s->field('line_lot') => ['11'], $s->field('line_quantity') => [$q]];

        return new PeriodActivity(
            $s,
            new Table([
                30 => $deal(201, '2026-10-31', 900),
                31 => $deal(201, '2026-11-01 00:00:00', 901),
                32 => $deal(209, '2026-11-02', 902, 903),
                33 => $deal(202, '2026-11-03', 904),
                34 => $deal(210, '2026-11-03', 905),
                35 => $deal(201, '2026-11-05', 906),
            ]),
            new Table([900 => $line('4'), 901 => $line('10'), 902 => $line('-2'), 903 => $line('2'), 904 => $line('-3'), 905 => $line('-1'), 906 => $line('1')]),
            new Table([11 => [$s->field('lot_thing') => ['50']]]),
            '2026-11-01',
            '2026-11-05'
        );
    }
}
