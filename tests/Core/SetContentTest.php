<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\SetContent;

final class SetContentTest extends TestCase
{
    public function testWantedAgainstHeld(): void
    {
        $rows = SetContent::rows([10 => 50.0, 11 => 30.0, 12 => 20.0], [
            ['lot' => 1, 'thing' => 10, 'quantity' => 50.0],
            ['lot' => 2, 'thing' => 11, 'quantity' => 20.0],
            ['lot' => 3, 'thing' => 11, 'quantity' => 5.0],
            ['lot' => 4, 'thing' => 13, 'quantity' => 7.0],
        ]);

        self::assertSame(['ok', 'weniger', 'fehlt', 'nicht im Set'], array_column($rows, 'state'));
        self::assertSame([2, 3], $rows[1]['lots']);
        self::assertSame(25.0, $rows[1]['held']);
        self::assertNull($rows[3]['wanted']);
    }

    public function testMoreThanWanted(): void
    {
        self::assertSame('mehr', SetContent::rows([10 => 5.0], [['lot' => 1, 'thing' => 10, 'quantity' => 6.0]])[0]['state']);
    }

    public function testNoSelfAndNoChain(): void
    {
        self::assertFalse(SetContent::allowed(5, 5, []));
        self::assertFalse(SetContent::allowed(5, 7, [7 => 9]));
        self::assertTrue(SetContent::allowed(5, 7, [8 => 7]));
        self::assertFalse(SetContent::allowed(5, 0, []));
    }
}
