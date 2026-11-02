<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/** One position of a reservation (D-1083): a material, how much, and the lot it is taken from if one is named. */
final class ReservationRow
{
    public function __construct(
        public readonly int $thing,
        public readonly string $quantity,
        public readonly ?int $lot = null,
    ) {
    }
}
