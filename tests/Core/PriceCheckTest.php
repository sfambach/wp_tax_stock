<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PriceCheck;

final class PriceCheckTest extends TestCase
{
    public function testWarnsAboveTheShareOverTheLastEarlierPurchase(): void
    {
        $purchases = [
            1 => ['material' => 7, 'date' => '2026-01-01', 'unit' => 1.00],
            2 => ['material' => 7, 'date' => '2026-03-01', 'unit' => 0.62],
            3 => ['material' => 7, 'date' => '2026-05-01', 'unit' => 6.20],
        ];

        $warnings = PriceCheck::warnings([3], $purchases, 20.0);

        self::assertSame(2, $warnings[3]['previous']);
        self::assertEqualsWithDelta(900.0, $warnings[3]['percent'], 0.001);
    }

    public function testNoWarningAtOrBelowTheShareNorDownwards(): void
    {
        $purchases = [
            1 => ['material' => 7, 'date' => '2026-01-01', 'unit' => 1.00],
            2 => ['material' => 7, 'date' => '2026-02-01', 'unit' => 1.20],
            3 => ['material' => 7, 'date' => '2026-03-01', 'unit' => 0.10],
        ];

        self::assertSame([], PriceCheck::warnings([2, 3], $purchases, 20.0));
    }

    public function testLaterPurchasesOtherMaterialsAndMissingPricesDoNotCount(): void
    {
        $purchases = [
            1 => ['material' => 7, 'date' => '2026-01-01', 'unit' => null],
            2 => ['material' => 8, 'date' => '2026-01-02', 'unit' => 0.10],
            3 => ['material' => 7, 'date' => '2026-02-01', 'unit' => 5.00],
            4 => ['material' => 7, 'date' => '2026-03-01', 'unit' => 0.10],
        ];

        self::assertSame([], PriceCheck::warnings([3], $purchases, 20.0));
    }

    public function testSameDateFallsBackToTheSmallerId(): void
    {
        $purchases = [
            5 => ['material' => 7, 'date' => '2026-01-01', 'unit' => 1.00],
            6 => ['material' => 7, 'date' => '2026-01-01', 'unit' => 2.00],
        ];

        self::assertSame(5, PriceCheck::warnings([6], $purchases, 20.0)[6]['previous']);
        self::assertSame([], PriceCheck::warnings([5], $purchases, 20.0));
    }
}
