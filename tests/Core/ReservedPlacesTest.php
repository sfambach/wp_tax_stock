<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\LocationChanges;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\ReservedPlaces;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TransferRow;

/**
 * D-1082: a place «reserviert für Produktion» reserves what lies on it and below it; computed, strict.
 *
 * Tree: 1 Lager › 2 Produktion (flag) › 3 Platz › 4 Kiste; 5 Regal; 6 Montage (flag).
 */
final class ReservedPlacesTest extends TestCase
{
    #[Test]
    public function the_flag_is_inherited_down_the_tree(): void
    {
        $places = $this->places();

        self::assertSame(2, $places->reservingPlaceOf(4), 'the box in the reserved area');
        self::assertSame(2, $places->reservingPlaceOf(2));
        self::assertNull($places->reservingPlaceOf(1), 'not upwards');
        self::assertNull($places->reservingPlaceOf(5));
        self::assertNull($places->reservingPlaceOf(0));
    }

    #[Test]
    public function the_reservation_is_computed_from_what_lies_there(): void
    {
        $ledger = $this->ledger();
        $places = $this->places();

        self::assertSame([2 => [60 => [4 => 3.0]]], $places->holdings($ledger), 'lot 61 lies at the free shelf');
        self::assertSame([30 => 3.0], $places->reservedByThing($ledger));
    }

    #[Test]
    public function goods_move_into_a_reserved_area_unless_reserved_already(): void
    {
        $places = $this->places();

        $places->checkTransfer(61, 5, 3, false);
        $places->checkTransfer(60, 4, 3, false);
        $places->checkTransfer(60, 4, 5, false);
        self::assertTrue(true, 'free into an area, within it, and out of it');

        foreach ([[60, 4, 6, false], [61, 5, 3, true]] as [$lot, $from, $to, $bound]) {
            try {
                $places->checkTransfer($lot, $from, $to, $bound);
                self::fail('refused');
            } catch (StockError $e) {
                self::assertSame('already_reserved', $e->reason);
            }
        }
    }

    #[Test]
    public function the_umbuchung_checks_the_reservation(): void
    {
        $this->expectException(StockError::class);
        (new Bookings(Fixture::structure(), '2026-11-02'))->transfer([new TransferRow(60, 4, 6, '1')], 'U', $this->ledger(), '', $this->places());
    }

    #[Test]
    public function nothing_is_consumed_inside_a_reserved_area(): void
    {
        $this->places()->checkConsumption(5);

        $this->expectException(StockError::class);
        $this->places()->checkConsumption(4);
    }

    #[Test]
    public function hanging_a_box_carries_its_reservation_rules(): void
    {
        $places = $this->places();
        $places->checkHang(4, 5, $this->ledger(), []);
        $places->checkHang(5, 3, $this->ledger(), []);
        self::assertTrue(true, 'out of the area, and a free shelf into it');

        $this->expectException(StockError::class);
        $places->checkHang(4, 6, $this->ledger(), []);
    }

    #[Test]
    public function the_flag_is_set_and_taken_off_as_one_value(): void
    {
        $s       = Fixture::structure();
        $changes = (new LocationChanges($s))->reserve(5, true, new Table([5 => []]));

        self::assertSame([['op' => 'set_values', 'record' => '5', 'values' => [(string) $s->field('location_reserved') => '1']]], $changes);
        self::assertSame('0', (new LocationChanges($s))->reserve(5, false, new Table([5 => []]))[0]['values'][(string) $s->field('location_reserved')]);

        $this->expectException(StockError::class);
        $this->places()->checkFlag(5, $this->ledger(), [61 => true]);
    }

    #[Test]
    public function the_flag_is_read_from_the_places(): void
    {
        $s      = Fixture::structure();
        $table  = new Table([1 => [], 2 => [$s->field('location_reserved') => ['1']], 3 => [$s->field('location_reserved') => ['0']]]);
        $places = ReservedPlaces::from($s, $table, new LocationTree([1 => 'A', 2 => 'B', 3 => 'C'], [1 => 0, 2 => 1, 3 => 2]));

        self::assertSame([2], $places->flagged());
        self::assertSame(2, $places->reservingPlaceOf(3));
    }

    private function places(): ReservedPlaces
    {
        return new ReservedPlaces(
            new LocationTree([1 => 'Lager', 2 => 'Produktion', 3 => 'Platz', 4 => 'Kiste', 5 => 'Regal', 6 => 'Montage'], [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 1, 6 => 1]),
            [2 => true, 6 => true],
        );
    }

    private function ledger(): Ledger
    {
        $s    = Fixture::structure();
        $lots = new Table([
            60 => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['4'], $s->field('lot_date') => ['2026-10-01']],
            61 => [$s->field('lot_thing') => ['31'], $s->field('lot_location') => ['5'], $s->field('lot_date') => ['2026-10-01']],
        ]);

        return new Ledger($s, $lots, new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['3']],
            81 => [$s->field('line_lot') => ['61'], $s->field('line_quantity') => ['2']],
        ]));
    }
}
