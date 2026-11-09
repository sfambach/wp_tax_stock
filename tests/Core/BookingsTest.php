<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\LagerInput;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\Pack;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\SourceRow;
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
    public function a_total_value_without_a_value_per_piece_is_spread_over_the_counted_quantity(): void
    {
        $s      = Fixture::structure();
        $amount = (string) $s->field('money_amount');
        $total  = $this->valuesByNode((new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(3912, null, 'BC547C', '40', '', '', '2,00')], 'Inventur'));
        $both   = $this->valuesByNode((new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(3912, null, 'BC547C', '40', '0,10', '', '2,00')], 'Inventur'));
        $none   = $this->valuesByNode((new Bookings($s, '2026-10-21'))->inventory([new InventoryRow(3912, null, 'BC547C', '40', '', '', '')], 'Inventur'));

        self::assertSame('0.05', $total[2][(string) $s->field('lot_value')]['neu'][$amount], 'his word 2026-11-09: «Es wird dann umgerechnet»');
        self::assertSame('0.10', $both[2][(string) $s->field('lot_value')]['neu'][$amount], 'the value per piece wins');
        self::assertArrayNotHasKey((string) $s->field('lot_value'), $none[2]);
    }

    #[Test]
    public function as_a_composition_the_lines_are_parts_of_the_vorgang_naming_their_lots_by_ref(): void
    {
        $s       = Fixture::composed();
        $changes = (new Bookings($s, '2026-10-21'))->inventory([
            new InventoryRow(3912, null, 'BC547C', '40', null, ''),
            new InventoryRow(3913, null, 'BC557C', '10', null, ''),
        ], 'Inventur');
        $values  = $this->valuesByNode($changes);

        self::assertArrayNotHasKey($s->node('line'), $values, 'D-1097: no line is created on its own');
        self::assertSame(['p2', 'p4'], array_keys($values[3][(string) $s->field('transaction_lines')]), 'one part key per line, never «0»');
        self::assertSame(['@l1', '40', '40'], array_values(array_intersect_key(
            $values[3][(string) $s->field('transaction_lines')]['p2'],
            array_flip([(string) $s->field('line_lot'), (string) $s->field('line_quantity'), (string) $s->field('line_counted')])
        )), 'a part names the lot created before it in the same list');
        self::assertSame('@l3', $values[3][(string) $s->field('transaction_lines')]['p4'][(string) $s->field('line_lot')]);
    }

    #[Test]
    public function as_a_composition_a_receipt_puts_its_lines_into_its_vorgang(): void
    {
        $s                    = Fixture::composed();
        [$orders, $purchases] = $this->order(['0', '0']);
        $changes              = (new Bookings($s, '2026-10-21'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, new Table([]), [], 'WE');
        $deal                 = end($changes)['values'];

        self::assertSame([], array_filter($changes, static fn (array $c): bool => $c['op'] === 'create_record' && $c['node'] === $s->node('line')));
        self::assertSame('@l1', $deal[(string) $s->field('transaction_lines')]['p2'][(string) $s->field('line_lot')]);
        self::assertSame('70', $deal[(string) $s->field('transaction_order')]);
    }

    #[Test]
    public function a_receipt_of_packs_books_the_pieces_at_the_price_of_one_piece(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);
        $money                = new Table([80 => [$s->field('money_amount') => ['4'], $s->field('money_unit') => ['208']]]);
        $values               = $this->valuesByNode((new Bookings($s, '2026-11-05'))->receipt(70, [72 => ReceiptChoice::keep()], $orders, $purchases, $money, [], 'WE', [43101 => new Pack('Leiste', 40)]));
        $lot                  = $values[$s->node('lot')];

        self::assertSame('200', $values[$s->node('line')][(string) $s->field('line_quantity')], 'D-1178: 5 strips of 40 are 200 pins');
        self::assertSame('0.1', $lot[(string) $s->field('lot_value')]['neu'][(string) $s->field('money_amount')], 'the price of one pin');
        self::assertSame([5, 12.0], (new Pack('Leiste', 40))->split(212.0));
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
    public function a_new_thing_carries_the_fields_asked_in_its_dialog(): void
    {
        $s      = Fixture::structure();
        $values = $this->valuesByNode((new Bookings($s, '2026-11-09'))->inventory([new InventoryRow(null, new NewThing(3640, 'CH340N', null, [555 => '777']), 'CH340N', '1', null, '')], 'Inventur'));

        self::assertSame('777', $values[3640]['555'], 'D-1255: the manufacturer chosen in the dialog goes into the new material');
        self::assertSame('CH340N', $values[3640][(string) $s->field('label')]);
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
        $values  = $this->valuesByNode((new Bookings($s, '2026-10-21'))->consumption(11, '2,5', Purpose::subject(18507), 'Verbrauch', null, 'Reparatur'));

        self::assertSame('-2.5', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame('11', $values[4][(string) $s->field('line_lot')]);
        // D-1088: the purpose stands in the Vorgang's Bezug, the reason in its description.
        self::assertSame('18507', $values[3][(string) $s->field('transaction_reference')]);
        self::assertArrayNotHasKey((string) $s->field('transaction_project'), $values[3]);
        self::assertSame('Reparatur', $values[3][(string) $s->field('description')]);
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
    public function as_a_composition_a_split_writes_the_further_purchases_as_parts_of_the_order(): void
    {
        $s                    = Fixture::composed();
        [$orders, $purchases] = $this->order(['1', '0']);

        $changes = (new Bookings($s, '2026-10-28'))->split(70, 71, [
            new SplitRow(55, null, 'Mainboard', '1', null),
            new SplitRow(null, new NewThing(3640, 'Netzteil AT'), 'Netzteil AT', '2', '4,50'),
        ], $orders, $purchases, new Table([]));
        $order   = end($changes);
        $part    = $order['values'][(string) $s->field('order_lines')]['k2'] ?? [];

        self::assertSame([], array_filter($changes, static fn (array $c): bool => $c['op'] === 'create_record' && $c['node'] === $s->node('purchase')), 'D-1097: no purchase of its own');
        self::assertSame(70, $order['record']);
        self::assertSame(['k2'], array_keys($order['values'][(string) $s->field('order_lines')]), 'only the new part; 71 and 72 stay where they are');
        self::assertSame('@d1', $part[(string) $s->field('purchase_model')], 'the part names the material created before it');
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '4.50', (string) $s->field('money_unit') => '208']], $part[(string) $s->field('purchase_unit_price')], 'its price a part of the part');
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

    #[Test]
    public function an_assignment_to_a_group_creates_the_material_there(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $changes = (new Bookings($s, '2026-11-01'))->assign(70, [71 => new NewThing(900, 'Lötpaste')], $orders, $purchases);

        self::assertSame('create_record', $changes[0]['op']);
        self::assertSame(900, $changes[0]['node']);
        self::assertSame([(string) $s->field('label') => 'Lötpaste'], $changes[1]['values']);
        self::assertSame('@' . $changes[0]['ref'], $changes[2]['values'][(string) $s->field('purchase_model')], 'D-1062: the purchase points at the new material');
    }

    #[Test]
    public function a_material_taken_away_clears_the_purchases_model(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);

        $changes = (new Bookings($s, '2026-11-01'))->assign(70, [72 => 0, 71 => 0], $orders, $purchases);

        self::assertCount(1, $changes, 'a purchase without a material stays as it is');
        self::assertSame(['op' => 'set_values', 'record' => 72, 'values' => [(string) $s->field('purchase_model') => '']], $changes[0]);
    }

    #[Test]
    public function a_new_material_comes_with_what_the_purchase_tells_about_its_lager_view(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);
        $lager                = new LagerInput(false, null, 5, null, [new SourceRow(null, 46727, 'Bestellung 123')]);

        $changes = (new Bookings($s, '2026-11-01'))->assign(70, [71 => new NewThing(900, 'Lötpaste', $lager)], $orders, $purchases);

        self::assertSame($s->node('lager'), $changes[2]['node'], 'D-1071: the Lager record is created with the material');
        self::assertSame('@' . $changes[0]['ref'], $changes[3]['values'][(string) $s->field('lager_thing')]);
        self::assertSame('5', $changes[3]['values'][(string) $s->field('lager_location')]);
        self::assertSame('@' . $changes[0]['ref'], $changes[4]['values'][(string) $s->field('purchase_model')]);
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
