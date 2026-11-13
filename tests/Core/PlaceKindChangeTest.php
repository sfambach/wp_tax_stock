<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PlaceKindChange;
use TaxmodStock\Core\StockError;

/** D-1315: where the places inside go when the kind of a place changes. */
final class PlaceKindChangeTest extends TestCase
{
    #[Test]
    public function nothing_inside_asks_nothing_and_otherwise_every_way_is_offered_none_preselected(): void
    {
        self::assertSame([], PlaceKindChange::ways(0));
        self::assertSame([PlaceKindChange::WAY_PARENT, PlaceKindChange::WAY_FIT, PlaceKindChange::WAY_SPREAD], PlaceKindChange::ways(2));
        self::assertSame([], PlaceKindChange::targets([], $this->new(3), ''));
    }

    #[Test]
    public function without_a_way_chosen_it_is_refused(): void
    {
        $this->expectException(StockError::class);
        PlaceKindChange::targets($this->old(2), $this->new(2), '');
    }

    #[Test]
    public function equal_counts_go_one_to_one_one_level_down_too(): void
    {
        // Old: 10 (with 11, 12 inside), 20. New: teil1 (teil2, teil3 inside), teil4.
        $old = [['id' => 10, 'below' => [['id' => 11, 'below' => []], ['id' => 12, 'below' => []]]], ['id' => 20, 'below' => []]];
        $new = [['type' => 1, 'name' => 'A 1', 'below' => $this->new(2)], ['type' => 1, 'name' => 'A 2', 'below' => []]];

        self::assertSame([10 => 'teil1', 11 => 'teil2', 12 => 'teil3', 20 => 'teil4'], PlaceKindChange::targets($old, $new, PlaceKindChange::WAY_FIT));
    }

    #[Test]
    public function as_much_as_fits_and_the_rest_onto_the_place_itself(): void
    {
        self::assertSame([1 => 'teil1', 2 => 'teil2', 3 => ''], PlaceKindChange::targets($this->old(3), $this->new(2), PlaceKindChange::WAY_FIT));
    }

    #[Test]
    public function spread_goes_round_the_new_places_again(): void
    {
        self::assertSame([1 => 'teil1', 2 => 'teil2', 3 => 'teil1'], PlaceKindChange::targets($this->old(3), $this->new(2), PlaceKindChange::WAY_SPREAD));
    }

    #[Test]
    public function onto_the_place_itself_takes_everything_also_below(): void
    {
        $old = [['id' => 10, 'below' => [['id' => 11, 'below' => []]]]];

        self::assertSame([10 => '', 11 => ''], PlaceKindChange::targets($old, $this->new(2), PlaceKindChange::WAY_PARENT));
        self::assertSame([10 => '', 11 => ''], PlaceKindChange::targets($old, [], PlaceKindChange::WAY_SPREAD));
    }

    /** @return list<array{id: int, below: list<array<string, mixed>>}> */
    private function old(int $n): array
    {
        return array_map(static fn (int $i): array => ['id' => $i, 'below' => []], range(1, $n));
    }

    /** @return list<array{type: int, name: string, below: list<array<string, mixed>>}> */
    private function new(int $n): array
    {
        return array_map(static fn (int $i): array => ['type' => 1, 'name' => 'Fach ' . $i, 'below' => []], range(1, $n));
    }
}
