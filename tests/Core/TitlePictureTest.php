<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\TitlePicture;

/** D-1205: the first picture of «Bilder» is the title picture; the old field wins while it is filled. */
final class TitlePictureTest extends TestCase
{
    public function testTheFirstPictureOfTheListIsTheTitlePicture(): void
    {
        self::assertSame(15685, TitlePicture::of(null, ['media:15685', 'media:15686']));
    }

    public function testTheOldFieldWinsWhileItIsFilled(): void
    {
        self::assertSame(20001, TitlePicture::of('media:20001', ['media:15685']));
    }

    public function testAnOutsideAddressIsNoTitlePicture(): void
    {
        self::assertSame(15686, TitlePicture::of('', ['https://example.org/a.jpg', 'media:15686']));
    }

    public function testNoPictureIsZero(): void
    {
        self::assertSame(0, TitlePicture::of(null, []));
    }
}
