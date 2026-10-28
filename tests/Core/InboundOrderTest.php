<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\InboundChanges;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;

/** D-1000: the stage of an incoming order follows from what mails and APIs wrote to it; the buttons write only the order. */
final class InboundOrderTest extends TestCase
{
    #[Test]
    public function the_stage_follows_from_status_arrival_and_tracking_in_that_order(): void
    {
        $s = $this->structure();

        self::assertSame(InboundStage::Ordered, (new InboundOrder(1, null, '2026-10-01', null, []))->stage($s));
        self::assertSame(InboundStage::Shipped, (new InboundOrder(1, null, '2026-10-01', null, ['00340434']))->stage($s));
        self::assertSame(InboundStage::Arrived, (new InboundOrder(1, null, '2026-10-01', '2026-10-05', ['00340434']))->stage($s), 'a delivery mail wrote «Eingegangen am»');
        self::assertSame(InboundStage::Arrived, (new InboundOrder(1, null, null, null, ['A1'], ['A1' => 'delivered']))->stage($s), 'DHL delivered counts as arrived');
        self::assertSame(InboundStage::Shipped, (new InboundOrder(1, null, null, null, ['A1'], ['A1' => 'shipped']))->stage($s));
        self::assertSame(InboundStage::Arrived, (new InboundOrder(1, 205, null, null, []))->stage($s), 'a partial receipt stays to check');
        self::assertSame(InboundStage::Booked, (new InboundOrder(1, 206, null, '2026-10-05', ['A1']))->stage($s));
        self::assertSame(InboundStage::None, (new InboundOrder(1, 209, null, null, []))->stage($s), 'draft');
        self::assertSame(InboundStage::None, (new InboundOrder(1, 210, null, '2026-10-05', []))->stage($s), 'cancelled');
    }

    #[Test]
    public function without_the_optional_status_values_a_draft_counts_as_ordered(): void
    {
        self::assertSame(InboundStage::Ordered, (new InboundOrder(1, 209, null, null, []))->stage(Fixture::structure()));
    }

    #[Test]
    public function an_order_is_overdue_only_while_ordered_or_shipped_for_too_long(): void
    {
        $s = $this->structure();

        self::assertFalse((new InboundOrder(1, null, '2026-10-14', null, []))->overdue($s, '2026-10-28'));
        self::assertTrue((new InboundOrder(1, null, '2026-10-13', null, []))->overdue($s, '2026-10-28'));
        self::assertFalse((new InboundOrder(1, null, '2026-10-07', null, ['X']))->overdue($s, '2026-10-28'));
        self::assertTrue((new InboundOrder(1, null, '2026-10-06 10:00:00', null, ['X']))->overdue($s, '2026-10-28'));
        self::assertFalse((new InboundOrder(1, null, '2026-01-01', '2026-01-09', ['X']))->overdue($s, '2026-10-28'), 'arrived is never overdue');
        self::assertFalse((new InboundOrder(1, null, null, null, []))->overdue($s, '2026-10-28'), 'no date, no verdict');
    }

    #[Test]
    public function arrived_sets_the_date_only_where_there_is_none(): void
    {
        $changes = (new InboundChanges(50, 51))->arrived([new InboundOrder(7, null, null, null, []), new InboundOrder(8, null, null, '2026-10-01', [])], '2026-10-28');

        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => ['50' => '2026-10-28']]], $changes);
    }

    #[Test]
    public function back_empties_the_date_but_not_once_a_purchase_is_booked(): void
    {
        $order = new InboundOrder(7, null, null, '2026-10-01', []);

        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => ['50' => '']]], (new InboundChanges(50, 51))->notArrived($order, 0));

        $this->expectException(StockError::class);
        (new InboundChanges(50, 51))->notArrived($order, 1);
    }

    #[Test]
    public function a_tracking_number_is_added_once_whatever_its_spacing(): void
    {
        $order = new InboundOrder(7, null, null, null, ['00340434 1234']);
        $c     = new InboundChanges(50, 51);

        self::assertSame([], $c->tracking($order, '003404341234'));
        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => ['51' => ['values' => ['00340434 1234', 'LP00123CN']]]]], $c->tracking($order, ' lp-00123-cn '));

        $this->expectException(StockError::class);
        $c->tracking($order, ' - ');
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['values'] += ['status_draft' => 209, 'status_cancelled' => 210];

        return Structure::fromArray($raw);
    }
}
