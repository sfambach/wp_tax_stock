<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\ShortMarks;

/** D-1337: a short mark proposed from the Kürzel of the kinds along the path. */
final class ShortMarksTest extends TestCase
{
    #[Test]
    public function the_kuerzel_and_the_number_among_siblings_of_the_same_kind_along_the_path(): void
    {
        // Keller (no kind) › Regal A, Regal B › Fach 1, Fach 2, Tisch › Box
        $tree  = new LocationTree(
            [1 => 'Keller', 2 => 'Regal A', 3 => 'Regal B', 4 => 'Fach 1', 5 => 'Fach 2', 6 => 'Tisch', 7 => 'Box'],
            [2 => 1, 3 => 1, 4 => 3, 5 => 3, 6 => 3, 7 => 5]
        );
        $kinds = [2 => 10, 3 => 10, 4 => 20, 5 => 20, 6 => 30, 7 => 40];

        self::assertSame('R2-F2-B1', ShortMarks::proposed(7, $tree, $kinds, [10 => 'R', 20 => 'F', 40 => 'B']));
        self::assertSame('', ShortMarks::proposed(6, $tree, $kinds, [10 => 'R', 20 => 'F']));
        self::assertSame('', ShortMarks::proposed(1, $tree, $kinds, [10 => 'R']));
    }
}
