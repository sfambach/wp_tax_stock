<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\PlatformLink;

final class PlatformLinkTest extends TestCase
{
    #[Test]
    public function the_number_goes_where_the_template_says(): void
    {
        self::assertSame('https://www.aliexpress.com/item/1005006.html', PlatformLink::filled('https://www.aliexpress.com/item/{nr}.html', ' 1005006 '));
    }

    #[Test]
    public function without_template_placeholder_or_number_there_is_no_link(): void
    {
        self::assertNull(PlatformLink::filled('', '1'));
        self::assertNull(PlatformLink::filled('https://shop.example/', '1'));
        self::assertNull(PlatformLink::filled('https://shop.example/{nr}', ''));
    }

    #[Test]
    public function the_number_cannot_break_out_of_the_url(): void
    {
        self::assertSame('https://shop.example/a%2Fb%3Fx%3D1', PlatformLink::filled('https://shop.example/{nr}', 'a/b?x=1'));
    }
}
