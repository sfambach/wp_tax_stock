<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\MaterialChanges;
use TaxmodStock\Core\MaterialInput;
use TaxmodStock\Core\MaterialNames;
use TaxmodStock\Core\ReceiptPlan;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/** D-967: a material is «#id · name of its Bezug»; the goods receipt proposes the material that refers to the Modell. */
final class MaterialTest extends TestCase
{
    #[Test]
    public function a_material_is_shown_as_its_number_and_the_name_of_its_bezug(): void
    {
        $names = $this->names();

        self::assertSame('#43124 · BC640', $names->name(43124), 'the referenced thing names it, not the stale own name');
        self::assertSame('#43200 · Lötzinn', $names->name(43200), 'without a Bezug its own name');
        self::assertSame('#43201', $names->name(43201));
        self::assertSame([43201, 43124, 43200], array_keys($names->all()), 'sorted by name, the nameless first');
        self::assertSame([43101], MaterialNames::referencedThings(Fixture::structure(), $this->materials()));
    }

    #[Test]
    public function the_receipt_proposes_an_existing_material_a_new_one_or_leaves_the_choice(): void
    {
        $s         = Fixture::structure();
        $orders    = new Table([70 => [$s->field('order_lines') => ['71', '72', '73', '74', '75']]]);
        $purchases = new Table([
            71 => [$s->field('purchase_model') => ['43101']],
            72 => [$s->field('purchase_model') => ['43102']],
            73 => [],
            74 => [$s->field('purchase_material') => ['43200']],
            75 => [$s->field('purchase_undetermined') => ['1'], $s->field('purchase_model') => ['43101']],
        ]);

        $plan = (new ReceiptPlan($s))->of(70, $orders, $purchases, $this->names(), []);

        self::assertSame(43124, $plan[71]['proposal']?->material, 'Modell 43101 has material 43124');
        self::assertTrue($plan[72]['proposal']?->isNew(), 'no material refers to 43102: a new one');
        self::assertSame(43102, $plan[72]['proposal']->thing);
        self::assertNull($plan[73]['proposal'], 'neither Modell nor Material: the user chooses');
        self::assertNull($plan[73]['lock']);
        self::assertTrue($plan[74]['proposal']?->keep);
        self::assertSame('purchase_undetermined', $plan[75]['lock']);

        $received = (new ReceiptPlan($s))->of(70, $orders, $purchases, $this->names(), [71 => true]);
        self::assertSame('received', $received[71]['lock']);
    }

    #[Test]
    public function editing_a_material_with_a_bezug_empties_its_own_name(): void
    {
        $s       = Fixture::structure();
        $changes = new MaterialChanges($s);

        $update = $changes->update(43200, new MaterialInput(43101, 'Lötzinn', false, 9001, null, null))[0]['values'];
        self::assertSame('', $update[(string) $s->field('label')], 'an empty value clears it');
        self::assertSame('43101', $update[(string) $s->field('material_ref_record')]);
        self::assertSame('9001', $update[(string) $s->field('material_use')]);
        self::assertSame('', $update[(string) $s->field('material_min')]);

        $create = $changes->create(new MaterialInput(null, 'Kartons', true, null, 5, 3))[1]['values'];
        self::assertSame('Kartons', $create[(string) $s->field('label')]);
        self::assertSame('1', $create[(string) $s->field('material_single')]);
        self::assertSame('3', $create[(string) $s->field('material_min')]);
        self::assertArrayNotHasKey((string) $s->field('material_ref_record'), $create, 'nothing empty on a new record');

        $this->expectException(StockError::class);
        $changes->create(new MaterialInput(null, ' ', false, null, null, null));
    }

    private function materials(): Table
    {
        $s = Fixture::structure();

        return new Table([
            43124 => [$s->field('label') => ['BC640 alt'], $s->field('material_ref_record') => ['43101']],
            43200 => [$s->field('label') => ['Lötzinn']],
            43201 => [],
        ]);
    }

    private function names(): MaterialNames
    {
        return new MaterialNames(Fixture::structure(), $this->materials(), [43101 => 'BC640']);
    }
}
