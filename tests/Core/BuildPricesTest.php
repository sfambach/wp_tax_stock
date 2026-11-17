<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\BuildPrices;

final class BuildPricesTest extends TestCase
{
    public function testTheLotsOwnPriceThenTheLastPurchaseThenNone(): void
    {
        $rows = [
            ['lot' => 10, 'place' => 1, 'quantity' => -2.0],
            ['lot' => 10, 'place' => 2, 'quantity' => -1.0],
            ['lot' => 11, 'place' => 1, 'quantity' => -4.0],
            ['lot' => 12, 'place' => 1, 'quantity' => -1.0],
            ['lot' => 13, 'place' => 1, 'quantity' => 5.0],
        ];

        $prices = BuildPrices::of($rows, [10 => 7, 11 => 7, 12 => 8, 13 => 9], [10 => 0.25], [7 => 0.30]);

        self::assertSame([
            7 => [
                ['lot' => 10, 'quantity' => 3.0, 'unit_price' => 0.25, 'source' => 'lot'],
                ['lot' => 11, 'quantity' => 4.0, 'unit_price' => 0.30, 'source' => 'last_purchase'],
            ],
            8 => [
                ['lot' => 12, 'quantity' => 1.0, 'unit_price' => null, 'source' => 'none'],
            ],
        ], $prices);
    }

    public function testTheNewestPricedPurchaseIsTheLast(): void
    {
        $purchases = [
            1 => ['material' => 7, 'date' => '2026-01-01', 'unit' => 1.0],
            2 => ['material' => 7, 'date' => '2026-03-01', 'unit' => 2.0],
            3 => ['material' => 7, 'date' => '2026-04-01', 'unit' => null],
            4 => ['material' => 8, 'date' => '', 'unit' => 0.5],
        ];

        self::assertSame([7 => 2.0, 8 => 0.5], BuildPrices::lastPurchase($purchases));
    }
}
