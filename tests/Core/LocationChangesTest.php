<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LocationChanges;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\PlaceTypes;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

final class LocationChangesTest extends TestCase
{
    #[Test]
    public function a_box_of_a_project_is_created_inside_its_place(): void
    {
        $s = Fixture::structure();

        self::assertSame([
            ['op' => 'create_record', 'ref' => 'ort', 'node' => (string) $s->node('location')],
            ['op' => 'set_values', 'record' => '@ort', 'values' => [
                (string) $s->field('label')            => 'Box BT2PS2',
                (string) $s->field('location_parent')  => '4',
                (string) $s->field('location_movable') => '1',
                (string) $s->field('location_project') => '900',
            ]],
        ], (new LocationChanges($s))->create(' Box BT2PS2 ', 4, true, 900, $this->tree(), $this->places()));
    }

    #[Test]
    public function a_place_moves_by_its_parent_only(): void
    {
        $s = Fixture::structure();

        self::assertSame([['op' => 'set_values', 'record' => '5', 'values' => [(string) $s->field('location_parent') => '1']]],
            (new LocationChanges($s))->move(5, 1, $this->tree(), $this->places()));
    }

    #[Test]
    public function a_place_cannot_go_into_what_it_holds(): void
    {
        $this->expectException(StockError::class);
        (new LocationChanges(Fixture::structure()))->move(4, 5, $this->tree(), $this->places());
    }

    #[Test]
    public function an_empty_place_gets_the_places_its_type_brings_under_its_own_id(): void
    {
        $raw = Fixture::structure()->toArray();
        $raw['fields'] += ['location_place_type' => 300];
        $s       = Structure::fromArray($raw);
        $types   = new PlaceTypes([1 => 'IKEA Alex', 2 => 'Schublade Alex'], [], [1 => [[2, 2, 'Schublade']]]);
        $changes = (new LocationChanges($s))->bringInto(5, 1, $types, $this->tree(), $this->places());
        $made    = array_values(array_filter($changes, static fn (array $c): bool => $c['op'] === 'set_values'));

        self::assertSame(['Schublade 1', 'Schublade 2'], array_map(static fn (array $c): string => $c['values'][(string) $s->field('label')], $made));
        self::assertSame(['5', '5'], array_map(static fn (array $c): string => $c['values'][(string) $s->field('location_parent')], $made));

        // A place with places inside gets nothing: Obergeschoss holds the box.
        $this->expectException(StockError::class);
        (new LocationChanges($s))->bringInto(4, 1, $types, $this->tree(), $this->places());
    }

    private function tree(): LocationTree
    {
        return new LocationTree([1 => 'Keller', 4 => 'Obergeschoss', 5 => 'Box'], [1 => 0, 4 => 0, 5 => 4]);
    }

    private function places(): Table
    {
        return new Table([1 => [], 4 => [], 5 => []]);
    }
}
