<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Table;

/** D-1486: a table read in a few fields answers those from what it has, and any other from the whole node — never as empty. */
final class TableTest extends TestCase
{
    private const WHOLE = [7 => [1 => ['a'], 2 => ['b', 'c'], 3 => ['long']], 8 => [3 => ['only long']]];

    #[Test]
    public function a_field_that_was_read_is_answered_without_reading_the_node_whole(): void
    {
        $reads = 0;
        $table = $this->narrow($reads);
        $before = Table::$widened;

        self::assertSame('a', $table->first(7, 1));
        self::assertSame(['b', 'c'], $table->all(7, 2));
        self::assertNull($table->first(8, 1));
        self::assertSame([7, 8], $table->ids());
        self::assertSame(0, $reads);
        self::assertSame($before, Table::$widened);
    }

    #[Test]
    public function a_field_that_was_not_read_is_answered_from_the_whole_node_read_once(): void
    {
        $reads = 0;
        $table = $this->narrow($reads);
        $before = Table::$widened;

        self::assertSame('only long', $table->first(8, 3));
        self::assertSame('long', $table->first(7, 3));
        self::assertSame(1, $reads);
        self::assertSame($before + 1, Table::$widened);
    }

    #[Test]
    public function all_values_of_a_record_are_the_whole_ones(): void
    {
        $reads = 0;

        self::assertSame(self::WHOLE[7], $this->narrow($reads)->valuesOf(7));
        self::assertSame(1, $reads);
    }

    #[Test]
    public function a_value_put_in_stays_when_the_node_is_read_whole_afterwards(): void
    {
        $reads = 0;
        $table = $this->narrow($reads)->with(8, 1, 'new');

        self::assertSame('new', $table->first(8, 1));
        self::assertSame(0, $reads);
        self::assertSame('only long', $table->first(8, 3));
        self::assertSame('new', $table->first(8, 1));
    }

    private function narrow(int &$reads): Table
    {
        $only = [1 => true, 2 => true];

        return new Table(
            array_map(static fn (array $values): array => array_intersect_key($values, $only), self::WHOLE),
            $only,
            static function () use (&$reads): array {
                $reads++;

                return self::WHOLE;
            }
        );
    }
}
