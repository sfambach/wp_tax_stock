<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The price of a source of supply, derived from the purchases bearing its number — never stored at the source (D-1314).
 *
 * @see docs/NewConcept/90-decision-log.md D-1314
 */
final class SourcePrices
{
    /**
     * Per source line (by its index): the purchases whose article number is the source's, newest first. A source without
     * a number takes none; numbers compare without case and outer spaces.
     *
     * @param list<SourceRow> $sources
     * @param list<array{purchase: int, number: string, date: string, price: ?float}> $purchases
     * @return array<int, list<array{purchase: int, number: string, date: string, price: ?float}>>
     */
    public static function of(array $sources, array $purchases): array
    {
        $key = static fn (string $number): string => mb_strtolower(trim($number));
        usort($purchases, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: $b['purchase'] <=> $a['purchase']);
        $out = [];

        foreach ($sources as $i => $source) {
            $number  = $key($source->number);
            $out[$i] = $number === '' ? [] : array_values(array_filter($purchases, static fn (array $p): bool => $key($p['number']) === $number));
        }

        return $out;
    }
}
