<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PlaceFunctions;

final class PlaceFunctionsTest extends TestCase
{
    /** Places 1 (Warenausgang) with 2 inside it, 3 and 5 without a function, 4 (Wareneingang). */
    private static function functions(): PlaceFunctions
    {
        return PlaceFunctions::from(
            [1 => [70, 72], 2 => [], 3 => [], 4 => [71], 5 => []],
            [70 => PlaceFunctions::OUTBOUND, 71 => PlaceFunctions::INBOUND, 72 => PlaceFunctions::PACK],
            static fn (int $place): array => $place === 1 ? [2] : [],
        );
    }

    public function testAPlaceHasItsOwnFunctionAndSoDoesEverythingInsideIt(): void
    {
        $functions = self::functions();

        self::assertSame([1, 2], $functions->with(PlaceFunctions::OUTBOUND));
        self::assertSame([4], $functions->with(PlaceFunctions::INBOUND));
        self::assertSame([1, 2], $functions->with(PlaceFunctions::PACK), 'functions are flags: one place is Warenausgang and Packplatz');
        self::assertTrue($functions->is(2, PlaceFunctions::OUTBOUND));
        self::assertFalse($functions->is(3, PlaceFunctions::OUTBOUND), 'a place without a function is a storage place');
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

    /**
     * D-1503: Wareneingang 20 (inbound) holds Test 21 (with 22 inside) and Klärung 23; Fertigung 30 holds Test 31; Klärung 40
     * stands alone.
     */
    private static function testing(): array
    {
        $parents = [20 => 0, 21 => 20, 22 => 21, 23 => 20, 30 => 0, 31 => 30, 40 => 0];
        $below   = [20 => [21, 22, 23], 21 => [22]];

        return [PlaceFunctions::from(
            [20 => [71], 21 => [72], 22 => [], 23 => [73], 30 => [], 31 => [72], 40 => [73]],
            [71 => PlaceFunctions::INBOUND, 72 => PlaceFunctions::TEST, 73 => PlaceFunctions::CLARIFY],
            static fn (int $place): array => $below[$place] ?? [],
        ), $parents];
    }

    public function testTheGoodsReceiptsTestPlaceIsTheTopmostWithBothFunctions(): void
    {
        [$functions, $parents] = self::testing();

        self::assertSame(21, $functions->firstWithBoth(PlaceFunctions::TEST, PlaceFunctions::INBOUND, $parents));
        self::assertNull(PlaceFunctions::none()->firstWithBoth(PlaceFunctions::TEST, PlaceFunctions::INBOUND, $parents));
    }

    public function testAFailedLotGoesToTheNearestKlaerung(): void
    {
        [$functions, $parents] = self::testing();

        self::assertSame(23, $functions->nearest(PlaceFunctions::CLARIFY, 22, $parents), 'the Klärung in the same goods receipt');
        self::assertSame(23, $functions->nearest(PlaceFunctions::CLARIFY, 31, $parents), 'none shared: the lowest');
        self::assertNull(PlaceFunctions::none()->nearest(PlaceFunctions::CLARIFY, 22, $parents));
    }
}
