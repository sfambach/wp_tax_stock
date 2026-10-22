<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LocationRules;
use TaxmodStock\Core\LocationSettings;

/** D-972: the material's own place, else the nearest listed branch, else «ohne Bezug», else the default. */
final class LocationRulesTest extends TestCase
{
    private function rules(): LocationRules
    {
        // Branch 10 (PC) → place 1; its child 11 (Grafikkarten) → place 2; 12 is a grandchild of 10 below 11.
        return new LocationRules(
            [10 => 1, 11 => 2, 20 => 3],
            [10 => [10 => 0, 11 => 1, 12 => 2, 13 => 1], 11 => [11 => 0, 12 => 1], 20 => [20 => 0, 21 => 1]],
            4,
            99
        );
    }

    #[Test]
    public function the_materials_own_place_wins(): void
    {
        self::assertSame(7, $this->rules()->propose(7, 21));
    }

    #[Test]
    public function the_nearest_listed_branch_wins(): void
    {
        self::assertSame(2, $this->rules()->propose(null, 12), 'Grafikkarten is nearer than PC');
        self::assertSame(1, $this->rules()->propose(null, 13));
        self::assertSame(3, $this->rules()->propose(null, 21));
        self::assertSame(1, $this->rules()->propose(null, 10), 'the branch node itself');
    }

    #[Test]
    public function without_a_thing_it_is_the_unreferenced_place_and_outside_every_branch_the_default(): void
    {
        self::assertSame(4, $this->rules()->propose(null, null));
        self::assertSame(99, $this->rules()->propose(null, 500));
        self::assertSame(99, (new LocationRules([], [], null, 99))->propose(null, null));
    }

    #[Test]
    public function settings_keep_only_whole_positive_ids(): void
    {
        $settings = LocationSettings::fromArray(['branches' => ['10' => '1', 'x' => 2, 11 => 0, 12 => '3'], 'unreferenced' => '0']);

        self::assertSame([10 => 1, 12 => 3], $settings->branches);
        self::assertNull($settings->unreferenced);
    }
}
