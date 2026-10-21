<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The fields of a material as the form «Material» sends them; empty means not given.
 */
final class MaterialInput
{
    public function __construct(
        public readonly ?int $thing,
        public readonly string $name,
        public readonly bool $single,
        public readonly ?int $use,
        public readonly ?int $location,
        public readonly ?int $minimum,
    ) {
    }
}
