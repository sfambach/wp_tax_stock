<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LagerMerge;
use TaxmodStock\Core\Table;

/** D-1480: what the stock answers when the modeller merges a doubled material. */
final class LagerMergeTest extends TestCase
{
    #[Test]
    public function the_doubled_lager_record_goes_to_the_trash_when_nothing_of_it_is_lost(): void
    {
        $s     = Fixture::structure();
        $merge = new LagerMerge($s);
        $f     = static fn (string $key): int => $s->field($key);

        $lager = new Table([
            11 => [$f('lager_thing') => ['500'], $f('lager_min') => ['5'], $f('lager_location') => ['70']],
            12 => [$f('lager_thing') => ['600'], $f('lager_min') => ['5']],
            13 => [$f('lager_thing') => ['700'], $f('lager_min') => ['8'], $f('lager_unit') => ['90']],
        ]);

        self::assertSame(['trash' => [12]], $merge->answer(600, 500, $lager), 'nothing of #12 that #11 holds differently');
        self::assertSame([], $merge->answer(600, 900, $lager), 'into without a Lager record takes over the one of from');
        self::assertSame([], $merge->answer(900, 500, $lager), 'from without a Lager record: nothing to do');
        self::assertSame(
            ['veto' => 'Lager-Stammsatz #13 und #11: Mindestbestand, Einheit weichen ab.'],
            $merge->answer(700, 500, $lager)
        );
    }
}
