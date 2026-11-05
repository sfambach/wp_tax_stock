<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/** D-1158: every side fits, the box turned as it suits — largest side against largest. */
final class SidesFit implements FitRule
{
    public function fits(array $outer, array $inner): bool
    {
        rsort($outer);
        rsort($inner);

        foreach ($outer as $i => $side) {
            if ($side > ($inner[$i] ?? 0.0)) {
                return false;
            }
        }

        return true;
    }
}
