<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\Purchasing;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\Reservations;
use TaxmodStock\Core\ReservedPlaces;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Storno;
use TaxmodStock\Core\Table;

/**
 * D-1088: reserved goods may be consumed for exactly their purpose, and such a consumption lowers the open reservation.
 *
 * Places: 1 Lager › 2 Produktion (reserved, no project) › 3 Kiste (project 700); 5 Regal is free. Lot 60 (thing 30) holds
 * 10 at place 5; lot 61 (thing 31) 5 at place 5. Reservation 90 for subject 500 names 4 of lot 60 (line 81) and 2 of
 * thing 31 without a lot (line 82).
 */
final class ConsumptionPurposeTest extends TestCase
{
    #[Test]
    public function a_reserved_place_without_project_gives_only_for_production(): void
    {
        $places = $this->places();
        $places->checkConsumption(2, Purpose::production());
        $places->checkConsumption(5, Purpose::none());
        self::assertSame(0, $places->subjectAt(2));

        foreach ([Purpose::none(), Purpose::subject(700)] as $purpose) {
            try {
                $places->checkConsumption(2, $purpose);
                self::fail('refused: not the Bezug of the place');
            } catch (StockError $e) {
                self::assertSame('reserved_for_production', $e->reason);
            }
        }
    }

    #[Test]
    public function a_box_with_a_project_inside_a_reserved_area_gives_only_for_that_project(): void
    {
        $places = $this->places();
        self::assertSame(700, $places->subjectAt(3));
        $places->checkConsumption(3, Purpose::subject(700));

        foreach ([Purpose::production(), Purpose::subject(500), Purpose::none()] as $purpose) {
            try {
                $places->checkConsumption(3, $purpose);
                self::fail('refused');
            } catch (StockError $e) {
                self::assertSame('reserved_for_production', $e->reason);
            }
        }
    }

    #[Test]
    public function a_consumption_for_the_subject_lowers_its_open_reservation(): void
    {
        $list = $this->reservations($this->consumptions(['92' => ['500', '60', '-3'], '93' => ['500', '61', '-2'], '94' => ['501', '60', '-1']]));

        // 4 − 3 of lot 60; thing 31 had no lot named: 2 − 2 of lot 61, the position is consumed whole.
        self::assertSame([30 => 1.0], $list->reservedByThing());
        self::assertSame([60 => 1.0], $list->boundLots());
        self::assertSame([81], array_keys($list->all()[90]['positions']));
    }

    #[Test]
    public function a_reservation_consumed_whole_is_no_longer_open(): void
    {
        $list = $this->reservations($this->consumptions(['92' => ['500', '60', '-5'], '93' => ['500', '61', '-2']]));

        self::assertSame([], $list->open());
        self::assertNull($list->forSubject(500));
    }

    #[Test]
    public function the_storno_of_the_consumption_opens_the_reservation_again(): void
    {
        $s     = Fixture::structure();
        [$deals, $lines] = $this->consumptions(['92' => ['500', '60', '-3']]);
        $rows  = (new Storno($s))->consumptionRows(92, $deals, $lines);

        self::assertSame([['lot' => 60, 'place' => 5, 'quantity' => '3', 'reverses' => 192]], $rows);

        $changes = (new Bookings($s, '2026-11-02 10:00:00'))->cancelConsumption($rows, 'Storno', 'Stefan');
        $line    = $changes[1]['values'];
        self::assertSame('3', $line[(string) $s->field('line_quantity')]);
        self::assertSame('192', $line[(string) $s->field('line_reverses')]);
        self::assertSame('202', end($changes)['values'][(string) $s->field('transaction_kind')]);

        // Booked, the Storno pairs with the consumption: the reservation counts in full again.
        $lines = new Table($this->lineRows() + [
            192 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-3'], $s->field('line_location') => ['5']],
            193 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['3'], $s->field('line_location') => ['5'], $s->field('line_reverses') => ['192']],
        ]);
        $deals = new Table($this->dealRows() + [
            92 => [$s->field('transaction_kind') => ['202'], $s->field('transaction_lines') => ['192'], $s->field('transaction_reference') => ['500']],
            93 => [$s->field('transaction_kind') => ['202'], $s->field('transaction_lines') => ['193']],
        ]);
        $list  = Reservations::from($s, $deals, $lines, new Ledger($s, $this->lots(), $lines, $deals));
        self::assertSame([30 => 4.0, 31 => 2.0], $list->reservedByThing());

