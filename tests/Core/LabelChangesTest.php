<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LabelChanges;
use TaxmodStock\Core\PlaceTypes;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1239: a label created, changed and removed as one change list, its rolls kept in step. */
final class LabelChangesTest extends TestCase
{
    #[Test]
    public function a_new_label_is_one_list_with_its_values_and_the_rolls_that_allow_it(): void
    {
        $s       = $this->structure();
        $changes = (new LabelChanges($s))->create(40, ['name' => '40×12 SMD', 'key' => '40x12', 'width' => 40, 'height' => 12, 'layout' => '[]', 'unknown' => 'x'], [7], $this->rolls());

        self::assertSame([
            ['op' => 'create_record', 'ref' => 'etikett', 'node' => '40'],
            ['op' => 'set_values', 'record' => '@etikett', 'values' => [(string) $s->field('label') => '40×12 SMD', '400' => '40x12', '401' => '40', '402' => '12', '406' => '[]']],
            ['op' => 'set_values', 'record' => '7', 'values' => ['441' => ['values' => ['90', '91', '@etikett']]]],
        ], $changes);
    }

    #[Test]
    public function a_change_writes_only_what_is_given_and_a_roll_that_already_allows_it_stays(): void
    {
        $changes = (new LabelChanges($this->structure()))->update(90, ['copies' => 2], [7], $this->rolls());

        self::assertSame([['op' => 'set_values', 'record' => '90', 'values' => ['405' => '2']]], $changes);
        self::assertSame([], (new LabelChanges($this->structure()))->update(90, [], [7], $this->rolls()));
    }

    #[Test]
    public function a_size_is_a_whole_number_above_zero_and_a_label_has_a_name(): void
    {
        $changes = new LabelChanges($this->structure());

        foreach ([['width' => 0], ['height' => '12.5'], ['copies' => 'zwei'], ['name' => ' ']] as $given) {
            try {
                $changes->values($given);
                self::fail('accepted ' . json_encode($given));
            } catch (StockError $e) {
                self::assertContains($e->reason, ['not_a_label_size', 'label_without_name']);
            }
        }
        $this->expectException(StockError::class);
        $changes->create(40, ['width' => 40]);
    }

    #[Test]
    public function an_unknown_roll_is_refused(): void
    {
        $this->expectException(StockError::class);
        (new LabelChanges($this->structure()))->update(90, [], [8], $this->rolls());
    }

    #[Test]
    public function a_removed_label_leaves_its_rolls_first(): void
    {
        $changes = (new LabelChanges($this->structure()))->remove(90, $this->rolls());

        self::assertSame([
            ['op' => 'set_values', 'record' => '7', 'values' => ['441' => ['values' => ['91']]]],
            ['op' => 'remove_record', 'record' => '90'],
        ], $changes);
        self::assertSame([7], (new LabelChanges($this->structure()))->rollsAllowing(91, $this->rolls()));
    }

    #[Test]
    public function a_label_a_place_type_prints_with_is_not_removed(): void
    {
        $types = new PlaceTypes([1 => 'Schublade'], [], [], [1 => [90, 0]]);

        try {
            (new LabelChanges($this->structure()))->remove(90, $this->rolls(), $types);
            self::fail('removed a label in use');
        } catch (StockError $e) {
            self::assertSame(['label_in_use', 'Schublade'], [$e->reason, $e->detail]);
        }
    }

    private function rolls(): Table
    {
        return new Table([7 => [441 => ['90', '91']]]);
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['nodes'] += ['label_template' => 23, 'label_roll' => 24];
        $raw['fields'] += ['template_key' => 400, 'template_width' => 401, 'template_height' => 402, 'template_roll' => 403,
            'template_copies' => 405, 'template_layout' => 406, 'roll_labels' => 441];

        return Structure::fromArray($raw);
    }
}
