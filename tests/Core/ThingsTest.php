<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LagerChanges;
use TaxmodStock\Core\LagerInput;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\ReceiptPlan;
use TaxmodStock\Core\SourceRow;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\Things;

/** D-995: the thing is the material — named by its own label, its number its record id; the Lager view hangs on it. */
final class ThingsTest extends TestCase
{
    #[Test]
    public function a_thing_is_shown_with_its_own_name_and_its_number_beside_it(): void
    {
        $things = new Things(Fixture::structure(), $this->lager(), [43101 => 'BC640', 43102 => ''], [43101 => 3640, 43102 => 3640]);

        self::assertSame('BC640 (Nr. 43101)', $things->name(43101));
        self::assertSame('(Nr. 43102)', $things->name(43102));
        self::assertSame([43102, 43101], array_keys($things->all()), 'sorted by name, the nameless first');
        self::assertSame(43124, $things->lagerOf(43101), 'the oldest Lager record of the thing');
        self::assertNull($things->lagerOf(43102));
        self::assertSame(3640, $things->nodeOf(43101));
    }

    #[Test]
    public function the_things_known_are_those_a_lot_or_a_lager_record_names(): void
    {
        $s      = Fixture::structure();
        $ledger = new Ledger($s, new Table([11 => [$s->field('lot_thing') => ['43102'], $s->field('lot_date') => ['2026-10-01']]]), new Table([]));

        self::assertEqualsCanonicalizing([43102, 43101], Things::known($s, $this->lager(), $ledger));
    }

    #[Test]
    public function the_receipt_keeps_the_modell_and_leaves_the_choice_without_one(): void
    {
        $s         = Fixture::structure();
        $orders    = new Table([70 => [$s->field('order_lines') => ['71', '73', '75']]]);
        $purchases = new Table([
            71 => [$s->field('purchase_model') => ['43101']],
            73 => [],
            75 => [$s->field('purchase_undetermined') => ['1'], $s->field('purchase_model') => ['43101']],
        ]);

        $plan = (new ReceiptPlan($s))->of(70, $orders, $purchases, []);

        self::assertTrue($plan[71]['proposal']?->keep, 'the Modell is the thing');
        self::assertSame(43101, $plan[71]['model']);
        self::assertNull($plan[73]['proposal'], 'no Modell: the user chooses');
        self::assertSame('purchase_undetermined', $plan[75]['lock']);
        self::assertSame('received', (new ReceiptPlan($s))->of(70, $orders, $purchases, [71 => true])[71]['lock']);
    }

    #[Test]
    public function saving_the_lager_tab_creates_the_lager_record_once_and_then_updates_it(): void
    {
        $s       = Fixture::structure();
        $changes = new LagerChanges($s);

        $create = $changes->save(43101, null, new LagerInput(true, null, 5, 3));
        self::assertSame(['op' => 'create_record', 'ref' => 'lager', 'node' => 1], $create[0]);
        self::assertSame('43101', $create[1]['values'][(string) $s->field('lager_thing')]);
        self::assertSame('1', $create[1]['values'][(string) $s->field('lager_single')]);
        self::assertSame('3', $create[1]['values'][(string) $s->field('lager_min')]);
        self::assertArrayNotHasKey((string) $s->field('lager_use'), $create[1]['values'], 'nothing empty on a new record');
        self::assertArrayNotHasKey((string) $s->field('label'), $create[1]['values'], 'D-996: the name stays with the thing');

        $update = $changes->save(43101, 43124, new LagerInput(false, 9001, null, null))[0];
        self::assertSame(43124, $update['record']);
        self::assertSame('', $update['values'][(string) $s->field('lager_min')], 'an empty value clears it');
        self::assertSame('9001', $update['values'][(string) $s->field('lager_use')]);
    }

    #[Test]
    public function the_sources_of_a_thing_are_parts_of_its_lager_record(): void
    {
        $s       = Fixture::structure();
        $sources = (string) $s->field('lager_sources');
        $firm    = (string) $s->field('source_firm');
        $number  = (string) $s->field('source_number');
        $rows    = [new SourceRow(51, 35197, 'K104K15X7RF53H5'), new SourceRow(52, null, ''), new SourceRow(null, 39497, 'C15849'), new SourceRow(null, null, '')];

        $update = (new LagerChanges($s))->save(43101, 43124, new LagerInput(false, null, null, null, $rows));

        self::assertSame(['51' => [$firm => '35197', $number => 'K104K15X7RF53H5'], 'new2' => [$firm => '39497', $number => 'C15849']], $update[0]['values'][$sources]);
        self::assertSame(['op' => 'remove_record', 'record' => 52], $update[1], 'an emptied line removes its part');
        self::assertCount(2, $update, 'an empty new line writes nothing');

        $create = (new LagerChanges($s))->save(43101, null, new LagerInput(false, null, null, null, [new SourceRow(null, 35197, 'X')]));
        self::assertSame(['new0' => [$firm => '35197', $number => 'X']], $create[1]['values'][$sources]);

        $none = (new LagerChanges($s))->save(43101, 43124, new LagerInput(false, null, null, null));
        self::assertArrayNotHasKey($sources, $none[0]['values'], 'a form without the lines leaves the sources alone');
    }

    #[Test]
    public function a_structure_pasted_before_0_2_0_still_reads(): void
    {
        $raw = Fixture::structure()->toArray();
        $old = $raw;

        $old['nodes']['material'] = $old['nodes']['lager'];
        unset($old['nodes']['lager']);
        $old['fields']['lot_material']        = $old['fields']['lot_thing'];
        $old['fields']['material_ref_record'] = $old['fields']['lager_thing'];
        unset($old['fields']['lot_thing'], $old['fields']['lager_thing']);

        $read = Structure::fromArray($old);

        self::assertSame($raw['nodes']['lager'], $read->node('lager'));
        self::assertSame($raw['fields']['lot_thing'], $read->field('lot_thing'), 'the same id: the field was retargeted, not replaced');
        self::assertSame($raw['fields']['lager_thing'], $read->field('lager_thing'));
    }

    private function lager(): Table
    {
        $s = Fixture::structure();

        return new Table([
            43130 => [$s->field('lager_thing') => ['43101']],
            43124 => [$s->field('lager_thing') => ['43101'], $s->field('lager_min') => ['10']],
        ]);
    }
}
