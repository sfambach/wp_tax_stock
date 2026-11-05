<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1169: one way to say whether an outer size goes into an inner size — exchangeable, so the fit check can learn better.
 */
interface FitRule
{
    /**
     * @param list<float> $outer length, width, height of what goes in
     * @param list<float> $inner length, width, height of the room it goes into
     */
    public function fits(array $outer, array $inner): bool;
}
