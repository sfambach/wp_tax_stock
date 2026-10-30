<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Purchasing;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1027: demand without a supplier → source per position → an order per supplier, each step with its way back. */
final class PurchasingTest extends TestCase
{
    private const DEMAND = 60;
    private const DRAFT  = 61;
    private const ORDER  = 62;

    #[Test]
    public function the_proposed_quantity_fills_up_to_the_minimum_and_is_at_least_one(): void
    {
        self::assertSame(1, Purchasing::proposedQuantity(null, 0.0, 0.0));
        self::assertSame(7, Purchasing::proposedQuantity(10.0, 2.0, 1.0));
        self::assertSame(1, Purchasing::proposedQuantity(5.0, 8.0, 0.0));
    }

    #[Test]
    public function reorder_into_a_new_demand_creates_a_draft_without_merchant_and_one_purchase_per_thing(): void
    {
        $s       = self::structure();
        $changes = (new Purchasing($s, '2026-10-30'))->reorder([3912 => ['label' => 'BC547C', 'quantity' => '5']], null, 'Kiselev-Backplane', $this->orders(), $this->purchases());

        self::assertSame(['op' => 'create_record', 'ref' => 'b1', 'node' => 6], $changes[0]);
        self::assertSame([(string) $s->field('label') => 'Kiselev-Backplane', (string) $s->field('order_status') => '209'], $changes[1]['values'], 'no merchant: a demand');
        self::assertSame('3912', $changes[3]['values'][(string) $s->field('purchase_model')]);
        self::assertSame('5', $changes[3]['values'][(string) $s->field('purchase_quantity')]);
        self::assertSame(['values' => ['@k2']], end($changes)['values'][(string) $s->field('order_lines')]);
    }

    #[Test]
    public function reorder_of_a_thing_already_in_the_demand_raises_its_quantity(): void
    {
        $s       = self::structure();
        $changes = (new Purchasing($s, '2026-10-30'))->reorder([3912 => ['label' => 'BC547C', 'quantity' => '2']], self::DEMAND, '', $this->orders(), $this->purchases());

        self::assertSame([['op' => 'set_values', 'record' => '70', 'values' => [(string) $s->field('purchase_quantity') => '5']]], $changes);
    }

    #[Test]
    public function reorder_into_a_supplier_draft_or_without_a_name_is_refused(): void
    {
        $p = new Purchasing(self::structure(), '2026-10-30');

        try {
            $p->reorder([1 => ['label' => 'x', 'quantity' => '1']], self::DRAFT, '', $this->orders(), $this->purchases());
            self::fail('a supplier draft is no demand');
        } catch (StockError $e) {
            self::assertSame('not_a_demand', $e->reason);
        }

        $this->expectException(StockError::class);
        $p->reorder([1 => ['label' => 'x', 'quantity' => '1']], null, '  ', $this->orders(), $this->purchases());
    }

