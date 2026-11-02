<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationStock;
use TaxmodStock\Core\Table;

final class LocationStockTest extends TestCase
{
    #[Test]
    public function each_place_counts_its_lots_with_rest_and_a_lot_without_place_goes_under_none(): void
    {
        $s    = Fixture::structure();
        $lots = new Table([
            60 => [$s->field('lot_location') => ['5'], $s->field('lot_thing') => ['30']],
            61 => [$s->field('lot_location') => ['5'], $s->field('lot_thing') => ['30']],
            62 => [$s->field('lot_thing') => ['31']],
            63 => [$s->field('lot_location') => ['5'], $s->field('lot_thing') => ['32']],
        ]);
        $lines = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['2']],
            81 => [$s->field('line_lot') => ['61'], $s->field('line_quantity') => ['1']],
            82 => [$s->field('line_lot') => ['62'], $s->field('line_quantity') => ['1']],
            83 => [$s->field('line_lot') => ['63'], $s->field('line_quantity') => ['1']],
            84 => [$s->field('line_lot') => ['63'], $s->field('line_quantity') => ['-1']],
        ]);

        $out = LocationStock::of($s, $lots, new Ledger($s, $lots, $lines), [5 => 'Keller', 6 => 'Leer']);

        self::assertSame(['name' => 'Keller', 'lots' => 2, 'things' => [30]], $out[5]);
        self::assertSame(['name' => 'Leer', 'lots' => 0, 'things' => []], $out[6]);
        self::assertSame(['name' => '', 'lots' => 1, 'things' => [31]], $out[0]);
    }

    #[Test]
    public function a_lot_split_by_an_umbuchung_counts_at_both_places(): void
    {
        $s     = Fixture::structure();
        $lots  = new Table([60 => [$s->field('lot_location') => ['5'], $s->field('lot_thing') => ['30']]]);
        $lines = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['3']],
            81 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['-1'], $s->field('line_location') => ['5']],
            82 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['1'], $s->field('line_location') => ['6']],
        ]);

        $out = LocationStock::of($s, $lots, new Ledger($s, $lots, $lines), [5 => 'Keller', 6 => 'Kiste']);

        self::assertSame([1, 1], [$out[5]['lots'], $out[6]['lots']]);
    }
}
