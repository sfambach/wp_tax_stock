<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1398: pieces of a pin header cut whole from single strips — every piece from one strip, never joined from two.
 *
 * A strip of C columns gives ⌊(C + loss) ÷ (c + loss)⌋ pieces of c columns; what is left (fewer than c + loss columns)
 * cannot serve the next piece and goes back as a rest. Rests and shorter strips are cut first: the plan walks the strips
 * from the shortest that fits to the longest (his «Rest» 1×4 serves a later 1×3 before a new 1×40 is opened).
 *
 * ```mermaid
 * flowchart LR
 *   S["Leisten + Reste"] -- "kürzeste passende zuerst" --> P["je Leiste ⌊C/c⌋ Stücke"]
 *   P --> T["Stücke"]
 *   P --> R["neue Reste (C mod c)"]
 * ```
 */
final class StripCut
{
    /**
     * @param list<array{strip: int|string, columns: int, pieces: int, rest: int}> $uses strips opened, in cutting order
     */
    private function __construct(public readonly array $uses, public readonly int $missing)
    {
    }

    /**
     * @param array<int|string, int> $strips     strip key → its columns (Polzahl ÷ Reihen); whole strips and rests alike
     * @param int                    $lossPerCut columns lost per cut (D-1178, Buchsenleisten); none behind the last piece
     *                                           where nothing is left
     */
    public static function plan(int $pieces, int $columns, array $strips, int $lossPerCut = 0): self
    {
        if ($pieces < 1 || $columns < 1) {
            throw StockError::cutNotPossible($columns, 0);
        }

        $loss = max(0, $lossPerCut);
        $fit  = array_filter($strips, static fn (int $c): bool => $c >= $columns);
        // Shortest first; among equals the order given (asort is stable).
        asort($fit);
        $uses = [];
        $left = $pieces;

        foreach ($fit as $strip => $c) {
            if ($left === 0) {
                break;
            }

            $taken = min($left, intdiv($c + $loss, $columns + $loss));
            $left -= $taken;
            $uses[] = ['strip' => $strip, 'columns' => $c, 'pieces' => $taken, 'rest' => max(0, $c - $taken * ($columns + $loss))];
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
}
