<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Table;

/** A record table read as the booking sees it. */
final class TableTest extends TestCase
{
    #[Test]
    public function a_replaced_value_is_read_in_the_copy_and_the_original_stays(): void
    {
        $table = new Table([7 => [3 => ['1'], 4 => ['x']]]);
        $copy  = $table->with(7, 3, '40');

        self::assertSame('40', $copy->first(7, 3));
        self::assertSame('x', $copy->first(7, 4));
        self::assertSame('1', $table->first(7, 3));
    }
}
