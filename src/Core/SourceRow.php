<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * One line of «Bezugsquellen» on the tab «Lager»: a firm and its number for the thing (D-1013, D-1018).
 *
 * Whether the number is the maker's or a seller's is said by the node the firm lives in, so the line does not carry it.
 */
final class SourceRow
{
    /** @param int|null $part the part record the line already is; null for a new line */
    public function __construct(
        public readonly ?int $part,
        public readonly ?int $firm,
        public readonly string $number,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->firm === null && $this->number === '';
    }
}
