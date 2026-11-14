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

    #[Test]
    public function the_number_follows_the_highest_one_typed_and_the_prefix_is_the_mark_above(): void
    {
        // Regal 1 › Fach 1 › Displays (typed R1-F1-B6), Kabel, Ablage (no kind)
        $tree  = new LocationTree(
            [1 => 'R1 Regal 1', 2 => 'R1-F1 Fach 1', 3 => 'R1-F1-B6 Displays', 4 => 'Kabel', 5 => 'Ablage', 6 => 'Zubehör'],
            [2 => 1, 3 => 2, 4 => 2, 5 => 2, 6 => 2],
            titles: [1 => 'Regal 1', 2 => 'Fach 1', 3 => 'Displays'],
            shorts: [1 => 'R1', 2 => 'R1-F1', 3 => 'R1-F1-B6']
        );
        $kinds  = [1 => 10, 2 => 20, 3 => 40, 4 => 40, 6 => 40];
        $shorts = [10 => 'R', 20 => 'F', 40 => 'B'];

        self::assertSame('R1-F1-B7', ShortMarks::proposed(4, $tree, $kinds, $shorts), 'D-1368: after a typed B6 comes B7, never a B6 again');
        self::assertSame('R1-F1-B8', ShortMarks::proposed(6, $tree, $kinds, $shorts), 'the open ones follow by their title');
        self::assertSame('', ShortMarks::proposed(5, $tree, $kinds, $shorts), 'a place without a kind gets no proposal');
    }
}
