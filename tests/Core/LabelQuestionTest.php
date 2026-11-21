<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LabelQuestion;
use TaxmodStock\Core\LocationTree;

final class LabelQuestionTest extends TestCase
{
    private const GROUPS = [20 => ['parent' => 10], 30 => ['parent' => 20], 10 => ['parent' => 1]];

    private const FORMS = [60 => ['parent' => 50], 50 => ['parent' => 5]];

    #[Test]
    public function a_path_runs_from_the_node_up_to_the_root_of_its_tree(): void
    {
        self::assertSame([30, 20, 10, 1], LabelQuestion::path(self::GROUPS, 30));
        self::assertSame([], LabelQuestion::path(self::GROUPS, 0));
    }

    #[Test]
    public function a_path_never_runs_in_a_circle(): void
    {
        self::assertSame([7, 8], LabelQuestion::path([7 => ['parent' => 8], 8 => ['parent' => 7]], 7));
    }

    #[Test]
    public function a_material_is_asked_by_its_groups_and_a_lot_by_its_own_node(): void
    {
        $material = LabelQuestion::forMaterial(self::GROUPS, 30, self::FORMS, 60);
        $lot      = LabelQuestion::forLot(900, self::GROUPS, 30, self::FORMS, 60);

        self::assertSame([30, 20, 10, 1], $material['nodes']);
        self::assertSame([60, 50, 5], $material['forms']);
        self::assertSame([900], $lot['nodes']);
        self::assertSame($material['groups'], $lot['groups']);
        self::assertSame(['nodes' => [400], 'place_type' => 48122], LabelQuestion::forPlace(400, 48122));
        // D-1563 (2): with the place, the filters read its values.
        self::assertSame(['nodes' => [400], 'place_type' => 48122, 'record' => 9001, 'groups' => [9001, 400]], LabelQuestion::forPlace(400, 48122, 9001));
        // …and its branch, the place first, then the places above it, then «Lagerorte»: what a rule's node is asked against.
        self::assertSame([9001, 77, 70, 400], LabelQuestion::forPlace(400, 48122, 9001, [77, 70])['groups']);
        // D-1570: the place type's label and count ride along for a rule that reads them; empty ones are not sent.
        self::assertSame([350 => 62, 351 => 2], LabelQuestion::forPlace(400, 48122, 9001, [], [350 => 62, 351 => 2])['from']);
        self::assertSame([350 => 62], LabelQuestion::forPlace(400, 48122, 9001, [], [350 => 62, 351 => 0])['from']);
        self::assertArrayNotHasKey('from', LabelQuestion::forPlace(400, 48122, 9001, [], [350 => 0]));
    }

    #[Test]
    public function where_the_stock_lies_rides_along_with_every_place_above_it(): void
    {
        // D-1576: a box (2) in a cupboard (1) with a compartment (4); another shelf (5).
        $tree = new LocationTree([1 => 'Schrank', 2 => 'SMD R&C', 4 => 'Fach', 5 => 'Regal'], [1 => 0, 2 => 1, 4 => 2, 5 => 0]);

        self::assertSame(['stock' => [120 => ['4', '2', '1']]], LabelQuestion::lyingAt(120, [4], $tree), 'a lot in the compartment lies below the box');
        self::assertSame(['stock' => [120 => ['2', '1', '5']]], LabelQuestion::lyingAt(120, [2, 5, 2], $tree), 'a material: every place of its lots, each once');
        self::assertSame([], LabelQuestion::lyingAt(120, [], $tree), 'a material with nothing in stock sends nothing');
        self::assertSame([], LabelQuestion::lyingAt(120, [0], $tree), 'a lot that names no place');
        self::assertSame([], LabelQuestion::lyingAt(0, [4], $tree), 'no «Lagerort» field in this model');
    }
}
