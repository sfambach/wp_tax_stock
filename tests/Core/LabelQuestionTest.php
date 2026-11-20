<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LabelQuestion;

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
    }
}
