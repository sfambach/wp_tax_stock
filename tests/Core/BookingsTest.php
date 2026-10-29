<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** One booking is one change list: lots and lines first, the Vorgang naming its lines last. */
final class BookingsTest extends TestCase
{
    #[Test]
    public function a_count_of_an_existing_thing_creates_a_lot_without_purchase_and_a_counted_line(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(3912, null, 'BC547C', '40', '0,05', 'Fach 3')], 'Inventur');

        $values = $this->valuesByNode($changes);

        self::assertArrayNotHasKey(3640, $values, 'no new thing');
        self::assertSame('3912', $values[2][(string) $s->field('lot_thing')], 'D-995: the lot belongs to the thing');
        self::assertSame('BC547C', $values[2][(string) $s->field('label')], 'the lot keeps the row name');
        self::assertArrayNotHasKey((string) $s->field('lot_purchase'), $values[2]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '0.05', (string) $s->field('money_unit') => '208']], $values[2][(string) $s->field('lot_value')]);
        self::assertSame('40', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame('40', $values[4][(string) $s->field('line_counted')]);
        self::assertSame('203', $values[3][(string) $s->field('transaction_kind')]);
        self::assertSame(['values' => ['@p2']], $values[3][(string) $s->field('transaction_lines')]);
    }

    #[Test]
    public function a_count_of_a_new_thing_creates_it_in_its_group_and_one_without_a_name_is_refused(): void
    {
        $s      = Fixture::structure();
        $values = $this->valuesByNode((new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(null, new NewThing(3640, 'Lötzinn'), 'Lötzinn', '1', null, '')], 'Inventur'));

        self::assertSame(['label' => 'Lötzinn'], [ 'label' => $values[3640][(string) $s->field('label')]], 'the thing is created in the group, named as typed');
        self::assertSame('@d1', $values[2][(string) $s->field('lot_thing')]);

        $this->expectException(StockError::class);
        new NewThing(3640, ' ');
    }

    #[Test]
    public function an_undetermined_purchase_is_not_received(): void
    {
        [$orders, $purchases] = $this->order(['1', '0']);

        $this->expectException(StockError::class);
        (new Bookings(Fixture::structure(), '2026-10-21'))->receipt(70, [71 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), [], 'WE');
    }

    #[Test]
    public function a_receipt_into_another_thing_writes_it_into_the_purchases_modell(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [71 => ReceiptChoice::existing(55), 72 => ReceiptChoice::existing(55)], $orders, $purchases, new Table([]), [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame('55', $this->purchaseModel($changes, 71));
        self::assertSame('55', $this->purchaseModel($changes, 72), 'every purchase of the receipt, not only the first');
        self::assertSame('55', $values[2][(string) $s->field('lot_thing')]);
    }

    #[Test]
    public function a_receipt_into_a_new_thing_creates_it_and_points_the_purchase_at_it(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [71 => ReceiptChoice::create(new NewThing(3640, 'Kontaktspray'))], $orders, $purchases, new Table([]), [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame('Kontaktspray', $values[3640][(string) $s->field('label')]);
        self::assertSame('@d1', $this->purchaseModel($changes, 71));
        self::assertSame('@d1', $values[2][(string) $s->field('lot_thing')]);
    }

    #[Test]
    public function a_receipt_puts_the_lot_where_the_choice_says_else_at_the_default_place(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $placed = $this->valuesByNode((new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()->at(3001)], $orders, $purchases, new Table([]), [], 'WE'));
        self::assertSame('3001', $placed[2][(string) $s->field('lot_location')], 'D-972: the chosen place');

        $plain = $this->valuesByNode((new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), [], 'WE'));
        self::assertSame('207', $plain[2][(string) $s->field('lot_location')], 'no choice: the default place');
    }

    #[Test]
    public function a_received_purchase_or_one_without_a_modell_kept_is_refused(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        try {
            (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), [72 => true], 'WE');
            self::fail('received twice');
        } catch (StockError $e) {
            self::assertSame('already_received', $e->reason);
        }

        $this->expectException(StockError::class);
        (new Bookings($s, '2026-10-21'))->receipt(70, [71 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), [], 'WE');
    }

    #[Test]
    public function a_partial_receipt_marks_the_order_partly_received_and_copies_the_price(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);
        $money                = new Table([80 => [$s->field('money_amount') => ['1.8'], $s->field('money_unit') => ['208']]]);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, $money, [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame('72', $values[2][(string) $s->field('lot_purchase')]);
        self::assertSame('5', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '1.8', (string) $s->field('money_unit') => '208']], $values[2][(string) $s->field('lot_value')]);
        self::assertSame('205', $this->orderStatus($changes), 'partly received');

        self::assertNull($this->purchaseModel($changes, 72), '«keep» leaves the purchase as it is');

        $all = (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, $money, [71 => true], 'WE');
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

    #[Test]
    public function a_lot_purchase_split_becomes_its_first_part_and_further_purchases_of_the_order(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['1', '0']);

        $changes = (new Bookings($s, '2026-10-28'))->split(70, 71, [
            new SplitRow(55, null, 'Mainboard', '1', null),
            new SplitRow(null, new NewThing(3640, 'Netzteil AT'), 'Netzteil AT', '2', '4,50'),
        ], $orders, $purchases, new Table([]));
        $values  = $this->valuesByNode($changes);
        $first   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && $c['record'] === 71))[0]['values'];
        $order   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && $c['record'] === 70))[0]['values'];

        self::assertSame('Mainboard', $first[(string) $s->field('label')], 'the lot purchase itself becomes the first part');
        self::assertSame('55', $first[(string) $s->field('purchase_model')]);
        self::assertSame('0', $first[(string) $s->field('purchase_undetermined')], 'now determined, so the receipt takes it');
        self::assertArrayNotHasKey((string) $s->field('purchase_unit_price'), $first, 'without a price the whole price stays on it');

        $added = $values[$s->node('purchase')];
        self::assertSame('@d1', $added[(string) $s->field('purchase_model')], 'a new material is created for its part');
        self::assertSame('2', $added[(string) $s->field('purchase_quantity')]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '4.50', (string) $s->field('money_unit') => '208']], $added[(string) $s->field('purchase_unit_price')]);
        self::assertSame(['values' => ['71', '72', '@k2']], $order[(string) $s->field('order_lines')], 'the new purchase joins the order');
        self::assertArrayNotHasKey($s->node('transaction'), $values, 'no booking: nothing is in stock before the receipt');
    }

    #[Test]
    public function a_split_divides_shipping_and_fees_by_the_value_of_the_parts_and_writes_no_landed_price(): void
    {
        $s       = Fixture::structure();
        $orders  = new Table([70 => [$s->field('order_lines') => ['71']]]);
        $lot     = new Table([71 => [
            $s->field('purchase_undetermined')   => ['1'],
            $s->field('purchase_unit_price')     => ['501'],
            $s->field('purchase_landed_price')   => ['502'],
            $s->field('purchase_shipping_share') => ['503'],
            $s->field('purchase_fees_share')     => ['504'],
        ]]);
        $money   = new Table([
            501 => [$s->field('money_amount') => ['30']],
            503 => [$s->field('money_amount') => ['10'], $s->field('money_unit') => ['208']],
            504 => [$s->field('money_amount') => ['0']],
        ]);
        $changes = (new Bookings($s, '2026-10-29'))->split(70, 71, [
            new SplitRow(55, null, 'Mainboard', '1', '20'),
            new SplitRow(56, null, 'Netzteil', '2', '5'),
        ], $orders, $lot, $money);
        $first   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && $c['record'] === 71))[0]['values'];
        $added   = $this->valuesByNode($changes)[$s->node('purchase')];
        $amount  = (string) $s->field('money_amount');

        self::assertArrayNotHasKey((string) $s->field('purchase_landed_price'), $first, 'the model calculates it (D-1022)');
        self::assertSame(['0' => [$amount => '6.67', (string) $s->field('money_unit') => '208']], $first[(string) $s->field('purchase_shipping_share')], 'value 20 of 30, plus the rounding');
        self::assertSame(['neu' => [$amount => '3.33', (string) $s->field('money_unit') => '208']], $added[(string) $s->field('purchase_shipping_share')]);
        self::assertArrayNotHasKey((string) $s->field('purchase_fees_share'), $first, 'a zero share stays as it is');
    }

    #[Test]
    public function only_an_undetermined_purchase_of_the_order_is_split(): void
    {
        [$orders, $purchases] = $this->order(['1', '0']);

        try {
            (new Bookings(Fixture::structure(), '2026-10-28'))->split(70, 72, [new SplitRow(55, null, 'X', '1', null)], $orders, $purchases, new Table([]));
            self::fail('a determined purchase was split');
        } catch (StockError $e) {
            self::assertSame('not_a_lot', $e->reason);
        }

        $this->expectException(StockError::class);
        (new Bookings(Fixture::structure(), '2026-10-28'))->split(70, 99, [new SplitRow(55, null, 'X', '1', null)], $orders, $purchases, new Table([]));
    }

    #[Test]
    public function an_assignment_keeps_the_material_in_the_purchase_and_books_nothing(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $changes = (new Bookings($s, '2026-10-28'))->assign(70, [71 => 55, 72 => 43101], $orders, $purchases);

        self::assertSame([['op' => 'set_values', 'record' => 71, 'values' => [(string) $s->field('purchase_model') => '55']]], $changes, 'only the changed purchase is written, and nothing is booked');

        $this->expectException(StockError::class);
        (new Bookings($s, '2026-10-28'))->assign(70, [99 => 55], $orders, $purchases);
    }

    /** @return array{0: Table, 1: Table} */
    private function order(array $undetermined): array
    {
        $s = Fixture::structure();

        return [
            new Table([70 => [$s->field('order_lines') => ['71', '72']]]),
            new Table([
                71 => [$s->field('label') => ['Kauf A'], $s->field('purchase_undetermined') => [$undetermined[0]]],
                72 => [$s->field('label') => ['Kauf B'], $s->field('purchase_undetermined') => [$undetermined[1]],
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

    /** @param list<array<string, mixed>> $changes */
    private function purchaseModel(array $changes, int $purchase): ?string
    {
        foreach ($changes as $c) {
            if ($c['op'] === 'set_values' && $c['record'] === $purchase) {
                return $c['values'][(string) Fixture::structure()->field('purchase_model')] ?? null;
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
