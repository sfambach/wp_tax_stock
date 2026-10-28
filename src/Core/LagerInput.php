<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The fields of the tab «Lager» of one thing as its form sends them; null means not given (D-997).
 */
final class LagerInput
{
    public function __construct(
        public readonly bool $single,
        public readonly ?int $use,
        public readonly ?int $location,
        public readonly ?int $minimum,
    ) {
    }
}
