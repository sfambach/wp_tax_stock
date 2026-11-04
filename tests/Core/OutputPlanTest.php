<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\OutputPlan;
use TaxmodStock\Core\StockError;

/** D-1143: finished things in, and cancelled from this order's lots, newest first. */
final class OutputPlanTest extends TestCase
{
    #[Test]
    public function finished_things_come_in_and_a_cancel_takes_the_newest_first(): void
    {
        $plan = OutputPlan::rows([7 => 2.0, 8 => -3.0], [8 => [10 => [1 => 2.0], 11 => [2 => 2.0]]]);

        self::assertSame([['thing' => 7, 'quantity' => 2.0]], $plan['new']);
        self::assertSame([['lot' => 11, 'place' => 2, 'quantity' => 2.0], ['lot' => 10, 'place' => 1, 'quantity' => 1.0]], $plan['back']);
    }

    #[Test]
    public function more_than_this_order_brought_in_is_refused(): void
    {
        $this->expectException(StockError::class);
        OutputPlan::rows([8 => -1.0], []);
    }
}
