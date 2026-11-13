<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1328 (D9): what cutting `pieces` strips of `poles` from longer strips of `from` poles takes and leaves, with the
 * loss per cut of D-1178.
 *
 * A strip counted as strips gives up whole strips; what is left of each becomes a shorter strip. A strip counted in
 * pins (a pack, D-1178) gives up pins: the pieces and one loss per cut.
 *
 * ```mermaid
 * flowchart LR
 *   L["1 × 40"] -- "3 × (8 + 1 Verlust)" --> S["3 × 1×8"]
 *   L --> R["Rest 1 × 13"]
 * ```
 */
final class CutPlan
{
    /**
     * @param int             $strips strips (or, in pins, pins) taken from the long lot
     * @param array<int, int> $rests  poles of a rest strip → how many such strips are left
     */
    private function __construct(public readonly int $strips, public readonly array $rests)
    {
    }

    public static function of(int $pieces, int $poles, int $from, int $lossPerCut, bool $countedInPins): self
    {
        if ($pieces < 1 || $poles < 1 || $from <= $poles) {
            throw StockError::cutNotPossible($poles, $from);
        }

        $loss = max(0, $lossPerCut);

        if ($countedInPins) {
            return new self($pieces * ($poles + $loss), []);
        }

        // The last piece of a strip needs no cut where nothing is left behind it.
        $perStrip = intdiv($from + $loss, $poles + $loss);
        $strips   = (int) ceil($pieces / $perStrip);
        $rests    = [];

        for ($strip = 1; $strip <= $strips; $strip++) {
            $taken = $strip < $strips ? $perStrip : $pieces - ($strips - 1) * $perStrip;
            $left  = $from - $taken * ($poles + $loss);

            if ($left >= 1) {
                $rests[$left] = ($rests[$left] ?? 0) + 1;
            }
        }

        return new self($strips, $rests);
    }
}
