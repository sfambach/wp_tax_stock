<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What one table of the stock page shows: search, filter and order, as a WordPress list table offers them — the same
 * way as the eBay module's shipping page (his wish 2026-10-25: «genauso wie in ebay shipping»).
 *
 * @see https://github.com/sfambach/wp_tax_api_ebay/blob/main/src/Core/Shipping/ParcelList.php
 */
final class StockList
{
    public const LOW = 'low';
    public const IN_STOCK = 'instock';
    public const ORDER_BY = ['name', 'stock'];

    public function __construct(
        public readonly string $search = '',
        public readonly string $filter = '',
        public readonly string $orderBy = 'name',
        public readonly bool $descending = false,
    ) {
    }

    /**
     * @param list<array{id: int, name: string, stock: float, low: bool, words: list<string>}> $rows
     * @return list<array{id: int, name: string, stock: float, low: bool, words: list<string>}>
     */
    public function apply(array $rows): array
    {
        $kept = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row)));
        $key  = $this->orderBy === 'stock'
            ? static fn (array $row): float => $row['stock']
            : static fn (array $row): string => mb_strtolower((string) preg_replace('/^#\d+( · )?/u', '', $row['name']));
        usort($kept, fn (array $a, array $b): int => ($this->descending ? -1 : 1) * ($key($a) <=> $key($b)));

        return $kept;
    }

    /** @param array{id: int, name: string, stock: float, low: bool, words: list<string>} $row */
    private function matches(array $row): bool
    {
        if (($this->filter === self::LOW && ! $row['low']) || ($this->filter === self::IN_STOCK && $row['stock'] <= 0.0)) {
            return false;
        }

        $search = trim($this->search);

        if ($search === '') {
            return true;
        }

        foreach ([(string) $row['id'], $row['name'], ...$row['words']] as $word) {
            if (mb_stripos($word, $search) !== false) {
                return true;
            }
        }

        return false;
    }
}
