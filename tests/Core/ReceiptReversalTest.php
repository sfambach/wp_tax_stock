<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\ReceiptReversal;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** D-1009: a goods receipt goes back out of stock only while nothing else has touched its lots. */
final class ReceiptReversalTest extends TestCase
{
    #[Test]
    public function the_receipt_its_lines_and_lots_go_and_the_order_loses_its_status(): void
    {
        $s = Fixture::structure();
        [$orders, $lots, $transactions] = $this->booked();
        $lines = new Table([80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1']]]);

        self::assertSame([
            ['op' => 'remove_record', 'record' => '90'],
            ['op' => 'remove_record', 'record' => '80'],
            ['op' => 'remove_record', 'record' => '60'],
            ['op' => 'set_values', 'record' => '70', 'values' => [(string) $s->field('order_status') => '']],
        ], (new ReceiptReversal($s))->reverse(70, $orders, $lots, $lines, $transactions));
    }

    #[Test]
    public function as_a_composition_the_lines_go_with_their_receipt_and_are_not_removed_after_it(): void
    {
        $s = Fixture::composed();
        [$orders, $lots, $transactions] = $this->booked();
        $lines = new Table([80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1']]]);

        // D-1097/D-1099: the cascade takes line 80 with Vorgang 90; a second removal of it would find nothing and refuse the list.
        self::assertSame([
            ['op' => 'remove_record', 'record' => '90'],
            ['op' => 'remove_record', 'record' => '60'],
            ['op' => 'set_values', 'record' => '70', 'values' => [(string) $s->field('order_status') => '']],
        ], (new ReceiptReversal($s))->reverse(70, $orders, $lots, $lines, $transactions));
    }

    #[Test]
    public function a_lot_with_a_consumption_keeps_the_receipt(): void
    {
        $s = Fixture::structure();
        [$orders, $lots, $transactions] = $this->booked();
        $lines = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1']],
            81 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-1']],
        ]);

        $this->expectException(StockError::class);
        (new ReceiptReversal($s))->reverse(70, $orders, $lots, $lines, $transactions);
    }

    #[Test]
    public function a_lot_only_moved_goes_back_with_its_umbuchung(): void
    {
        $s = Fixture::structure();
        [$orders, $lots] = $this->booked();
        $transactions = $this->moved($s);

        // His report 2026-11-05: a card moved once, not consumed — the Umbuchung 92 holds only this lot and goes whole.
        self::assertSame([
            ['op' => 'remove_record', 'record' => '92'],
            ['op' => 'remove_record', 'record' => '82'],
            ['op' => 'remove_record', 'record' => '83'],
            ['op' => 'remove_record', 'record' => '90'],
            ['op' => 'remove_record', 'record' => '80'],
            ['op' => 'remove_record', 'record' => '60'],
            ['op' => 'set_values', 'record' => '70', 'values' => [(string) $s->field('order_status') => '']],
        ], (new ReceiptReversal($s))->reverse(70, $orders, $lots, $this->movedLines($s), $transactions));
    }

    #[Test]
    public function an_umbuchung_with_other_lots_keeps_them_and_drops_this_lots_lines(): void
    {
        $s = Fixture::structure();
        [$orders, $lots] = $this->booked();
        $transactions = $this->moved($s, ['82', '83', '84']);

        self::assertSame([
            ['op' => 'set_values', 'record' => '92', 'values' => [(string) $s->field('transaction_lines') => ['values' => ['84']]]],
            ['op' => 'remove_record', 'record' => '82'],
            ['op' => 'remove_record', 'record' => '83'],
        ], array_slice((new ReceiptReversal($s))->reverse(70, $orders, $lots, $this->movedLines($s), $transactions), 0, 3));

        $composed = Fixture::composed();
        self::assertSame([
            ['op' => 'remove_record', 'record' => '82'],
            ['op' => 'remove_record', 'record' => '83'],
            ['op' => 'remove_record', 'record' => '90'],
        ], array_slice((new ReceiptReversal($composed))->reverse(70, $orders, $lots, $this->movedLines($s), $this->moved($composed, ['82', '83', '84'])), 0, 3));
    }

    #[Test]
    public function an_umbuchung_that_does_not_net_to_zero_keeps_the_receipt(): void
    {
        $s = Fixture::structure();
        [$orders, $lots] = $this->booked();
        $lines = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['2']],
            82 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-1']],
        ]);

        $this->expectException(StockError::class);
        (new ReceiptReversal($s))->reverse(70, $orders, $lots, $lines, $this->moved($s, ['82']));
    }

    #[Test]
    public function a_consumption_taken_back_by_storno_does_not_keep_the_receipt(): void
    {
        $s = Fixture::structure();
        [$orders, $lots, $transactions] = $this->booked();
        $lines = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1']],
            81 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-1']],
            85 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1'], $s->field('line_reverses') => ['81']],
        ]);

        $changes = (new ReceiptReversal($s))->reverse(70, $orders, $lots, $lines, $transactions);
        self::assertContains(['op' => 'remove_record', 'record' => '91'], $changes);
        self::assertContains(['op' => 'remove_record', 'record' => '85'], $changes);
        self::assertContains(['op' => 'remove_record', 'record' => '60'], $changes);
    }

    #[Test]
    public function an_order_without_lots_has_nothing_to_unbook(): void
    {
        [$orders, , $transactions] = $this->booked();

        $this->expectException(StockError::class);
        (new ReceiptReversal(Fixture::structure()))->reverse(70, $orders, new Table([]), new Table([]), $transactions);
    }

    /** @param list<string> $lines @return Table the receipt 90 and an Umbuchung 92 */
    private function moved(\TaxmodStock\Core\Structure $s, array $lines = ['82', '83']): Table
    {
        return new Table([
            90 => [$s->field('transaction_order') => ['70'], $s->field('transaction_kind') => ['201'], $s->field('transaction_lines') => ['80']],
            92 => [$s->field('transaction_kind') => ['209'], $s->field('transaction_lines') => $lines],
        ]);
    }

    private function movedLines(\TaxmodStock\Core\Structure $s): Table
    {
        return new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1']],
            82 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-1']],
            83 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1']],
            84 => [$s->field('line_lot') => ['61'], $s->field('line_quantity') => ['1']],
        ]);
    }

    /** @return array{Table, Table, Table} order 70 with purchase 71, its lot 60 and receipt 90 holding line 80 */
    private function booked(): array
    {
        $s = Fixture::structure();

        return [
            new Table([70 => [$s->field('order_lines') => ['71'], $s->field('order_status') => ['206']]]),
            new Table([60 => [$s->field('lot_purchase') => ['71']], 61 => [$s->field('lot_purchase') => ['99']]]),
            new Table([
                90 => [$s->field('transaction_order') => ['70'], $s->field('transaction_kind') => ['201'], $s->field('transaction_lines') => ['80']],
                91 => [$s->field('transaction_order') => ['70'], $s->field('transaction_kind') => ['202'], $s->field('transaction_lines') => ['81']],
            ]),
        ];
    }
}
