<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\InventoryRow;
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

        self::assertSame('BC547C', $values[1][(string) $s->field('label')]);
        self::assertSame('3912', $values[1][(string) $s->field('material_ref_record')]);
        self::assertArrayNotHasKey((string) $s->field('lot_purchase'), $values[2]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '0.05', (string) $s->field('money_unit') => '208']], $values[2][(string) $s->field('lot_value')]);
        self::assertSame('40', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame('40', $values[4][(string) $s->field('line_counted')]);
        self::assertSame('203', $values[3][(string) $s->field('transaction_kind')]);
        self::assertSame(['values' => ['@p3']], $values[3][(string) $s->field('transaction_lines')]);
    }

    #[Test]
    public function an_undetermined_purchase_is_not_received(): void
    {
        [$orders, $purchases] = $this->order(['1', '0']);

        $this->expectException(StockError::class);
        (new Bookings(Fixture::structure(), '2026-10-21'))->receipt(70, [71], $orders, $purchases, new Table([]), [], 'WE');
    }

    #[Test]
    public function a_partial_receipt_marks_the_order_partly_received_and_copies_the_price(): void
    {
        $s                    = Fixture::structure();
        [$orders, $purchases] = $this->order(['0', '0']);
        $money                = new Table([80 => [$s->field('money_amount') => ['1.8'], $s->field('money_unit') => ['208']]]);

        $changes = (new Bookings($s, '2026-10-21'))->receipt(70, [72], $orders, $purchases, $money, [], 'WE');
        $values  = $this->valuesByNode($changes);

        self::assertSame('72', $values[2][(string) $s->field('lot_purchase')]);
        self::assertSame('5', $values[4][(string) $s->field('line_quantity')]);
        self::assertSame(['neu' => [(string) $s->field('money_amount') => '1.8', (string) $s->field('money_unit') => '208']], $values[2][(string) $s->field('lot_value')]);
        self::assertSame('205', $this->orderStatus($changes), 'partly received');

        $all = (new Bookings($s, '2026-10-21'))->receipt(70, [72], $orders, $purchases, $money, [71 => true], 'WE');
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
                    $s->field('purchase_quantity') => ['5'], $s->field('purchase_unit_price') => ['80']],
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
