<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\StockAnswer;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\Things;

/**
 * D-1208: «is anything stored under Alex?» answered from the ledger, not by reading every lot and transaction.
 */
final class StockAnswerTest extends TestCase
{
    /** @return array{Ledger, LocationTree, Things} Haus(1) › Alex(2) › Kiste(3); Garage(4) */
    private static function stock(): array
    {
        $s    = Fixture::structure();
        $lots = new Table([
            60 => [$s->field('lot_location') => ['3'], $s->field('lot_thing') => ['30']],
            61 => [$s->field('lot_location') => ['2'], $s->field('lot_thing') => ['31']],
            62 => [$s->field('lot_location') => ['4'], $s->field('lot_thing') => ['30']],
            63 => [$s->field('lot_location') => ['2'], $s->field('lot_thing') => ['32']],
        ]);
        $lines = new Table([
            80 => [$s->field('line_lot') => ['60'], $s->field('line_quantity') => ['5']],
            81 => [$s->field('line_lot') => ['61'], $s->field('line_quantity') => ['2']],
            82 => [$s->field('line_lot') => ['62'], $s->field('line_quantity') => ['7']],
            83 => [$s->field('line_lot') => ['63'], $s->field('line_quantity') => ['1']],
            84 => [$s->field('line_lot') => ['63'], $s->field('line_quantity') => ['-1']],
        ]);
        $places = new LocationTree([1 => 'Haus', 2 => 'Alex', 3 => 'Kiste', 4 => 'Garage'], [2 => 1, 3 => 2]);
        $things = new Things($s, new Table([]), [30 => 'NE555', 31 => 'LM317', 32 => 'BC547'], [30 => 9, 31 => 9, 32 => 9]);

        return [new Ledger($s, $lots, $lines), $places, $things];
    }

    #[Test]
    public function a_place_answers_with_what_lies_there_and_inside_it(): void
    {
        [$ledger, $places, $things] = self::stock();

        $out = StockAnswer::of($ledger, $places, $things, 2, true, [], 100, 0);

        self::assertSame(2, $out['total_lots'], 'the used-up lot 63 is not there');
        self::assertSame([[61, 31, 'Haus › Alex', 2.0], [60, 30, 'Haus › Alex › Kiste', 5.0]], array_map(
            static fn (array $r): array => [$r['lot'], $r['material'], $r['place_word'], $r['quantity']],
            $out['lots']
        ));
        self::assertSame(['LM317', 'NE555'], array_column($out['materials'], 'material_word'));

        self::assertSame(1, StockAnswer::of($ledger, $places, $things, 2, false, [], 100, 0)['total_lots'], 'without below only the place itself');
        self::assertSame(0, StockAnswer::of($ledger, $places, $things, 99, true, [], 100, 0)['total_lots'], 'an unknown place holds nothing');
    }

    #[Test]
    public function a_material_answers_with_its_quantity_over_every_place(): void
    {
        [$ledger, $places, $things] = self::stock();

        $out = StockAnswer::of($ledger, $places, $things, 0, true, [30], 1, 0);

        self::assertSame([['material' => 30, 'material_word' => 'NE555', 'quantity' => 12.0, 'lots' => 2]], $out['materials']);
        self::assertSame(2, $out['total_lots']);
        self::assertCount(1, $out['lots'], 'the page holds one row, the totals all of them');

        $this->expectException(\InvalidArgumentException::class);
        StockAnswer::of($ledger, $places, $things, 0, true, [], 0, 0);
    }
}
