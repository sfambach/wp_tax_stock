<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PurchasePictures;

final class PurchasePicturesTest extends TestCase
{
    private const NONE = ['title' => null, 'pictures' => []];

    #[Test]
    public function a_material_without_a_picture_takes_its_purchases_first_once(): void
    {
        $s       = Fixture::structure();
        $field   = (string) $s->field('images');
        $changes = (new PurchasePictures($s))->firstPictures(
            [70 => '40', 71 => '40', 72 => '41', 73 => '@d1', 74 => '42'],
            [70 => ['title' => null, 'pictures' => ['media:5', 'media:6']], 71 => ['title' => null, 'pictures' => ['media:7']], 72 => ['title' => null, 'pictures' => ['media:8']], 73 => ['title' => null, 'pictures' => ['media:9']], 74 => self::NONE],
            [40 => self::NONE, 41 => ['title' => null, 'pictures' => ['media:1']], 42 => self::NONE]
        );

        // D-1264: 40 takes the first purchase's title picture, once; 41 has one; a new thing takes it; 74 has none to give.
        self::assertSame([
            ['op' => 'set_values', 'record' => '40', 'values' => [$field => ['values' => ['media:5']]]],
            ['op' => 'set_values', 'record' => '@d1', 'values' => [$field => ['values' => ['media:9']]]],
        ], $changes);
    }

    #[Test]
    public function taking_a_picture_appends_it_and_never_twice(): void
    {
        $s     = Fixture::structure();
        $field = (string) $s->field('images');
        $take  = new PurchasePictures($s);

        self::assertSame([['op' => 'set_values', 'record' => '40', 'values' => [$field => ['values' => ['media:1', 'media:5']]]]], $take->append(40, ['title' => null, 'pictures' => ['media:5']], ['title' => null, 'pictures' => ['media:1']]));
        self::assertSame([], $take->append(40, ['title' => null, 'pictures' => ['media:5']], ['title' => null, 'pictures' => ['media:1', 'media:5']]));
        self::assertSame([], $take->append(40, self::NONE, ['title' => null, 'pictures' => ['media:1']]));
    }
}
