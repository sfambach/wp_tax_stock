<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\StockList;

/** The stock tables search, filter and sort like the eBay shipping page. */
final class StockListTest extends TestCase
{
    #[Test]
    public function it_searches_name_id_and_words(): void
    {
        self::assertSame([43124], $this->ids(new StockList('bc6')));
        self::assertSame([43200], $this->ids(new StockList('43200')));
        self::assertSame([43201], $this->ids(new StockList('Werkstatt')));
    }

    #[Test]
    public function it_filters_low_stock_and_stock_on_hand(): void
    {
        self::assertSame([43200], $this->ids(new StockList('', StockList::LOW)));
        self::assertSame([43124, 43200], $this->ids(new StockList('', StockList::IN_STOCK)));
    }

    #[Test]
    public function it_sorts_by_name_or_stock_either_way(): void
    {
        self::assertSame([43124, 43200, 43201], $this->ids(new StockList()));
        self::assertSame([43201, 43200, 43124], $this->ids(new StockList('', '', 'name', true)));
        self::assertSame([43201, 43200, 43124], $this->ids(new StockList('', '', 'stock')));
        self::assertSame([43124, 43200, 43201], $this->ids(new StockList('', '', 'stock', true)));
    }

    /** @return list<int> */
    private function ids(StockList $list): array
    {
        return array_column($list->apply([
            ['id' => 43200, 'name' => 'Lötzinn', 'stock' => 1.0, 'low' => true, 'words' => []],
            ['id' => 43124, 'name' => 'BC640', 'stock' => 12.0, 'low' => false, 'words' => ['Bauteile']],
            ['id' => 43201, 'name' => 'Zange', 'stock' => 0.0, 'low' => false, 'words' => ['Werkstatt']],
        ]), 'id');
    }
}
