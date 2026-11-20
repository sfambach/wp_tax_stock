<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1398: pieces of a pin header cut whole from single strips — every piece from one strip, never joined from two.
 *
 * One plan for all piece lengths of a material at once (Pin-Aufbereitung P1): the longest pieces first, each into the
 * strip with the least room that still takes it, so rests and shorter strips are cut before a new 1×40 is opened and
 * long rests stay. A piece that would leave a rest shorter than the minimum (P2: no 1-pin rests) goes to another strip
 * where there is one; a rest shorter than the minimum at the end is scrap, not a rest.
 *
 * ```mermaid
 * flowchart LR
 *   S["Leisten + Reste"] -- "längste Stücke zuerst, in die engste passende" --> P["Stücke je Leiste"]
 *   P --> R["Reste ≥ Mindestrest"]
 *   P --> X["kürzer: Ausschuss"]
 * ```
 */
final class StripCut
{
    /**
     * @param list<array{strip: int|string, columns: int, pieces: int, cuts: array<int, int>, rest: int, scrap: int}> $uses
     *        strips opened, in the order first opened; `cuts` = piece columns → pieces cut from this strip
     */
    private function __construct(public readonly array $uses, public readonly int $missing)
    {
    }

    /**
     * Pieces of one length — {@see mixed()} with one length.
     *
     * @param array<int|string, int> $strips strip key → its columns (Polzahl ÷ Reihen); whole strips and rests alike
     */
    public static function plan(int $pieces, int $columns, array $strips, int $lossPerCut = 0, int $minRest = 1): self
    {
        if ($pieces < 1 || $columns < 1) {
            throw StockError::cutNotPossible($columns, 0);
        }

        return self::mixed([$columns => $pieces], $strips, $lossPerCut, $minRest);
    }

    /**
     * @param array<int, int>        $demand     piece columns → pieces wanted
     * @param array<int|string, int> $strips     strip key → its columns; whole strips and rests alike
     * @param int                    $lossPerCut columns lost per cut (D-1178, Buchsenleisten); none behind the last piece
     * @param int                    $minRest    the shortest rest that goes back (columns); a shorter one is scrap
     */
    public static function mixed(array $demand, array $strips, int $lossPerCut = 0, int $minRest = 1): self
    {
        $loss = max(0, $lossPerCut);
        $min  = max(1, $minRest);
        $want = [];

        foreach ($demand as $columns => $pieces) {
            if ((int) $columns < 1 || $pieces < 0) {
                throw StockError::cutNotPossible((int) $columns, 0);
            }

            for ($n = 0; $n < $pieces; $n++) {
                $want[] = (int) $columns;
            }
        }

        if ($want === []) {
            throw StockError::cutNotPossible(0, 0);
        }

        rsort($want);

        $room  = [];
        $order = [];
        $cuts  = [];
        $index = array_flip(array_map('strval', array_keys($strips)));
        $left  = 0;

        foreach ($want as $columns) {
            $best = null;

            foreach ($strips as $strip => $length) {
                $has = $room[$strip] ?? $length;

                if ($has < $columns) {
                    continue;
                }

                $rest = max(0, $has - $columns - $loss);
                // Least room first; a rest shorter than the minimum only where nothing else takes the piece; at equal
                // room an opened strip before a new one; then the order given.
                $key = [($rest > 0 && $rest < $min) ? 1 : 0, $has, isset($room[$strip]) ? 0 : 1, $index[(string) $strip]];

                if ($best === null || $key < $best[1]) {
                    $best = [$strip, $key];
                }
            }

            if ($best === null) {
                $left++;
                continue;
            }

            $strip = $best[0];

            if (! isset($room[$strip])) {
                $order[] = $strip;
            }

            $room[$strip]           = max(0, ($room[$strip] ?? $strips[$strip]) - $columns - $loss);
            $cuts[$strip][$columns] = ($cuts[$strip][$columns] ?? 0) + 1;
        }

        $uses = [];

        foreach ($order as $strip) {
            $rest   = $room[$strip];
            $scrap  = $rest < $min ? $rest : 0;
            $uses[] = ['strip' => $strip, 'columns' => $strips[$strip], 'pieces' => array_sum($cuts[$strip]), 'cuts' => $cuts[$strip], 'rest' => $rest - $scrap, 'scrap' => $scrap];
        }

        return new self($uses, $left);
    }

    /** @return array<int|string, int> strip → rest columns, only where a rest is left */
    public function rests(): array
    {
        $out = [];

        foreach ($this->uses as $use) {
            if ($use['rest'] > 0) {
                $out[$use['strip']] = $use['rest'];
            }
        }

        return $out;
    }

    /** @return array<int|string, int> strip → scrap columns (a rest shorter than the minimum), only where there is some */
    public function scrap(): array
    {
        $out = [];

        foreach ($this->uses as $use) {
            if ($use['scrap'] > 0) {
                $out[$use['strip']] = $use['scrap'];
            }
        }

        return $out;
    }

    /** Strips opened that were whole before, given the length of a whole strip. */
    public function opened(int $wholeColumns): int
    {
        return count(array_filter($this->uses, static fn (array $use): bool => $use['columns'] >= $wholeColumns));
    }
}
