<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The rows of an Umbuchung asked for from outside (taxmod-stock/book-transfer), checked before anything is booked: every lot and
 * place known, from and to differ, and no row takes more than is left at its place after the rows before it. The error names the row.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1233
 */
final class TransferRequest
{
    /**
     * @param list<array{lot?: int, from?: int, to?: int, quantity?: string}> $rows
     * @param \Closure(int): bool        $isLot
     * @param \Closure(int): bool        $isPlace
     * @param \Closure(int, int): float  $restAt what of the lot lies at the place now
     * @return list<TransferRow>
     */
    public static function rows(array $rows, \Closure $isLot, \Closure $isPlace, \Closure $restAt): array
    {
        $moves = [];
        $taken = [];

        foreach (array_values($rows) as $i => $row) {
            $step = sprintf('row %d', $i + 1);
            $lot  = (int) ($row['lot'] ?? 0);
            $from = (int) ($row['from'] ?? 0);
            $to   = (int) ($row['to'] ?? 0);

            if (! $isLot($lot)) {
                throw new \InvalidArgumentException($step . ': lot ' . $lot . ' does not exist.');
            }

            foreach ([$from, $to] as $place) {
                if (! $isPlace($place)) {
                    throw new \InvalidArgumentException($step . ': place ' . $place . ' is no storage place.');
                }
            }

            if ($from === $to) {
                throw new \InvalidArgumentException($step . ': from and to are the same place.');
            }

            $key  = $lot . ':' . $from;
            $rest = $restAt($lot, $from) - ($taken[$key] ?? 0.0);
            $text = trim((string) ($row['quantity'] ?? ''));
            $want = $text === '' ? $rest : (float) str_replace(',', '.', $text);

            if ($want <= 0.0 || $want > $rest + 1e-9) {
                throw new \InvalidArgumentException($step . ': lot ' . $lot . ' has ' . self::plain(max($rest, 0.0)) . ' at place ' . $from . ', not ' . ($text === '' ? 'anything' : $text) . '.');
            }

            $taken[$key] = ($taken[$key] ?? 0.0) + $want;
            $moves[]     = new TransferRow($lot, $from, $to, Quantity::positive(self::plain($want)));
        }

        return $moves;
    }

    private static function plain(float $quantity): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', $quantity), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
