<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** One booking is one change list: lots and lines first, the Vorgang naming its lines last. */
final class BookingsTest extends TestCase
{
    #[Test]
    public function a_count_creates_a_new_material_a_lot_without_purchase_and_a_counted_line(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(null, 'BC547C', 3912, '40', '0,05', 'Fach 3')], 'Inventur');

        $values = $this->valuesByNode($changes);

        self::assertArrayNotHasKey((string) $s->field('label'), $values[1], 'D-967: a material with a Bezug has no name of its own');
        self::assertSame('0', $values[1][(string) $s->field('material_single')]);
        self::assertSame('3912', $values[1][(string) $s->field('material_ref_record')]);
        self::assertSame('BC547C', $values[2][(string) $s->field('label')], 'the lot keeps the row name');
        self::assertArrayNotHasKey((string) $s->field('lot_purchase'), $values[2]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '0.05', (string) $s->field('money_unit') => '208']], $values[2][(string) $s->field('lot_value')]);
        self::assertSame('40', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame('40', $values[4][(string) $s->field('line_counted')]);
        self::assertSame('203', $values[3][(string) $s->field('transaction_kind')]);
        self::assertSame(['values' => ['@p3']], $values[3][(string) $s->field('transaction_lines')]);
    }

    #[Test]
    public function a_count_row_without_a_bezug_keeps_its_name_and_one_without_either_is_refused(): void
    {
        $s      = Fixture::structure();
        $values = $this->valuesByNode((new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(null, 'Lötzinn', null, '1', null, '')], 'Inventur'));

        self::assertSame('Lötzinn', $values[1][(string) $s->field('label')]);
        self::assertArrayNotHasKey((string) $s->field('material_ref_record'), $values[1]);

        $this->expectException(StockError::class);
        (new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(null, '', null, '1', null, '')], 'Inventur');
    }

    #[Test]
    public function an_undetermined_purchase_is_not_received(): void
    {
        [$orders, $purchases] = $this->order(['1', '0']);

        $this->expectException(StockError::class);
        (new Bookings(Fixture::structure(), '2026-10-21'))->receipt(70, [71 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), new Table([]), [], 'WE');
    }

    #[Test]
    public function a_receipt_into_an_existing_material_writes_it_into_the_purchase(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);
        $materials            = new Table([55 => [$s->field('material_single') => ['0']]]);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [71 => ReceiptChoice::existing(55), 72 => ReceiptChoice::existing(55)], $orders, $purchases, $materials, new Table([]), [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame(['values' => ['55']], $this->purchaseMaterial($changes, 71));
        self::assertSame(['values' => ['55']], $this->purchaseMaterial($changes, 72), 'every purchase of the receipt, not only the first');
        self::assertSame('55', $values[2][(string) $s->field('lot_material')]);
        self::assertCount(0, array_filter($changes, static fn (array $c): bool => $c['op'] === 'create_record' && $c['node'] === 1), 'no new material');
    }

    #[Test]
    public function a_receipt_without_a_matching_material_creates_one_with_the_model_as_bezug(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::create(null)], $orders, $purchases, new Table([]), new Table([]), [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame('43101', $values[1][(string) $s->field('material_ref_record')], 'Bezug = Modell of the purchase');
        self::assertSame('0', $values[1][(string) $s->field('material_single')]);
        self::assertArrayNotHasKey((string) $s->field('label'), $values[1]);
        self::assertSame(['values' => ['@m1']], $this->purchaseMaterial($changes, 72));
        self::assertSame('@m1', $values[2][(string) $s->field('lot_material')]);

        $chosen = $this->valuesByNode((new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::create(999)], $orders, $purchases, new Table([]), new Table([]), [], 'WE'));
        self::assertSame('999', $chosen[1][(string) $s->field('material_ref_record')], 'a Bezug the user typed wins over the Modell');
    }

    #[Test]
    public function a_receipt_puts_the_lot_where_the_choice_says_else_at_the_default_place(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $placed = $this->valuesByNode((new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::create(null)->at(3001)], $orders, $purchases, new Table([]), new Table([]), [], 'WE'));
        self::assertSame('3001', $placed[2][(string) $s->field('lot_location')], 'D-972: the chosen place');

        $plain = $this->valuesByNode((new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::create(null)], $orders, $purchases, new Table([]), new Table([]), [], 'WE'));
        self::assertSame('207', $plain[2][(string) $s->field('lot_location')], 'no choice: the default place');
    }

    #[Test]
    public function a_received_purchase_or_an_unknown_material_is_refused(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        try {
            (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), new Table([]), [72 => true], 'WE');
            self::fail('received twice');
        } catch (StockError $e) {
            self::assertSame('already_received', $e->reason);
        }

        $this->expectException(StockError::class);
        (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::existing(4711)], $orders, $purchases, new Table([]), new Table([]), [], 'WE');
    }

    #[Test]
    public function a_partial_receipt_marks_the_order_partly_received_and_copies_the_price(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);
        $money                = new Table([80 => [$s->field('money_amount') => ['1.8'], $s->field('money_unit') => ['208']]]);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), $money, [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame('72', $values[2][(string) $s->field('lot_purchase')]);
        self::assertSame('5', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '1.8', (string) $s->field('money_unit') => '208']], $values[2][(string) $s->field('lot_value')]);
        self::assertSame('205', $this->orderStatus($changes), 'partly received');

        self::assertNull($this->purchaseMaterial($changes, 72), '«keep» leaves the purchase as it is');

        $all = (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), $money, [71 => true], 'WE');
        self::assertSame('206', $this->orderStatus($all), 'the other purchase came earlier');
    }

    #[Test]
    public function a_consumption_books_a_negative_line_on_the_chosen_lot(): void
    {
        $s       = Fixture::structure();
        $values  = $this->valuesByNode((new Bookings($s, '2026-10-21'))->consumption(11, '2,5', 18507, 'Verbrauch'));

        self::assertSame('-2.5', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame('11', $values[4][(string) $s->field('line_lot')]);
        self::assertSame('18507', $values[3][(string) $s->field('transaction_project')]);
    }

    /** @return array{0: Table, 1: Table} */
    private function order(array $undetermined): array
    {
        $s = Fixture::structure();

        return [
            new Table([70 => [$s->field('order_lines') => ['71', '72']]]),
            new Table([
                71 => [$s->field('label') => ['Kauf A'], $s->field('purchase_undetermined') => [$undetermined[0]], $s->field('purchase_material') => ['50']],
                72 => [$s->field('label') => ['Kauf B'], $s->field('purchase_undetermined') => [$undetermined[1]], $s->field('purchase_material') => ['60'],
                    $s->field('purchase_quantity') => ['5'], $s->field('purchase_unit_price') => ['80'], $s->field('purchase_model') => ['43101']],
            ]),
        ];
    }

    /**
     * The values set on the record created in each node (last one wins), keyed by node id.
     *
     * @param list<array<string, mixed>> $changes
     * @return array<int, array<string, mixed>>
     */
    private function valuesByNode(array $changes): array
    {
        $nodeOf = [];
        $out    = [];

        foreach ($changes as $c) {
            if ($c['op'] === 'create_record') {
                $nodeOf['@' . $c['ref']] = $c['node'];
            } elseif ($c['op'] === 'set_values' && isset($nodeOf[$c['record']])) {
                $out[$nodeOf[$c['record']]] = $c['values'];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, list<string>>|null
     */
    private function purchaseMaterial(array $changes, int $purchase): ?array
    {
        foreach ($changes as $c) {
            if ($c['op'] === 'set_values' && $c['record'] === $purchase) {
                return $c['values'][(string) Fixture::structure()->field('purchase_material')] ?? null;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $changes */
    private function orderStatus(array $changes): string
    {
        foreach ($changes as $c) {
            if ($c['op'] === 'set_values' && $c['record'] === 70) {
                return $c['values'][(string) Fixture::structure()->field('order_status')];
            }
        }

        return '';
    }
}
