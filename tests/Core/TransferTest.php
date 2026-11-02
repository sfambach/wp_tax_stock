<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Storno;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TransferRow;

/** D-1039: an Umbuchung is a Vorgang with «− old place, + new place»; D-1036: taking it back is a Storno. */
final class TransferTest extends TestCase
{
    #[Test]
    public function a_lot_lies_where_it_came_in_until_a_line_names_another_place(): void
    {
        $ledger = $this->ledger([80 => [60, '5', 0], 81 => [60, '-2', 3], 82 => [60, '2', 4]]);

        self::assertSame([3 => 3.0, 4 => 2.0], $ledger->placesOf(60), 'the receipt line has no place: it lies where the lot came in');
        self::assertSame(5.0, $ledger->restOf(60), 'an Umbuchung leaves the lot as it is');
        self::assertSame(2.0, $ledger->restAt(60, 4));
        self::assertSame(3, $ledger->homeOf(60));
    }

    #[Test]
    public function an_umbuchung_writes_two_lines_with_their_places_and_the_kind(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-02'))->transfer([new TransferRow(60, 3, 4, '2')], 'Umbuchung', $this->ledger([80 => [60, '5', 0]]));
        $lines   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && isset($c['values'][(string) $s->field('line_quantity')])));
        $deal    = end($changes)['values'];

        self::assertSame(['-2', '3'], [$lines[0]['values'][(string) $s->field('line_quantity')], $lines[0]['values'][(string) $s->field('line_location')]]);
        self::assertSame(['2', '4'], [$lines[1]['values'][(string) $s->field('line_quantity')], $lines[1]['values'][(string) $s->field('line_location')]]);
        self::assertSame('209', $deal[(string) $s->field('transaction_kind')]);
        self::assertSame(['values' => ['@p1', '@p2']], $deal[(string) $s->field('transaction_lines')]);
        self::assertCount(0, array_filter($changes, static fn (array $c): bool => ($c['record'] ?? '') === '60'), 'the lot itself is not touched');
    }

    #[Test]
    public function an_umbuchung_moves_no_more_than_lies_there_and_not_to_the_same_place(): void
    {
        $ledger = $this->ledger([80 => [60, '5', 0], 81 => [60, '-4', 3], 82 => [60, '4', 4]]);

        foreach ([new TransferRow(60, 3, 4, '2'), new TransferRow(60, 4, 4, '1'), new TransferRow(60, 3, 4, '0')] as $row) {
            try {
                (new Bookings(Fixture::structure(), '2026-11-02'))->transfer([$row], 'U', $ledger);
                self::fail('refused');
            } catch (StockError $e) {
                self::assertContains($e->reason, ['not_enough_at', 'same_place', 'not_a_quantity']);
            }
        }
    }

    #[Test]
    public function the_storno_of_an_umbuchung_moves_back_and_names_the_lines_it_cancels(): void
    {
        $s      = Fixture::structure();
        $lines  = $this->lines([80 => [60, '5', 0], 81 => [60, '-2', 3], 82 => [60, '2', 4]]);
        $deals  = new Table([90 => [$s->field('transaction_kind') => ['209'], $s->field('transaction_lines') => ['81', '82']]]);
        $ledger = new Ledger($s, $this->lots(), $lines, $deals);
        $rows   = (new Storno($s))->transferRows(90, $deals, $lines, $ledger);

        self::assertEquals([new TransferRow(60, 4, 3, '2', 82, 81)], $rows);

        $changes = (new Bookings($s, '2026-11-02 10:00:00'))->transfer($rows, 'Storno', $ledger, 'Stefan');
        $first   = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values' && isset($c['values'][(string) $s->field('line_reverses')])));

        self::assertSame(['82', '81'], array_map(static fn (array $c): string => $c['values'][(string) $s->field('line_reverses')], $first));
        self::assertSame('Stefan', end($changes)['values'][(string) $s->field('description')], 'who took it back');
    }

    #[Test]
    public function a_storno_pair_is_hidden_and_not_cancelled_twice(): void
    {
        $s     = Fixture::structure();
        $lines = $this->lines([81 => [60, '-2', 3], 82 => [60, '2', 4], 83 => [60, '-2', 4, 82], 84 => [60, '2', 3, 81]]);
        $deals = new Table([
            90 => [$s->field('transaction_kind') => ['209'], $s->field('transaction_lines') => ['81', '82']],
            91 => [$s->field('transaction_kind') => ['209'], $s->field('transaction_lines') => ['83', '84']],
            92 => [$s->field('transaction_kind') => ['202'], $s->field('transaction_lines') => []],
        ]);

        self::assertSame([81, 82, 83, 84], $this->sorted((new Storno($s))->pairedLines($lines)), 'the Storno and what it cancels');

        foreach ([90 => 'already_cancelled', 91 => 'already_cancelled', 92 => 'not_cancellable'] as $deal => $reason) {
            try {
                (new Storno($s))->transferRows($deal, $deals, $lines, new Ledger($s, $this->lots(), $lines, $deals));
                self::fail('refused');
            } catch (StockError $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    #[Test]
    public function a_consumption_names_its_place(): void
    {
        $s       = Fixture::structure();
        $changes = (new Bookings($s, '2026-11-02'))->consumption(60, '1', Purpose::none(), 'Verbrauch', 4);

        self::assertSame('4', $changes[1]['values'][(string) $s->field('line_location')]);
    }

    /**
     * @param array<int, true> $keys
     * @return list<int>
     */
    private function sorted(array $keys): array
    {
        $ids = array_keys($keys);
        sort($ids);

        return $ids;
    }

    private function lots(): Table
    {
        $s = Fixture::structure();

        return new Table([60 => [$s->field('lot_thing') => ['30'], $s->field('lot_location') => ['3'], $s->field('lot_date') => ['2026-10-01']]]);
    }

    /** @param array<int, array{0: int, 1: string, 2: int, 3?: int}> $rows line → lot, quantity, place (0: none), cancelled line */
    private function lines(array $rows): Table
    {
        $s   = Fixture::structure();
        $out = [];

        foreach ($rows as $id => $row) {
            $out[$id] = [$s->field('line_lot') => [(string) $row[0]], $s->field('line_quantity') => [$row[1]]]
                + ($row[2] > 0 ? [$s->field('line_location') => [(string) $row[2]]] : [])
                + (isset($row[3]) ? [$s->field('line_reverses') => [(string) $row[3]]] : []);
        }

        return new Table($out);
    }

    /** @param array<int, array{0: int, 1: string, 2: int}> $rows */
    private function ledger(array $rows): Ledger
    {
        return new Ledger(Fixture::structure(), $this->lots(), $this->lines($rows));
    }
}
