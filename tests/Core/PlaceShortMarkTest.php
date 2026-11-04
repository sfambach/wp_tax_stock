<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1150: a place is named by its short mark and its Bezeichnung; its label keeps the Bezeichnung as title. */
final class PlaceShortMarkTest extends TestCase
{
    #[Test]
    public function the_short_mark_stands_before_the_name_but_not_in_the_title(): void
    {
        $raw = Fixture::structure()->toArray();
        $raw['fields'] += ['location_short' => 310];
        $s    = Structure::fromArray($raw);
        $tree = LocationTree::from($s, new Table([
            1 => [$s->field('label') => ['Schrank, Alex 1']],
            2 => [$s->field('label') => ['Werkzeug'], $s->field('location_short') => ['A1-S1'], $s->field('location_parent') => ['1']],
        ]), []);

        self::assertSame('Schrank, Alex 1 › A1-S1 Werkzeug', $tree->pathOf(2));
        self::assertSame('Werkzeug', $tree->titleOf(2));
        self::assertSame('A1-S1', $tree->shortOf(2));
        self::assertSame('Schrank, Alex 1', $tree->titleOf(1));
        self::assertSame('', $tree->shortOf(1));
    }
}
