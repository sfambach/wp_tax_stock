<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which thing a purchase goes in as: its «Modell», another existing thing, or a new one in a group (D-995).
 *
 * `$location` is where its lot goes; null is the default place (D-972).
 */
final class ReceiptChoice
{
    private function __construct(
        public readonly bool $keep,
        public readonly ?int $thing,
        public readonly ?NewThing $new,
        public readonly ?int $location = null,
    ) {
    }

    public static function keep(): self
    {
        return new self(true, null, null);
    }

    public static function existing(int $thing): self
    {
        return new self(false, $thing, null);
    }

    public static function create(NewThing $new): self
    {
        return new self(false, null, $new);
    }

    public function at(?int $location): self
    {
        return new self($this->keep, $this->thing, $this->new, $location);
    }

    public function isNew(): bool
    {
        return $this->new !== null;
    }
}
