<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The frozen inputs of one completion's price (D-1425) — wage, set-up time and its divisor, time per board, the parts with
 * their price and the scrap — and the breakdown derived from them. Only the inputs are stored; sums are read, never kept,
 * and the total per piece stays the lot's value alone.
 *
 * ```mermaid
 * flowchart LR
 *   I["inputs: wage, set-up s, divisor, work s, parts, scrap"] --> P["parts: Σ quantity × price"]
 *   I --> S["set-up: s ÷ 3600 × wage ÷ divisor"]
 *   I --> W["work: s ÷ 3600 × wage"]
 *   P --> T["total per board"]
 *   S --> T
 *   W --> T
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1425
 */
final class Calculation
{
    /**
     * @param list<array{thing: int, lot: int, quantity: float, unit_price: float|null, source: string}> $parts
     * @param list<array{thing: int, quantity: float}>                                                $scrap
     */
    public function __construct(
        public readonly float $wage,
        public readonly float $setupSeconds,
        public readonly float $divisor,
        public readonly ?float $workSeconds,
        public readonly array $parts,
        public readonly array $scrap,
    ) {
    }

    /**
     * The `cost` a caller hands to book_output: setup {hours, wage, divisor (or boards_of_order)}, work {minutes, wage},
     * parts [{thing, lot?, quantity, unit_price|null, source}], scrap [{thing, quantity}]. Refuses what is not a number.
     *
     * @param array<string, mixed> $cost
     */
    public static function fromCost(array $cost): self
    {
        $setup = is_array($cost['setup'] ?? null) ? $cost['setup'] : [];
        $work  = is_array($cost['work'] ?? null) ? $cost['work'] : [];
        $wage  = self::number($setup['wage'] ?? $work['wage'] ?? null);
        $parts = [];
        $scrap = [];

        foreach (is_array($cost['parts'] ?? null) ? $cost['parts'] : [] as $part) {
            $part  = is_array($part) ? $part : [];
            $price = $part['unit_price'] ?? null;

            $parts[] = [
                'thing'      => self::id($part['thing'] ?? null),
                'lot'        => (int) ($part['lot'] ?? 0),
                'quantity'   => self::number($part['quantity'] ?? null),
                'unit_price' => $price === null ? null : self::number($price),
                'source'     => $price === null ? BuildPrices::NONE : (in_array($part['source'] ?? '', [BuildPrices::LOT, BuildPrices::LAST_PURCHASE], true) ? (string) $part['source'] : BuildPrices::NONE),
            ];
        }

        foreach (is_array($cost['scrap'] ?? null) ? $cost['scrap'] : [] as $piece) {
            $piece   = is_array($piece) ? $piece : [];
            $scrap[] = ['thing' => self::id($piece['thing'] ?? null), 'quantity' => self::number($piece['quantity'] ?? null)];
        }

        $divisor = self::number($setup['divisor'] ?? $setup['boards_of_order'] ?? null);
        $minutes = $work['minutes'] ?? null;

        if ($divisor <= 0.0) {
            throw StockError::notAQuantity((string) $divisor);
        }

        return new self($wage, self::number($setup['hours'] ?? null) * 3600.0, $divisor, $minutes === null || $minutes === '' ? null : self::number($minutes) * 60.0, $parts, $scrap);
    }

    /**
     * The breakdown as the sale and the production read it, per board in euro.
     *
     * @return array{parts: list<array{thing: int, lot: int, quantity: float, unit_price: float|null, source: string}>, parts_total: float, complete: bool, setup: array{hours: float, wage: float, divisor: float, amount: float}, work: array{minutes: float|null, wage: float, amount: float}, scrap: list<array{thing: int, quantity: float}>, total: float}
     */
    public function breakdown(): array
    {
        $partsTotal = 0.0;
        $complete   = true;

        foreach ($this->parts as $part) {
            if ($part['unit_price'] === null) {
                $complete = false;
            } else {
                $partsTotal += $part['quantity'] * $part['unit_price'];
            }
        }

        $setup = $this->setupSeconds / 3600.0 * $this->wage / $this->divisor;
        $work  = ($this->workSeconds ?? 0.0) / 3600.0 * $this->wage;

        return [
            'parts'       => $this->parts,
            'parts_total' => round($partsTotal, 4),
            'complete'    => $complete,
            'setup'       => ['hours' => $this->setupSeconds / 3600.0, 'wage' => $this->wage, 'divisor' => $this->divisor, 'amount' => round($setup, 4)],
            'work'        => ['minutes' => $this->workSeconds === null ? null : $this->workSeconds / 60.0, 'wage' => $this->wage, 'amount' => round($work, 4)],
            'scrap'       => $this->scrap,
            'total'       => round($partsTotal + $setup + $work, 4),
        ];
    }

    private static function number(mixed $given): float
    {
        if (! is_numeric($given) || (float) $given < 0.0) {
            throw StockError::notAQuantity(is_scalar($given) ? (string) $given : '');
        }

        return (float) $given;
    }

    private static function id(mixed $given): int
    {
        if (! is_numeric($given) || (int) $given <= 0) {
            throw StockError::notAThing(is_numeric($given) ? (int) $given : 0);
        }

        return (int) $given;
    }
}
