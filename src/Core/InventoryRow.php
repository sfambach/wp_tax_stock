<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * One counted line of a stock count: an existing thing or a new one, and what was counted (D-995).
 */
final class InventoryRow
{
    public function __construct(
        public readonly ?int $thing,
        public readonly ?NewThing $new,
        public readonly string $name,
        public readonly string $quantity,
        public readonly ?string $value,
        public readonly string $note,
    ) {
    }
}
