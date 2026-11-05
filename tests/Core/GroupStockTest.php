<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\GroupStock;

/** D-1174: «Gruppenbestand» — a node of the material tree is a group; its stock holds every group below it. */
final class GroupStockTest extends TestCase
{
    #[Test]
    public function a_branch_holds_the_group_and_every_group_below_it_and_nothing_beside_it(): void
    {
        $parents = [10 => 1, 11 => 10, 12 => 11, 13 => 10, 20 => 1];

        self::assertSame([10, 11, 13, 12], array_keys(GroupStock::branch($parents, 10)));
        self::assertSame([12], array_keys(GroupStock::branch($parents, 12)), 'a leaf is its own branch');
        self::assertArrayNotHasKey(20, GroupStock::branch($parents, 10));
    }

    #[Test]
    public function a_loop_in_the_tree_ends_the_walk(): void
    {
        self::assertSame([10, 11], array_keys(GroupStock::branch([10 => 11, 11 => 10], 10)));
    }

    #[Test]
    public function the_totals_count_materials_with_stock_and_below_minimum_and_sum_one_unit(): void
    {
        $totals = GroupStock::totals([
            ['stock' => 3.0, 'low' => false, 'unit' => 'Stück'],
            ['stock' => 0.0, 'low' => true, 'unit' => 'm'],
            ['stock' => 2.5, 'low' => true, 'unit' => 'Stück'],
        ]);

        self::assertSame(['materials' => 3, 'inStock' => 2, 'low' => 2, 'quantity' => 5.5], $totals);
    }

    #[Test]
    public function mixed_units_are_not_summed(): void
    {
        self::assertNull(GroupStock::totals([['stock' => 3.0, 'low' => false, 'unit' => 'Stück'], ['stock' => 1.0, 'low' => false, 'unit' => 'm']])['quantity']);
        self::assertSame(0.0, GroupStock::totals([])['quantity']);
    }
}
