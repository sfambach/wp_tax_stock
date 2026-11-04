<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1143: what a build order hands to the stock when it is done — a new lot of the finished thing at the target place, or,
 * for a negative count, the same pieces taken back from the lots this order brought in, newest first.
 *
 * ```mermaid
 * flowchart LR
 *   O["Auftrag: thing +2"] --> N["new lot at the place, +2"]
 *   S["Auftrag: thing −1"] --> L["lots of this order, newest first: −1"]
 * ```
 */
final class OutputPlan
{
    /**
     * @param array<int, float>                       $lines    thing → pieces; less than 0 cancels
     * @param array<int, array<int, array<int, float>>> $produced thing → lot → place → pieces of this order still there
     * @return array{new: list<array{thing: int, quantity: float}>, back: list<array{lot: int, place: int, quantity: float}>}
     */
    public static function rows(array $lines, array $produced): array
    {
        $new  = [];
        $back = [];

        foreach ($lines as $thing => $quantity) {
            if ($quantity > 0.0) {
                $new[] = ['thing' => $thing, 'quantity' => $quantity];

                continue;
            }

            $left = -$quantity;
            $lots = $produced[$thing] ?? [];
            krsort($lots);

            foreach ($lots as $lot => $at) {
                foreach ($at as $place => $there) {
                    if ($left <= 0.0) {
                        break 2;
                    }

                    $take   = min($there, $left);
                    $back[] = ['lot' => $lot, 'place' => $place, 'quantity' => $take];
                    $left  -= $take;
                }
            }

            if ($left > 1e-9) {
                throw StockError::notProducedForOrder($thing);
            }
        }

        return ['new' => $new, 'back' => $back];
    }
}
