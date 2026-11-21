<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\TakeOver;
use TaxmodStock\Core\TestRules;

/** D-1579: an empty group takes materials over from the group above it — one rule of the rule book, ticks, one act. */
final class TakeOverTest extends TestCase
{
    #[Test]
    public function the_rows_become_one_rule_on_the_parent_with_only_the_offered_fields(): void
    {
        $take = TakeOver::fromRows(10, [
            ['field' => 5, 'op' => 'contains', 'value' => ' blau '],
            ['field' => 9, 'op' => 'equals', 'value' => 'x'],
            ['field' => 6, 'op' => 'below', 'value' => '3'],
        ], [5, 6]);

        self::assertSame([['id' => 1, 'position' => 0, 'conditions' => [TestRules::NODE => 10], 'filters' => [
            ['field' => 5, 'value' => 'blau', 'operator' => 'contains'],
            ['field' => 6, 'value' => '3', 'operator' => 'equals'],
        ]]], $take->book(), 'a field not offered is left out; «liegt unter» is not offered, so it is «gleich»');
        self::assertSame([5, 6], $take->fields());
    }

    #[Test]
    public function at_most_three_rows_count(): void
    {
        $rows = array_fill(0, 5, ['field' => 5, 'op' => 'equals', 'value' => 'a']);

        self::assertCount(TakeOver::ROWS, TakeOver::fromRows(10, $rows, [5])->filters);
    }

    #[Test]
    public function an_untick_survives_turning_the_page_and_a_tick_again_takes_it_back(): void
    {
        $skipped = TakeOver::skipped([], [1, 2, 3], [1, 3]);
        self::assertSame([2], $skipped);

        $skipped = TakeOver::skipped($skipped, [4, 5], [4]);
        self::assertSame([2, 5], $skipped, 'page two forgets nothing of page one');

        self::assertSame([5], TakeOver::skipped($skipped, [1, 2, 3], [1, 2, 3]), 'ticked again on its page, it goes along');
        self::assertSame([1, 3, 4, 6], TakeOver::chosen([1, 2, 3, 4, 5, 6], [2, 5]), 'a page never seen takes everything it holds');
    }

    #[Test]
    public function the_button_stands_only_at_an_empty_group_with_a_group_above_it(): void
    {
        $parents = [1 => 0, 10 => 1, 11 => 10, 12 => 11];
        $groups  = array_fill_keys(array_keys($parents), true);

        self::assertTrue(TakeOver::offered(11, $parents, $groups, [10 => true]));
        self::assertFalse(TakeOver::offered(11, $parents, $groups, [12 => true]), 'a material further down: not empty');
        self::assertFalse(TakeOver::offered(1, $parents, $groups, []), 'the root has no group above it');
        self::assertFalse(TakeOver::offered(99, $parents, $groups, []), 'not a group of the tree');
    }
}
