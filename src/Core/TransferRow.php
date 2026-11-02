<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * One part of an Umbuchung (D-1039): a quantity of a lot from one place to another. A Storno row also names the two lines
 * it cancels (D-1036).
 */
final class TransferRow
{
    /**
     * @param int|null $reversesOut the line the «−» line of this row cancels, only in a Storno
     * @param int|null $reversesIn  the line the «+» line of this row cancels, only in a Storno
     */
    public function __construct(
        public readonly int $lot,
        public readonly int $from,
        public readonly int $to,
        public readonly string $quantity,
        public readonly ?int $reversesOut = null,
        public readonly ?int $reversesIn = null,
    ) {
    }
}
