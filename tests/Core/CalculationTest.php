<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Calculation;
use TaxmodStock\Core\StockError;

final class CalculationTest extends TestCase
{
    public function testPartsSetUpAndWorkAddUpPerBoard(): void
    {
        $calc = Calculation::fromCost([
            'parts' => [
                ['thing' => 7, 'lot' => 10, 'quantity' => 4, 'unit_price' => 0.25, 'source' => 'lot'],
                ['thing' => 8, 'quantity' => 1, 'unit_price' => 2.0, 'source' => 'last_purchase'],
            ],
            'setup' => ['hours' => 0.5, 'wage' => 15, 'divisor' => 10],
            'work'  => ['minutes' => 12, 'wage' => 15],
            'scrap' => [['thing' => 7, 'quantity' => 2]],
        ]);

        $b = $calc->breakdown();

        self::assertSame(3.0, $b['parts_total']);
        self::assertTrue($b['complete']);
        self::assertSame(0.75, $b['setup']['amount']);
        self::assertSame(3.0, $b['work']['amount']);
        self::assertSame(12.0, $b['work']['minutes']);
        self::assertSame(6.75, $b['total']);
        self::assertSame([['thing' => 7, 'quantity' => 2.0]], $b['scrap']);
        self::assertSame(1800.0, $calc->setupSeconds);
    }

    public function testAPartWithoutPriceMakesItIncompleteNeverZero(): void
    {
        $b = Calculation::fromCost([
            'parts' => [['thing' => 7, 'quantity' => 3, 'unit_price' => null, 'source' => 'lot']],
            'setup' => ['hours' => 0, 'wage' => 15, 'boards_of_order' => 1],
            'work'  => [],
        ])->breakdown();

        self::assertFalse($b['complete']);
        self::assertSame('none', $b['parts'][0]['source']);
        self::assertNull($b['parts'][0]['unit_price']);
        self::assertNull($b['work']['minutes']);
        self::assertSame(0.0, $b['total']);
    }

    public function testNoDivisorIsRefused(): void
    {
        $this->expectException(StockError::class);

        Calculation::fromCost(['setup' => ['hours' => 0.5, 'wage' => 15, 'divisor' => 0], 'parts' => []]);
    }
}
