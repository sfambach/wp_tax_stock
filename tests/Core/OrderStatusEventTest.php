<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\OrderStatusEvent;
use TaxmodStock\Core\Purchasing;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1038: the inbox's «versendet» / «zugestellt» moves the order status forward, never back, once. */
final class OrderStatusEventTest extends TestCase
{
    private const ORDERED   = 210;
    private const SHIPPED   = 212;
    private const DELIVERED = 213;

    #[Test]
    public function shipped_moves_an_ordered_order_forward_once(): void
    {
        $s       = self::structure();
        $event   = new OrderStatusEvent($s);
        $changes = $event->changes(7, self::ORDERED, OrderStatusEvent::SHIPPED);

        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => [(string) $s->field('order_status') => (string) self::SHIPPED]]], $changes);
        self::assertSame([], $event->changes(7, self::SHIPPED, OrderStatusEvent::SHIPPED), 'the same message twice writes nothing');
        self::assertNotSame([], $event->changes(7, 0, OrderStatusEvent::SHIPPED), 'an order without status counts as ordered');
    }

    #[Test]
    public function delivered_follows_ordered_or_shipped_and_nothing_goes_back(): void
    {
        $event = new OrderStatusEvent(self::structure());

        self::assertNotSame([], $event->changes(7, self::SHIPPED, OrderStatusEvent::DELIVERED));
        self::assertNotSame([], $event->changes(7, self::ORDERED, OrderStatusEvent::DELIVERED));
        self::assertSame([], $event->changes(7, self::DELIVERED, OrderStatusEvent::SHIPPED), 'never back');

        foreach ([205, 206, 209, 211] as $closed) {
            self::assertSame([], $event->changes(7, $closed, OrderStatusEvent::SHIPPED), 'partial, received, draft, cancelled keep their status');
            self::assertSame([], $event->changes(7, $closed, OrderStatusEvent::DELIVERED));
        }

        self::assertSame([], $event->changes(7, self::ORDERED, 'lost'), 'an unknown event writes nothing');
    }

    #[Test]
    public function without_the_values_in_the_model_nothing_is_written(): void
    {
        $raw = Fixture::structure()->toArray();
        $raw['values'] += ['status_ordered' => self::ORDERED];

        self::assertSame([], (new OrderStatusEvent(Structure::fromArray($raw)))->changes(7, self::ORDERED, OrderStatusEvent::SHIPPED));
    }

    #[Test]
    public function the_statuses_show_as_stages_and_still_count_as_ordered(): void
    {
        $s = self::structure();

        self::assertSame(InboundStage::Shipped, (new InboundOrder(1, self::SHIPPED, '2026-11-10', null, []))->stage($s), 'shipped without a tracking number');
        self::assertSame(InboundStage::Arrived, (new InboundOrder(1, self::DELIVERED, '2026-11-10', null, []))->stage($s));

        $orders = new Table([7 => [$s->field('order_status') => [(string) self::SHIPPED]]]);
        self::assertTrue((new Purchasing($s, '2026-11-13'))->isOrdered(7, $orders));
    }

    private static function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['values'] += ['status_draft' => 209, 'status_ordered' => self::ORDERED, 'status_cancelled' => 211, 'status_shipped' => self::SHIPPED, 'status_delivered' => self::DELIVERED];

        return Structure::fromArray($raw);
    }
}