        $this->expectException(StockError::class);
        (new Storno($s))->consumptionRows(92, $deals, $lines);
    }

    #[Test]
    public function only_a_consumption_is_cancelled_as_one(): void
    {
        $this->expectException(StockError::class);
        (new Storno(Fixture::structure()))->consumptionRows(90, new Table($this->dealRows()), new Table($this->lineRows()));
    }

    #[Test]
    public function a_lot_a_reservation_names_gives_others_only_what_is_free(): void
    {
        [$deals, $lines] = $this->consumptions([]);
        $s      = Fixture::structure();
        $ledger = new Ledger($s, $this->lots(), $lines, $deals);
        $list   = Reservations::from($s, $deals, $lines, $ledger);
        $places = $this->places();

        // Lot 60: 10 there, 4 reserved for 500 — 6 free for anyone, all 10 for 500.
        $list->checkConsumption(60, '6', 5, Purpose::none(), $ledger, $places);
        $list->checkConsumption(60, '10', 5, Purpose::subject(500), $ledger, $places);
        // A lot no reservation names may go below zero as before (concept §11).
        $list->checkConsumption(61, '99', 5, Purpose::none(), $ledger, $places);

        foreach ([Purpose::none(), Purpose::subject(501), Purpose::production()] as $purpose) {
            try {
                $list->checkConsumption(60, '7', 5, $purpose, $ledger, $places);
                self::fail('refused: reserved for 500');
            } catch (StockError $e) {
                self::assertSame('reserved_for_other', $e->reason);
            }
        }

        $this->expectException(StockError::class);
        $list->checkConsumption(60, '11', 5, Purpose::subject(500), $ledger, $places);
    }

    #[Test]
    public function before_the_bezug_field_the_purpose_stands_in_the_project(): void
    {
        $s      = Fixture::structure('transaction_reference');
        $values = (new Bookings($s, '2026-11-02'))->consumption(60, '1', Purpose::subject(700), 'Verbrauch');

        self::assertSame('700', end($values)['values'][(string) $s->field('transaction_project')]);
        self::assertArrayNotHasKey((string) Fixture::structure()->field('description'), end($values)['values'], 'no reason, no description');

        $production = (new Bookings(Fixture::structure(), '2026-11-02'))->consumption(60, '1', Purpose::production(), 'Verbrauch', 2);
        self::assertArrayNotHasKey((string) Fixture::structure()->field('transaction_reference'), end($production)['values'], '«für Produktion» names no record');
    }

    #[Test]
    public function reorder_proposes_from_the_available_stock(): void
    {
        // Minimum 10, 12 in stock of which 8 reserved: 4 available, 6 missing.
        self::assertSame(6, Purchasing::proposedQuantity(10.0, 12.0 - 8.0, 0.0));
    }

    private function places(): ReservedPlaces
    {
        return new ReservedPlaces(
            new LocationTree([1 => 'Lager', 2 => 'Produktion', 3 => 'Kiste', 5 => 'Regal'], [1 => 0, 2 => 1, 3 => 2, 5 => 1], [3 => 'Platine'], [], [3 => 700]),
            [2 => true],
        );
    }

    private function lots(): Table
    {
        $s = Fixture::structure();

        return new Table([
            60 => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['5'], $s->field('lot_date') => ['2026-10-01']],
            61 => [$s->field('lot_thing') => ['31'], $s->field('lot_location') => ['5'], $s->field('lot_date') => ['2026-10-01']],
        ]);
    }

    /** @return array<int, array<int, list<string>>> */
    private function lineRows(): array
    {
        $s = Fixture::structure();

        return [
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['10']],
            84 => [$s->field('line_lot') => ['61'], $s->field('line_quantity') => ['5']],
            81 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['4'], $s->field('line_thing') => ['30']],
            82 => [$s->field('line_quantity') => ['2'], $s->field('line_thing') => ['31']],
        ];
    }

    /** @return array<int, array<int, list<string>>> */
    private function dealRows(): array
    {
        $s = Fixture::structure();

        return [
            89 => [$s->field('transaction_kind') => ['201'], $s->field('transaction_lines') => ['80', '84']],
            90 => [$s->field('transaction_kind') => ['210'], $s->field('transaction_lines') => ['81', '82'], $s->field('transaction_reference') => ['500'], $s->field('transaction_date') => ['2026-11-02']],
        ];
    }

    /**
     * Consumption Vorgänge, each with one line at place 5: deal id → [subject, lot, quantity]; the line is the deal + 100.
     *
     * @param array<string, array{string, string, string}> $consumed
     * @return array{Table, Table}
     */
    private function consumptions(array $consumed): array
    {
        $s     = Fixture::structure();
        $deals = $this->dealRows();
        $lines = $this->lineRows();

        foreach ($consumed as $deal => [$subject, $lot, $quantity]) {
            $lines[(int) $deal + 100] = [$s->field('line_lot') => [$lot], $s->field('line_quantity') => [$quantity], $s->field('line_location') => ['5']];
            $deals[(int) $deal]       = [$s->field('transaction_kind') => ['202'], $s->field('transaction_lines') => [(string) ((int) $deal + 100)], $s->field('transaction_reference') => [$subject]];
        }

        return [new Table($deals), new Table($lines)];
    }

    /** @param array{Table, Table} $tables */
    private function reservations(array $tables): Reservations
    {
        [$deals, $lines] = $tables;
        $s = Fixture::structure();

        return Reservations::from($s, $deals, $lines, new Ledger($s, $this->lots(), $lines, $deals));
    }
}
