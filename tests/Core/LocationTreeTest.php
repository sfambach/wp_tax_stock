<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LocationTree;

/** D-1030: places form a tree; a box can belong to a project, and so does its content. */
final class LocationTreeTest extends TestCase
{
    private function tree(): LocationTree
    {
        return new LocationTree(
            [1 => 'Keller', 2 => 'Bauteile', 3 => 'Regal 2', 4 => 'Obergeschoss', 5 => 'Box BT2PS2', 6 => 'Tüte'],
            [1 => 0, 2 => 1, 3 => 2, 4 => 0, 5 => 4, 6 => 5],
            [5 => 'BT2PS2'],
            [5 => true, 6 => true],
        );
    }

    #[Test]
    public function a_place_reads_as_its_path_and_inherits_the_project_of_its_box(): void
    {
        $tree = $this->tree();

        self::assertSame('Keller › Bauteile › Regal 2', $tree->pathOf(3));
        self::assertSame('BT2PS2', $tree->projectOf(6));
        self::assertNull($tree->projectOf(3));
        self::assertSame('Obergeschoss › Box BT2PS2 › Tüte · BT2PS2', $tree->labels()[6]);
    }

    #[Test]
    public function the_order_puts_parents_before_children_and_siblings_by_name(): void
    {
        self::assertSame([[1, 0], [2, 1], [3, 2], [4, 0], [5, 1], [6, 2]], $this->tree()->ordered());
    }

    #[Test]
    public function everything_below_a_place_belongs_to_it(): void
    {
        $below = $this->tree()->withAllBelow(4);
        sort($below);

        self::assertSame([4, 5, 6], $below);
    }

    #[Test]
    public function the_children_of_a_place_are_only_those_right_inside_it_by_name(): void
    {
        $tree = new LocationTree([1 => 'Schrank', 2 => 'S10', 3 => 'S2', 4 => 'Tüte', 5 => 'Keller'], [1 => 0, 2 => 1, 3 => 1, 4 => 3, 5 => 0]);

        self::assertSame([3, 2], $tree->childrenOf(1));
        self::assertSame([5, 1], $tree->childrenOf(0));
        self::assertSame([], $tree->childrenOf(4));
    }

    #[Test]
    public function a_loop_does_not_hang(): void
    {
        $tree = new LocationTree([1 => 'A', 2 => 'B'], [1 => 2, 2 => 1]);

        self::assertCount(2, $tree->labels() + []);
        self::assertIsString($tree->pathOf(1));
    }
}
