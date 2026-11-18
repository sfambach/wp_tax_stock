<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\ViewSettings;

/** «Gleich aufgeklappt» per tab; since D-1440 the goods receipt has nothing left to open. */
final class ViewSettingsTest extends TestCase
{
    #[Test]
    public function nothing_opens_by_default_and_the_goods_receipt_is_no_longer_a_choice(): void
    {
        $views = ViewSettings::fromArray([]);

        self::assertFalse($views->expanded('material'));
        // D-1440: the purchases stand in the rows of «Angekommen»; «wareneingang» is no tab with rows to open any more.
        self::assertFalse($views->expanded('wareneingang'));
        self::assertFalse(ViewSettings::fromArray(['wareneingang' => ['expand' => true]])->expanded('wareneingang'));
    }

    #[Test]
    public function a_stored_choice_wins_over_the_default_and_survives_a_round_trip(): void
    {
        $views = ViewSettings::fromArray(['wareneingang' => ['expand' => false], 'material' => ['expand' => true], 'unknown' => ['expand' => true]]);

        self::assertTrue($views->expanded('material'));
        self::assertSame(['material' => ['expand' => true, 'open_single' => false]], $views->toArray());
        self::assertSame($views->toArray(), ViewSettings::fromArray($views->toArray())->toArray());
    }

    #[Test]
    public function a_single_hit_stays_in_the_list_unless_chosen_and_the_person_wins(): void
    {
        $site = ViewSettings::fromArray([]);
        $on   = ViewSettings::fromArray(['material' => ['open_single' => true]]);

        // D-1468: off unless set; the person's own choice wins over the site's, either way.
        self::assertFalse($site->opensSingleHit('material'));
        self::assertTrue($on->opensSingleHit('material'));
        self::assertTrue($site->opensSingleHit('material', true));
        self::assertFalse($on->opensSingleHit('material', false));
        self::assertTrue($on->opensSingleHit('material', null));
        self::assertSame($on->toArray(), ViewSettings::fromArray($on->toArray())->toArray());
    }
}
