<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PlaceFunctions;

final class PlaceFunctionsTest extends TestCase
{
    /** Places 1 (type 10, Warenausgang) with 2 inside it, 3 (type 11, no function), 4 (type 12, Wareneingang), 5 without a type. */
    private static function functions(): PlaceFunctions
    {
        return PlaceFunctions::from(
            [1 => 10, 2 => 0, 3 => 11, 4 => 12, 5 => 0],
            [10 => 70, 11 => 0, 12 => 71],
            [70 => PlaceFunctions::OUTBOUND, 71 => PlaceFunctions::INBOUND],
            static fn (int $place): array => $place === 1 ? [2] : [],
        );
    }

    public function testAPlaceHasItsTypesFunctionAndSoDoesEverythingInsideIt(): void
    {
        $functions = self::functions();

        self::assertSame([1, 2], $functions->with(PlaceFunctions::OUTBOUND));
        self::assertSame([4], $functions->with(PlaceFunctions::INBOUND));
        self::assertTrue($functions->is(2, PlaceFunctions::OUTBOUND));
        self::assertFalse($functions->is(3, PlaceFunctions::OUTBOUND), 'a type without a function is a storage place');
        self::assertSame([], PlaceFunctions::none()->with(PlaceFunctions::OUTBOUND));
    }

    public function testWhatLiesOnAWarenausgangPlaceIsProvidedNotAvailable(): void
    {
        $provided = self::functions()->providedByThing([
            100 => [1 => 2.0, 3 => 5.0],
            101 => [2 => 1.0],
            102 => [3 => 4.0],
        ], static fn (int $lot): ?int => [100 => 500, 101 => 500, 102 => 501][$lot] ?? null);

        self::assertSame([500 => 3.0], $provided);
    }
}