    #[Test]
    public function choosing_a_source_moves_the_position_into_the_suppliers_open_draft_and_keeps_the_demand(): void
    {
        $s       = self::structure();
        $changes = (new Purchasing($s, '2026-10-30'))->source(self::DEMAND, 70, 'lcsc', 'C8550', '0,02', $this->orders(), $this->purchases());

        self::assertSame((string) self::DEMAND, $changes[0]['values'][(string) $s->field('purchase_demand')]);
        self::assertSame('C8550', $changes[0]['values'][(string) $s->field('purchase_number')]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '0.02', (string) $s->field('money_unit') => '208']], $changes[0]['values'][(string) $s->field('purchase_unit_price')]);
        self::assertSame(['values' => ['71']], $changes[1]['values'][(string) $s->field('order_lines')], 'the demand keeps the rest');
        self::assertSame((string) self::DRAFT, $changes[2]['record'], 'the open LCSC draft, matched without case');
        self::assertSame(['values' => ['72', '70']], $changes[2]['values'][(string) $s->field('order_lines')]);
    }

    #[Test]
    public function a_source_without_an_open_draft_creates_one_named_after_supplier_and_demand(): void
    {
        $s       = self::structure();
        $changes = (new Purchasing($s, '2026-10-30'))->source(self::DEMAND, 71, 'Reichelt', '', null, $this->orders(), $this->purchases());

        self::assertSame('create_record', $changes[0]['op']);
        self::assertSame('Reichelt · Kiselev', $changes[1]['values'][(string) $s->field('label')]);
        self::assertSame('Reichelt', $changes[1]['values'][(string) $s->field('order_merchant')]);
        self::assertSame(['values' => ['71']], end($changes)['values'][(string) $s->field('order_lines')]);
    }

    #[Test]
    public function unsource_brings_the_position_back_and_cancels_an_emptied_draft(): void
    {
        $s       = self::structure();
        $changes = (new Purchasing($s, '2026-10-30'))->unsource(self::DRAFT, 72, $this->orders(), $this->purchases());

        self::assertSame(['values' => ['70', '71', '72']], $changes[0]['values'][(string) $s->field('order_lines')]);
        self::assertSame(['values' => []], $changes[1]['values'][(string) $s->field('order_lines')]);
        self::assertSame('211', $changes[1]['values'][(string) $s->field('order_status')]);
    }

    #[Test]
    public function marking_ordered_sets_status_number_and_date_and_back_to_draft_undoes_it(): void
    {
        $s = self::structure();
        $p = new Purchasing($s, '2026-10-30');

        self::assertSame([['op' => 'set_values', 'record' => '61', 'values' => [
            (string) $s->field('order_status') => '210',
            (string) $s->field('order_number') => 'WM2610300001',
        ]]], $p->markOrdered(self::DRAFT, ' WM2610300001 ', $this->orders()));

        self::assertSame([['op' => 'set_values', 'record' => '62', 'values' => [(string) $s->field('order_status') => '209']]], $p->backToDraft(self::ORDER, $this->orders(), ''));

        $this->expectException(StockError::class);
        $p->backToDraft(self::ORDER, $this->orders(), '2026-11-02');
    }

    #[Test]
    public function open_quantities_count_drafts_and_ordered_orders(): void
    {
        $open = (new Purchasing(self::structure(), '2026-10-30'))->openQuantities($this->orders(), $this->purchases());

        self::assertSame([3912 => 3.0, 4000 => 1.0, 4100 => 10.0, 4200 => 4.0], $open);
    }

    #[Test]
    public function the_cart_lists_article_number_and_quantity(): void
    {
        self::assertSame(['C1234 10'], (new Purchasing(self::structure(), '2026-10-30'))->cartLines(self::DRAFT, $this->orders(), $this->purchases()));
    }

    private function orders(): Table
    {
        $s = self::structure();
        [$label, $status, $merchant, $lines] = [$s->field('label'), $s->field('order_status'), $s->field('order_merchant'), $s->field('order_lines')];

        return new Table([
            self::DEMAND => [$label => ['Kiselev'], $status => ['209'], $lines => ['70', '71']],
            self::DRAFT  => [$label => ['LCSC · Kiselev'], $status => ['209'], $merchant => ['LCSC'], $lines => ['72']],
            self::ORDER  => [$label => ['Mouser'], $status => ['210'], $merchant => ['Mouser'], $lines => ['73']],
        ]);
    }

    private function purchases(): Table
    {
        $s = self::structure();
        [$label, $model, $quantity, $number, $demand] = [$s->field('label'), $s->field('purchase_model'), $s->field('purchase_quantity'), $s->field('purchase_number'), $s->field('purchase_demand')];

        return new Table([
            70 => [$label => ['BC547C'], $model => ['3912'], $quantity => ['3']],
            71 => [$label => ['L7805'], $model => ['4000'], $quantity => ['1']],
            72 => [$label => ['1N4148'], $model => ['4100'], $quantity => ['10'], $number => ['C1234'], $demand => ['60']],
            73 => [$label => ['NE555'], $model => ['4200'], $quantity => ['4']],
        ]);
    }

    /** The fixture with the order statuses purchasing needs. */
    private static function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['values'] += ['status_draft' => 209, 'status_ordered' => 210, 'status_cancelled' => 211];

        return Structure::fromArray($raw);
    }
}
