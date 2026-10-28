<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Structure;

/** A stored structure that is the bundled one reads the ids a release added; another site's stays as pasted. */
final class StructureTest extends TestCase
{
    #[Test]
    public function the_bundled_ids_complete_only_the_same_structure(): void
    {
        $bundled = ['nodes' => ['order' => 6, 'lot' => 2], 'fields' => ['order_status' => 10, 'lot_tested_on' => 11], 'values' => ['test_ok' => 12]];
        $same    = ['nodes' => ['order' => 6, 'lot' => 2], 'fields' => ['order_status' => 10]];
        $other   = ['nodes' => ['order' => 7, 'lot' => 2], 'fields' => ['order_status' => 10]];

        self::assertSame(11, Structure::completed($same, $bundled)['fields']['lot_tested_on']);
        self::assertSame(12, Structure::completed($same, $bundled)['values']['test_ok']);
        self::assertSame($other, Structure::completed($other, $bundled), 'one id differs: another site');
        self::assertSame([], Structure::completed([], $bundled), 'nothing pasted yet: nothing guessed');
    }
}
