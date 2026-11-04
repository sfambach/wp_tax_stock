<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LocationChanges;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\MaterialCopy;
use TaxmodStock\Core\NamedCopy;
use TaxmodStock\Core\PlaceTypes;
use TaxmodStock\Core\SourceRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** D-1130: «Kopieren» of a place and of a material — one change list each, nothing in stock comes along. */
final class CopyTest extends TestCase
{
    #[Test]
    public function a_place_is_copied_beside_itself_under_its_new_name(): void
    {
        $s = Fixture::structure();

        self::assertSame([
            ['op' => 'duplicate_record', 'ref' => 'kopie0', 'record' => '4'],
            ['op' => 'set_values', 'record' => '@kopie0', 'values' => [(string) $s->field('label') => 'Regal (Kopie)']],
        ], (new LocationChanges($s))->copy(4, ' Regal (Kopie) ', false, $this->tree(), $this->places()));
    }

    #[Test]
    public function with_its_places_inside_each_copy_hangs_in_the_copy_of_its_parent(): void
    {
        $s      = Fixture::structure();
        $parent = (string) $s->field('location_parent');

        self::assertSame([
            ['op' => 'duplicate_record', 'ref' => 'kopie0', 'record' => '4'],
            ['op' => 'set_values', 'record' => '@kopie0', 'values' => [(string) $s->field('label') => 'Regal 2']],
            ['op' => 'duplicate_record', 'ref' => 'kopie1', 'record' => '5'],
            ['op' => 'set_values', 'record' => '@kopie1', 'values' => [$parent => '@kopie0']],
            ['op' => 'duplicate_record', 'ref' => 'kopie2', 'record' => '6'],
            ['op' => 'set_values', 'record' => '@kopie2', 'values' => [$parent => '@kopie1']],
        ], (new LocationChanges($s))->copy(4, 'Regal 2', true, $this->tree(), $this->places()));
    }

    #[Test]
    public function a_copy_needs_a_name_and_a_place(): void
    {
        $changes = new LocationChanges(Fixture::structure());

        foreach ([[4, ' '], [99, 'X']] as [$place, $name]) {
            try {
                $changes->copy($place, $name, false, $this->tree(), $this->places());
                self::fail('copy accepted ' . $place);
            } catch (StockError $e) {
                self::assertContains($e->reason, ['place_without_name', 'not_a_location']);
            }
        }
    }

    #[Test]
    public function a_material_brings_its_lager_view_but_no_order_number(): void
    {
        $s     = Fixture::structure();
        $lager = new Table([30 => [
            $s->field('lager_thing')    => ['7'],
            $s->field('lager_single')   => ['1'],
            $s->field('lager_min')      => ['5'],
            $s->field('lager_location') => ['4'],
        ]]);

        self::assertSame([
            ['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => '7'],
            ['op' => 'set_values', 'record' => '@kopie', 'values' => [(string) $s->field('label') => 'Lötpaste (Kopie)']],
            ['op' => 'create_record', 'ref' => 'kopielager', 'node' => $s->node('lager')],
            ['op' => 'set_values', 'record' => '@kopielager', 'values' => [
                (string) $s->field('lager_thing')    => '@kopie',
                (string) $s->field('lager_single')   => '1',
                (string) $s->field('lager_min')      => '5',
                (string) $s->field('lager_location') => '4',
                (string) $s->field('lager_sources')  => ['new0' => [
                    (string) $s->field('source_firm')    => '11',
                    (string) $s->field('source_number')  => '',
                    (string) $s->field('source_content') => '10',
                ]],
            ]],
        ], (new MaterialCopy($s))->copy(7, 30, $lager, [new SourceRow(40, 11, 'RS-123', '10')], 'Lötpaste (Kopie)'));
    }

    #[Test]
    public function a_material_without_lager_view_or_own_label_is_only_duplicated(): void
    {
        self::assertSame([['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => '7']],
            (new MaterialCopy(Fixture::structure()))->copy(7, null, new Table([]), [], null));
    }

    #[Test]
    public function a_kind_of_place_is_copied_with_its_parts_under_its_new_name(): void
    {
        $s = Fixture::structure();

        self::assertSame([
            ['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => '20'],
            ['op' => 'set_values', 'record' => '@kopie', 'values' => [(string) $s->field('label') => 'Alex (Kopie)']],
        ], (new LocationChanges($s))->copyType(20, ' Alex (Kopie) ', new PlaceTypes([20 => 'Alex'])));

        $this->expectException(StockError::class);
        (new LocationChanges($s))->copyType(21, 'X', new PlaceTypes([20 => 'Alex']));
    }

    #[Test]
    public function any_named_record_is_copied_under_its_new_name(): void
    {
        $s = Fixture::structure();

        self::assertSame([
            ['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => '30'],
            ['op' => 'set_values', 'record' => '@kopie', 'values' => [(string) $s->field('label') => 'Halter 62 × 50 (Kopie)']],
        ], (new NamedCopy($s))->of(30, 'Halter 62 × 50 (Kopie)'));

        $this->expectException(StockError::class);
        (new NamedCopy($s))->of(30, '  ');
    }

    private function tree(): LocationTree
    {
        return new LocationTree([1 => 'Keller', 4 => 'Regal', 5 => 'Fach A', 6 => 'Box'], [1 => 0, 4 => 0, 5 => 4, 6 => 5]);
    }

    private function places(): Table
    {
        return new Table([1 => [], 4 => [], 5 => [], 6 => []]);
    }
}
