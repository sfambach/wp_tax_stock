<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\InboundChanges;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\PurchaseStatus;

/** D-1411, his word 2026-11-16 «Ich glaube hier fehlt ein status auf positionsebene»: each purchase stands in its own stage. */
final class PurchaseStatusTest extends TestCase
{
    #[Test]
    public function each_rule_of_the_purchase_status_holds_in_its_order(): void
    {
        $s       = Fixture::structure();
        $shipped = new InboundOrder(7, null, '2026-11-13', null, ['A1']);
        $all     = PurchaseStatus::of($s, $shipped, [1, 2, 3, 4, 5], [3 => '2026-11-16', 4 => '2026-11-16', 1 => '2026-11-16'], [2 => true, 4 => true], [1 => true]);

        self::assertSame(PurchaseStatus::NotStocked, $all[1], 'closed without stock wins over its date');
        self::assertSame(PurchaseStatus::Booked, $all[2], 'a lot names it');
        self::assertSame(PurchaseStatus::Arrived, $all[3], 'its own date');
        self::assertSame(PurchaseStatus::Booked, $all[4], 'booked wins over its date');
        self::assertSame(PurchaseStatus::Shipped, $all[5], 'the order has a tracking number');
        self::assertSame([1 => PurchaseStatus::Ordered], PurchaseStatus::of($s, new InboundOrder(7, null, '2026-11-13', null, []), [1], [], [], []));
    }

    #[Test]
    public function the_order_date_speaks_for_its_purchases_only_while_none_has_its_own(): void
    {
        $s       = Fixture::structure();
        $arrived = new InboundOrder(7, null, '2026-11-13', '2026-11-16', []);

        self::assertSame([1 => PurchaseStatus::Arrived, 2 => PurchaseStatus::Arrived], PurchaseStatus::of($s, $arrived, [1, 2], [], [], []), 'an order before D-1411');
        self::assertSame([1 => PurchaseStatus::Arrived, 2 => PurchaseStatus::Shipped], PurchaseStatus::of($s, $arrived, [1, 2], [1 => '2026-11-16'], [], []), 'one with its own date: the other is still on its way');
        self::assertSame([1 => PurchaseStatus::Arrived], PurchaseStatus::of($s, new InboundOrder(7, 205, null, null, []), [1], [], [], []), 'status partial, the old way');
    }

    #[Test]
    public function an_order_stands_in_every_stage_one_of_its_purchases_stands_in(): void
    {
        $s     = Fixture::structure();
        $order = new InboundOrder(7, null, '2026-11-13', '2026-11-16', ['A1'], [], false, false,
            [1 => PurchaseStatus::Arrived, 2 => PurchaseStatus::Shipped, 3 => PurchaseStatus::NotStocked]);

        self::assertSame(InboundStage::Arrived, $order->stage($s), 'the furthest open stage');
        self::assertSame([InboundStage::Shipped, InboundStage::Arrived, InboundStage::Booked], $order->stages($s));
        self::assertSame([1], $order->purchasesIn(InboundStage::Arrived, [1, 2, 3]));
        self::assertSame([2], $order->purchasesIn(InboundStage::Shipped, [1, 2, 3]));
        self::assertSame([3], $order->purchasesIn(InboundStage::Booked, [1, 2, 3]));
        self::assertSame([InboundStage::Booked], (new InboundOrder(7, 206, null, '2026-11-16', [], [], false, false, [1 => PurchaseStatus::Booked]))->stages($s));
    }

    #[Test]
    public function arriving_dates_the_ticked_purchases_and_the_order_only_while_it_has_none(): void
    {
        $order = new InboundOrder(7, null, null, null, ['A1'], [], false, false, [1 => PurchaseStatus::Shipped, 2 => PurchaseStatus::Shipped, 3 => PurchaseStatus::Booked]);

        self::assertSame([
            ['op' => 'set_values', 'record' => '7', 'values' => ['50' => '2026-11-16']],
            ['op' => 'set_values', 'record' => '1', 'values' => ['60' => '2026-11-16']],
        ], (new InboundChanges(50, 51, 60))->purchasesArrived($order, [1, 3], '2026-11-16 19:00'));

        $here = new InboundOrder(7, null, null, '2026-11-10', [], [], false, false, [1 => PurchaseStatus::Shipped]);
        self::assertSame([['op' => 'set_values', 'record' => '1', 'values' => ['60' => '2026-11-16']]], (new InboundChanges(50, 51, 60))->purchasesArrived($here, [1], '2026-11-16'), '«Eingegangen am» is not overwritten');
        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => ['50' => '2026-11-16']]], (new InboundChanges(50, 51))->purchasesArrived($order, [1], '2026-11-16'), 'without the field: the order as before');
    }

    #[Test]
    public function stepping_back_leaves_the_other_purchases_arrived(): void
    {
        $order = new InboundOrder(7, null, null, '2026-11-16', [], [], false, false, [1 => PurchaseStatus::Arrived, 2 => PurchaseStatus::Arrived]);

        self::assertSame([
            ['op' => 'set_values', 'record' => '1', 'values' => ['60' => '']],
            ['op' => 'set_values', 'record' => '2', 'values' => ['60' => '2026-11-16']],
        ], (new InboundChanges(50, 51, 60))->purchasesNotArrived($order, [1], []), 'the order date alone spoke: the other gets it as its own');
        self::assertSame([['op' => 'set_values', 'record' => '1', 'values' => ['60' => '']]], (new InboundChanges(50, 51, 60))->purchasesNotArrived($order, [1], [1 => '2026-11-16', 2 => '2026-11-15']));
        self::assertSame([
            ['op' => 'set_values', 'record' => '1', 'values' => ['60' => '']],
            ['op' => 'set_values', 'record' => '7', 'values' => ['50' => '']],
        ], (new InboundChanges(50, 51, 60))->purchasesNotArrived($order, [1], [1 => '2026-11-16']), 'the last own day goes: the order date would speak for all again, it goes too');
        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => ['50' => '']]], (new InboundChanges(50, 51, 60))->purchasesNotArrived($order, [1, 2], []), 'all of them: the order steps back');
        self::assertSame([
            ['op' => 'set_values', 'record' => '7', 'values' => ['50' => '']],
            ['op' => 'set_values', 'record' => '2', 'values' => ['60' => '']],
        ], (new InboundChanges(50, 51, 60))->notArrived($order, 0, [2 => '2026-11-15']));
    }
}
