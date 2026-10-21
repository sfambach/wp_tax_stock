<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * One counted line of a stock count: an existing material or a new one by name, and what was counted.
 */
final class InventoryRow
{
    public function __construct(
        public readonly ?int $material,
        public readonly string $name,
        public readonly ?int $thing,
        public readonly string $quantity,
        public readonly ?string $value,
        public readonly string $note,
    ) {
    }
}
