<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\ViewSettings;

/** «Gleich aufgeklappt» per tab: on by default only for the goods receipt. */
final class ViewSettingsTest extends TestCase
{
    #[Test]
    public function only_the_goods_receipt_opens_its_rows_by_default(): void
    {
        $views = ViewSettings::fromArray([]);

        self::assertTrue($views->expanded('wareneingang'));
        self::assertFalse($views->expanded('material'));
        self::assertFalse($views->expanded('material'), 'a tab without rows to open never opens');
    }

    #[Test]
    public function a_stored_choice_wins_over_the_default_and_survives_a_round_trip(): void
    {
        $views = ViewSettings::fromArray(['wareneingang' => ['expand' => false], 'material' => ['expand' => true], 'unknown' => ['expand' => true]]);

        self::assertFalse($views->expanded('wareneingang'));
        self::assertTrue($views->expanded('material'));
        self::assertSame(['wareneingang' => ['expand' => false], 'material' => ['expand' => true]], $views->toArray());
        self::assertSame($views->toArray(), ViewSettings::fromArray($views->toArray())->toArray());
    }
}
