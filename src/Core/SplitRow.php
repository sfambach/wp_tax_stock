<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * One part of a lot purchase (Konvolut) once it is told apart: an existing material or a new one, how many, and
 * optionally the price per piece (concept R3: «Konvolute werden flache Positionen, gleiche Teile mit Anzahl»).
 */
final class SplitRow
{
    public function __construct(
        public readonly ?int $thing,
        public readonly ?NewThing $new,
        public readonly string $name,
        public readonly string $quantity,
        public readonly ?string $unitPrice,
    ) {
    }
}
