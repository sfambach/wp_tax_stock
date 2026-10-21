<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which material a purchase goes in as: the ones it already names, an existing one, or a new one.
 *
 * A new material refers to `$thing`; without one it refers to the purchase's «Modell», and only without
 * both it carries the purchase's own name (D-967).
 */
final class ReceiptChoice
{
    private function __construct(
        public readonly bool $keep,
        public readonly ?int $material,
        public readonly ?int $thing,
    ) {
    }

    public static function keep(): self
    {
        return new self(true, null, null);
    }

    public static function existing(int $material): self
    {
        return new self(false, $material, null);
    }

    public static function create(?int $thing): self
    {
        return new self(false, null, $thing);
    }

    public function isNew(): bool
    {
        return ! $this->keep && $this->material === null;
    }
}
