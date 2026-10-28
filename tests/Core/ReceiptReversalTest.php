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
    public function an_order_without_lots_has_nothing_to_unbook(): void
    {
        [$orders, , $transactions] = $this->booked();

        $this->expectException(StockError::class);
        (new ReceiptReversal(Fixture::structure()))->reverse(70, $orders, new Table([]), new Table([]), $transactions);
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
