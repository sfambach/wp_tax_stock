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

/** D-1123: kinds of place — Alex with nine drawers, a box that moves. */
final class PlaceTypesTest extends TestCase
{
    #[Test]
    public function a_new_alex_brings_its_nine_drawers(): void
    {
        $s       = $this->structure();
        $changes = (new LocationChanges($s))->create('Alex 2', 0, false, 0, new LocationTree([], []), new Table([]), false, 60, $this->types($s));

        self::assertCount(2 + 9 * 2, $changes);
        self::assertSame('60', $changes[1]['values'][(string) $s->field('location_place_type')]);
        self::assertSame('Schublade Alex 9', $changes[19]['values'][(string) $s->field('label')]);
        self::assertSame('@ort', $changes[19]['values'][(string) $s->field('location_parent')]);
        self::assertSame('61', $changes[19]['values'][(string) $s->field('location_place_type')]);
    }

    #[Test]
    public function the_kind_decides_whether_a_place_moves(): void
    {
        $s     = $this->structure();
        $types = $this->types($s);
        $tree  = LocationTree::from($s, new Table([
            1 => [$s->field('label') => ['Box'], $s->field('location_place_type') => ['62'], $s->field('location_movable') => ['0']],
            2 => [$s->field('label') => ['Regal'], $s->field('location_movable') => ['1']],
        ]), [], $types);

        self::assertTrue($tree->isMovable(1));
        self::assertTrue($tree->isMovable(2));
        self::assertSame('1', (new LocationChanges($s))->create('Box 2', 0, false, 0, $tree, new Table([]), false, 62, $types)[1]['values'][(string) $s->field('location_movable')]);
    }

    #[Test]
    public function a_unit_name_on_the_line_names_the_places_it_brings(): void
    {
        $types = new PlaceTypes([1 => 'IKEA Alex', 2 => 'Schublade Alex'], [], [1 => [[2, 2, 'Schublade']]]);

        self::assertSame(['Schublade 1', 'Schublade 2'], array_column($types->brought(1), 'name'));
        self::assertSame(['Schublade Alex 1'], array_column((new PlaceTypes([1 => 'A', 2 => 'Schublade Alex'], [], [1 => [[2, 1]]]))->brought(1), 'name'));
    }

    #[Test]
    public function the_types_stand_as_a_tree_their_parts_below_them(): void
    {
        $types = new PlaceTypes([1 => 'IKEA Alex', 2 => 'Schublade Alex', 3 => 'Kiste'], [], [1 => [[2, 9]]]);

        self::assertSame([['type' => 1, 'depth' => 0], ['type' => 2, 'depth' => 1], ['type' => 3, 'depth' => 0]], $types->asTree());
    }

    #[Test]
    public function a_kind_that_holds_itself_is_refused(): void
    {
        $this->expectException(StockError::class);
        (new PlaceTypes([1 => 'Kiste'], [1 => true], [1 => [[1, 2]]]))->brought(1);
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['nodes'] += ['location_type' => 20, 'location_type_part' => 21];
        $raw['fields'] += ['location_place_type' => 300, 'type_movable' => 301, 'type_parts' => 302, 'part_type' => 303, 'part_count' => 304];

        return Structure::fromArray($raw);
    }

    #[Test]
    public function the_menge_in_pieces_counts_where_the_structure_names_it(): void
    {
        $raw = $this->structure()->toArray();
        $raw['fields'] += ['part_quantity' => 305];
        $s     = Structure::fromArray($raw);
        $types = PlaceTypes::from($s, new Table([60 => [$s->field('label') => ['IKEA Alex'], 302 => ['70']], 61 => [$s->field('label') => ['Schublade Alex']]]),
            new Table([70 => [303 => ['61'], 304 => ['9'], 305 => ['80']]]), new Table([80 => [$s->field('money_amount') => ['6']]]));

        self::assertSame([[61, 6]], $types->partsOf(60));
    }

    private function types(Structure $s): PlaceTypes
    {
        $label = $s->field('label');

        return PlaceTypes::from($s, new Table([
            60 => [$label => ['IKEA Alex'], 301 => ['0'], 302 => ['70']],
            61 => [$label => ['Schublade Alex'], 301 => ['0']],
            62 => [$label => ['Kiste'], 301 => ['1']],
        ]), new Table([70 => [303 => ['61'], 304 => ['9']]]));
    }
}
