<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/** D-1169, his word «aktuell anhand des volumens»: it fits while its volume is no larger than the room's. */
final class VolumeFit implements FitRule
{
    public function fits(array $outer, array $inner): bool
    {
        return array_product($outer) <= array_product($inner);
    }
}
