<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Stock on hand, computed from the movements: nothing about a quantity is stored (D-956).
 *
 * ```mermaid
 * flowchart LR
 *   P["Vorgänge Pos: Menge ±"] -->|sum per lot| L["Bestandsposten: rest"]
 *   L -->|sum per thing| M["Ding: Bestand"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §3.5a
 */
final class Ledger
{
    /** @var array<int, float> lot id → rest */
    private array $rest = [];

    /** @var array<int, int> lot id → thing id (D-995: the thing is the material) */
    private array $thingOf = [];

    /** @var array<int, string> lot id → receipt date */
    private array $dateOf = [];

    public function __construct(Structure $structure, Table $lots, Table $lines)
    {
        foreach ($lots->ids() as $lot) {
            $this->thingOf[$lot]    = (int) $lots->first($lot, $structure->field('lot_thing'));
            $this->dateOf[$lot]     = (string) $lots->first($lot, $structure->field('lot_date'));
            $this->rest[$lot]       = 0.0;
        }

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $structure->field('line_lot'));

            // A line on a lot that no longer exists counts nowhere rather than inventing a lot.
            if (isset($this->rest[$lot])) {
                $this->rest[$lot] += (float) $lines->first($line, $structure->field('line_quantity'));
            }
        }
    }

    public function restOf(int $lot): float
    {
        return $this->rest[$lot] ?? 0.0;
    }

    public function stockOf(int $thing): float
    {
        $sum = 0.0;

        foreach ($this->lotsOf($thing) as $lot) {
            $sum += $this->rest[$lot];
        }

        return $sum;
    }

    /**
     * The lots of a thing, oldest first (receipt date, then id).
     *
     * @return list<int>
     */
    public function lotsOf(int $thing): array
    {
        $lots = array_keys(array_filter($this->thingOf, static fn (int $t): bool => $t === $thing));

        usort($lots, fn (int $a, int $b): int => [$this->dateOf[$a], $a] <=> [$this->dateOf[$b], $b]);

        return $lots;
    }

    /**
     * The lot a consumption takes from unless the user picks another: the oldest with something left (FIFO).
     * Without any such lot the oldest lot overall — a consumption may push the stock below zero (concept §11).
     */
    public function proposedLot(int $thing): int
    {
        $lots = $this->lotsOf($thing);

        foreach ($lots as $lot) {
            if ($this->rest[$lot] > 0) {
                return $lot;
            }
        }

        return $lots[0] ?? throw StockError::noLot($thing);
    }

    public function thingOf(int $lot): ?int
    {
        return $this->thingOf[$lot] ?? null;
    }

    /** @return list<int> */
    public function things(): array
    {
        return array_values(array_unique($this->thingOf));
    }

    /**
     * The purchases received already: those some lot names as its origin.
     *
     * @return array<int, true>
     */
    public static function receivedPurchases(Structure $structure, Table $lots): array
    {
        $received = [];

        foreach ($lots->ids() as $lot) {
            $purchase = (int) $lots->first($lot, $structure->field('lot_purchase'));

            if ($purchase > 0) {
                $received[$purchase] = true;
            }
        }

        return $received;
    }
}
