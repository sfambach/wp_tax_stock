<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\NotStocked;
use TaxmodStock\Core\ReceiptPlan;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** D-1058: a purchase closed without stock keeps its reason, counts as done, and can be taken back. */
final class NotStockedTest extends TestCase
{
    #[Test]
    public function the_last_open_purchase_closed_makes_the_order_received(): void
    {
        $s = Fixture::structure();

        self::assertSame([
            ['op' => 'set_values', 'record' => '72', 'values' => [(string) $s->field('purchase_not_stocked') => 'privat']],
            ['op' => 'set_values', 'record' => '70', 'values' => [(string) $s->field('order_status') => (string) $s->value('status_received')]],
        ], (new NotStocked($s))->close(70, 72, ' privat ', $this->orders(), new Table([]), [71 => true]));
    }

    #[Test]
    public function one_closed_before_anything_arrived_leaves_the_status(): void
    {
        $changes = (new NotStocked(Fixture::structure()))->close(70, 72, 'privat', $this->orders(), new Table([]), []);

        self::assertCount(1, $changes);
    }

    #[Test]
    public function one_closed_beside_one_booked_and_one_open_makes_the_order_partial(): void
    {
        $s      = Fixture::structure();
        $orders = new Table([70 => [$s->field('order_lines') => ['71', '72', '73']]]);
        $changes = (new NotStocked($s))->close(70, 72, 'privat', $orders, new Table([]), [71 => true]);

        self::assertSame((string) $s->value('status_partial'), $changes[1]['values'][(string) $s->field('order_status')]);
    }

    #[Test]
    public function an_order_closed_in_every_purchase_goes_to_the_archive(): void
    {
        $s = Fixture::structure();

        self::assertTrue(NotStocked::wholeOrder($s, $this->orders(), 70, [], [71 => true, 72 => true]));
        self::assertFalse(NotStocked::wholeOrder($s, $this->orders(), 70, [71 => true], [72 => true]));
        self::assertSame(InboundStage::Archived, (new InboundOrder(70, (int) $s->value('status_received'), '2026-11-01', null, [], [], true))->stage($s));
    }

    #[Test]
    public function taking_it_back_empties_the_reason_and_the_status(): void
    {
        $s         = Fixture::structure();
        $purchases = new Table([72 => [$s->field('purchase_not_stocked') => ['privat']]]);

        self::assertSame([
            ['op' => 'set_values', 'record' => '72', 'values' => [(string) $s->field('purchase_not_stocked') => '']],
            ['op' => 'set_values', 'record' => '70', 'values' => [(string) $s->field('order_status') => '']],
        ], (new NotStocked($s))->reopen(70, 72, $this->orders(), $purchases, []));
    }

    #[Test]
    public function a_reason_is_needed(): void
    {
        $this->expectException(StockError::class);
        (new NotStocked(Fixture::structure()))->close(70, 72, ' ', $this->orders(), new Table([]), []);
    }

    #[Test]
    public function a_received_purchase_cannot_be_closed(): void
    {
        $this->expectException(StockError::class);
        (new NotStocked(Fixture::structure()))->close(70, 71, 'privat', $this->orders(), new Table([]), [71 => true]);
    }

    #[Test]
    public function the_plan_locks_a_closed_purchase(): void
    {
        $s         = Fixture::structure();
        $purchases = new Table([72 => [$s->field('purchase_not_stocked') => ['privat']]]);
        $plan      = (new ReceiptPlan($s))->of(70, $this->orders(), $purchases, [], NotStocked::purchases($s, $purchases));

        self::assertSame('not_stocked', $plan[72]['lock']);
        self::assertNull($plan[71]['lock']);
    }

    private function orders(): Table
    {
        return new Table([70 => [Fixture::structure()->field('order_lines') => ['71', '72']]]);
    }
}
