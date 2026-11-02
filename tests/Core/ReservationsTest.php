<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\ReservationRow;
use TaxmodStock\Core\Reservations;
use TaxmodStock\Core\ReservedPlaces;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/**
 * D-1083: a reservation is a Vorgang «Reservierung» per subject; it lowers the available stock, not the stock.
 *
 * Lot 60 (thing 30) holds 10 at place 3; reservation 90 for subject 500 names 4 of lot 60 (line 81) and 2 of thing 31
 * without a lot (line 82); Storno 91 takes 1 back from line 81.
 */
final class ReservationsTest extends TestCase
{
    #[Test]
    public function reservation_lines_move_no_stock(): void
    {
        self::assertSame(10.0, $this->ledger()->restOf(60));
        self::assertSame(13.0, (new Ledger(Fixture::structure(), $this->lots(), $this->lines()))->restOf(60), 'without the Vorgänge every line would count');
    }

    #[Test]
    public function a_position_counts_with_what_its_storno_left(): void
    {
        $list = $this->reservations();

        self::assertSame([30 => 3.0, 31 => 2.0], $list->reservedByThing());
        self::assertSame([60 => 3.0], $list->boundLots());
        self::assertSame(90, $list->forSubject(500));
        self::assertNull($list->forSubject(501));
        self::assertSame([90], array_keys($list->all()), 'the Storno is no reservation of its own');
        self::assertSame([90 => 3.0], $list->ofThing(30));
    }

    #[Test]
    public function a_lot_gives_only_what_lies_free(): void
    {
        $list   = $this->reservations();
        $places = new ReservedPlaces(new LocationTree([3 => 'Regal'], [3 => 0]), []);

        $list->check([new ReservationRow(30, '7', 60), new ReservationRow(31, '99')], $this->ledger(), $places);
        self::assertTrue(true, '10 − 3 bound = 7 free; without a lot no limit');

        foreach ([[new ReservationRow(30, '8', 60)], [new ReservationRow(31, '1', 60)]] as $rows) {
            try {
                $list->check($rows, $this->ledger(), $places);
                self::fail('refused');
            } catch (StockError $e) {
                self::assertContains($e->reason, ['not_enough_free', 'no_lot']);
            }
        }

        $this->expectException(StockError::class);
        $list->check([new ReservationRow(30, '1', 60)], $this->ledger(), new ReservedPlaces(new LocationTree([3 => 'Regal'], [3 => 0]), [3 => true]));
    }

    #[Test]
    public function a_new_subject_gets_a_vorgang_with_its_reference_and_deadline(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-02'))->reserve(501, '2026-12-01', [new ReservationRow(31, '2')], null, $this->deals(), 'Reservierung');
        $line    = $changes[1]['values'];
        $deal    = end($changes)['values'];

        self::assertSame('31', $line[(string) $s->field('line_thing')]);
        self::assertArrayNotHasKey((string) $s->field('line_lot'), $line, 'no lot named');
        self::assertSame('210', $deal[(string) $s->field('transaction_kind')]);
        self::assertSame('501', $deal[(string) $s->field('transaction_reference')]);
        self::assertSame('2026-12-01', $deal[(string) $s->field('transaction_deadline')]);
    }

    #[Test]
    public function a_further_position_joins_the_open_reservation_of_its_subject(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-02'))->reserve(500, '', [new ReservationRow(30, '1', 60)], 90, $this->deals(), 'Reservierung');
        $last    = end($changes);

        self::assertSame('90', $last['record']);
        self::assertSame(['values' => ['81', '82', '@p1']], $last['values'][(string) $s->field('transaction_lines')]);
        self::assertCount(0, array_filter($changes, static fn (array $c): bool => $c['op'] === 'create_record' && $c['node'] === $s->node('transaction')), 'no second reservation');
    }

    #[Test]
    public function a_reservation_without_subject_is_refused(): void
    {
        $this->expectException(StockError::class);
        (new Bookings(Fixture::structure(), '2026-11-02'))->reserve(0, '', [new ReservationRow(31, '2')], null, $this->deals(), 'R');
    }

    #[Test]
    public function the_storno_takes_back_what_is_left_and_names_the_position(): void
    {
        $s         = Fixture::structure();
        $positions = $this->reservations()->toCancel(90);
        $changes   = (new Bookings($s, '2026-11-02 10:00:00'))->cancelReservation(500, $positions, 'Storno', 'Stefan');
        $lines     = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && isset($c['values'][(string) $s->field('line_reverses')])));

        self::assertSame([['81', '-3'], ['82', '-2']], array_map(static fn (array $c): array => [$c['values'][(string) $s->field('line_reverses')], $c['values'][(string) $s->field('line_quantity')]], $lines));
        self::assertSame([82], array_keys($this->reservations()->toCancel(90, 82)), 'one position');

        $this->expectException(StockError::class);
        $this->reservations()->toCancel(90, 999);
    }

    private function lots(): Table
    {
        $s = Fixture::structure();

        return new Table([60 => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['3'], $s->field('lot_date') => ['2026-10-01']]]);
    }

    private function lines(): Table
    {
        $s = Fixture::structure();

        return new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['10']],
            81 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['4'], $s->field('line_thing') => ['30']],
            82 => [$s->field('line_quantity') => ['2'], $s->field('line_thing') => ['31']],
            83 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-1'], $s->field('line_thing') => ['30'], $s->field('line_reverses') => ['81']],
        ]);
    }

    private function deals(): Table
    {
        $s = Fixture::structure();

        return new Table([
            89 => [$s->field('transaction_kind') => ['201'], $s->field('transaction_lines') => ['80']],
            90 => [$s->field('transaction_kind') => ['210'], $s->field('transaction_lines') => ['81', '82'], $s->field('transaction_reference') => ['500'], $s->field('transaction_date') => ['2026-11-02']],
            91 => [$s->field('transaction_kind') => ['210'], $s->field('transaction_lines') => ['83'], $s->field('transaction_reference') => ['500']],
        ]);
    }

    private function ledger(): Ledger
    {
        return new Ledger(Fixture::structure(), $this->lots(), $this->lines(), $this->deals());
    }

    private function reservations(): Reservations
    {
        return Reservations::from(Fixture::structure(), $this->deals(), $this->lines(), $this->ledger());
    }
}
