<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\ReturnPath;

final class ReturnPathTest extends TestCase
{
    private const ORDER = '/wp-admin/admin.php?page=taxmod-lager-modul&module=mf&auftrag=7';
    private const STOCK = '/wp-admin/admin.php?page=taxmod-stock&tab=material&thing=5&view=bestand';

    #[Test]
    public function aJumpWithAnOriginOffersTheWayBack(): void
    {
        $path = ReturnPath::empty()->arrive(self::STOCK, ['url' => self::ORDER, 'label' => 'Auftrag 7']);

        self::assertSame(['url' => self::ORDER, 'label' => 'Auftrag 7'], $path->top());
    }

    #[Test]
    public function stayingOnTheTargetKeepsTheWayBack(): void
    {
        $path = ReturnPath::empty()->arrive(self::STOCK, ['url' => self::ORDER, 'label' => 'Auftrag 7'])
            ->arrive(self::STOCK . '&message=ok', null)
            ->arrive(self::STOCK, ['url' => self::ORDER, 'label' => 'Auftrag 7']);

        self::assertCount(1, $path->toStored());
        self::assertSame(self::ORDER, $path->top()['url'] ?? null);
    }

    #[Test]
    public function arrivingBackAtTheOriginUsesItUp(): void
    {
        $path = ReturnPath::empty()->arrive(self::STOCK, ['url' => self::ORDER, 'label' => 'Auftrag 7'])->arrive(self::ORDER, null);

        self::assertNull($path->top());
    }

    #[Test]
    public function aChainLeadsBackStepByStep(): void
    {
        $path = ReturnPath::empty()
            ->arrive(self::STOCK, ['url' => self::ORDER, 'label' => 'Auftrag 7'])
            ->arrive('/c', ['url' => self::STOCK, 'label' => 'Bestand']);

        self::assertSame(self::STOCK, $path->top()['url'] ?? null);
        self::assertSame(self::ORDER, $path->arrive(self::STOCK, null)->top()['url'] ?? null);
        self::assertNull($path->arrive(self::ORDER, null)->top());
    }

    #[Test]
    public function oldOriginsFallOffAtTheDepth(): void
    {
        $path = ReturnPath::empty();

        for ($i = 0; $i <= ReturnPath::DEPTH; $i++) {
            $path = $path->arrive('/p' . ($i + 1), ['url' => '/p' . $i, 'label' => (string) $i]);
        }

        self::assertCount(ReturnPath::DEPTH, $path->toStored());
        self::assertSame('/p1', $path->toStored()[0]['url']);
    }

    #[Test]
    public function storedGarbageIsDropped(): void
    {
        self::assertNull(ReturnPath::fromStored('x')->top());
        self::assertSame([['url' => '/a', 'label' => 'A']], ReturnPath::fromStored([['url' => '/a', 'label' => 'A'], ['url' => 3], 'y'])->toStored());
    }
}
