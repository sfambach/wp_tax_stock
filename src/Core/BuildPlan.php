<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which lot a build takes from its box, or gives back to it (D-1146: «Beim Speichern bucht das Lager den Verbrauch der
 * Differenz … aus dem Gebinde, eine Rücknahme bucht zurück»).
 *
 * Taking: the oldest lot first (FIFO, as {@see Ledger::lotsOf()} orders them), at any place of the box or inside it; what
 * does not lie there is refused, never booked in part. Giving back: onto the box, to the lots this order took, the newest
 * first, at most what it took — so the value that comes back is the value that went out.
 *
 * ```mermaid
 * flowchart LR
 *   L["thing × quantity"] -->|"> 0"| T["oldest lots in the box and below"] --> R["lines −quantity at their place"]
 *   L -->|"< 0"| G["lots the order took, newest first"] --> B["lines +quantity at the box"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1146
 */
final class BuildPlan
{
    private const NOTHING = 0.000001;

    /**
     * @param array<int, float>              $lines  thing → pieces: more than 0 taken, less than 0 given back
     * @param list<int>                      $places the box and every place in it
     * @param array<int, array<int, float>>  $taken  thing → lot → pieces this order took so far
     * @return list<array{lot: int, place: int, quantity: float}> signed: negative is taken
     */
    public static function rows(Ledger $ledger, int $box, array $places, array $lines, array $taken): array
    {
        $rows = [];

        foreach ($lines as $thing => $quantity) {
            if ($quantity > self::NOTHING) {
                $rows = [...$rows, ...self::take($ledger, $places, $thing, $quantity)];
            } elseif ($quantity < -self::NOTHING) {
                $rows = [...$rows, ...self::giveBack($box, $thing, -$quantity, $taken[$thing] ?? [])];
            }
        }

        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        return $rows;
    }

    /** @param list<int> $places @return list<array{lot: int, place: int, quantity: float}> */
    private static function take(Ledger $ledger, array $places, int $thing, float $quantity): array
    {
        $rows = [];
        $left = $quantity;

        foreach ($ledger->lotsOf($thing) as $lot) {
            foreach ($places as $place) {
                $there = $ledger->restAt($lot, $place);

                if ($left <= self::NOTHING || $there <= self::NOTHING) {
                    continue;
                }

                $part   = min($there, $left);
                $rows[] = ['lot' => $lot, 'place' => $place, 'quantity' => -$part];
                $left  -= $part;
            }
        }

        return $left > self::NOTHING ? throw StockError::notEnoughForBuild($thing) : $rows;
    }

    /** @param array<int, float> $taken lot → pieces @return list<array{lot: int, place: int, quantity: float}> */
    private static function giveBack(int $box, int $thing, float $quantity, array $taken): array
    {
        krsort($taken);
        $rows = [];
        $left = $quantity;

        foreach ($taken as $lot => $pieces) {
            if ($left <= self::NOTHING || $pieces <= self::NOTHING) {
                continue;
            }

            $part   = min($pieces, $left);
            $rows[] = ['lot' => $lot, 'place' => $box, 'quantity' => $part];
            $left  -= $part;
        }

        return $left > self::NOTHING ? throw StockError::notTakenForBuild($thing) : $rows;
    }
}
