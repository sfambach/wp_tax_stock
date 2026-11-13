<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\SourcePrices;
use TaxmodStock\Core\SourceRow;

/** D-1314: a source's price comes from the purchases with its number, newest first. */
final class SourcePricesTest extends TestCase
{
    #[Test]
    public function the_purchases_with_the_sources_number_newest_first_and_none_without_a_number(): void
    {
        $sources   = [new SourceRow(1, 5, ' ABC-1 '), new SourceRow(2, 6, ''), new SourceRow(3, 7, 'X')];
        $purchases = [
            ['purchase' => 10, 'number' => 'abc-1', 'date' => '2026-10-01', 'price' => 1.5],
            ['purchase' => 11, 'number' => 'ABC-1', 'date' => '2026-11-01', 'price' => 1.2],
            ['purchase' => 12, 'number' => 'other', 'date' => '2026-11-02', 'price' => 9.0],
        ];

        $prices = SourcePrices::of($sources, $purchases);

        self::assertSame([11, 10], array_column($prices[0], 'purchase'));
        self::assertSame([], $prices[1]);
        self::assertSame([], $prices[2]);
    }
}
