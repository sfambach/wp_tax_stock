<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The price of every part a build takes, for the calculation of the finished board (D-1425): the consumed lot's own
 * price, else the material's last purchase, marked as such, else none — never 0.
 *
 * ```mermaid
 * flowchart LR
 *   R["build plan: lot × pieces"] --> L{"lot has a price?"}
 *   L -->|yes| A["source lot"]
 *   L -->|no| P{"material bought before?"}
 *   P -->|yes| B["source last_purchase"]
 *   P -->|no| N["source none, price null"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1425
 */
final class BuildPrices
{
    public const LOT           = 'lot';
    public const LAST_PURCHASE = 'last_purchase';
    public const NONE          = 'none';

    /**
     * @param list<array{lot: int, place: int, quantity: float}> $rows      the build plan, negative is taken
     * @param array<int, int>                                    $thingOf   lot → material
     * @param array<int, float>                                  $lotPrice  lot → price per piece
     * @param array<int, float>                                  $lastPrice material → price per piece of its last purchase
     * @return array<int, list<array{lot: int, quantity: float, unit_price: float|null, source: string}>> material → the lots taken
     */
    public static function of(array $rows, array $thingOf, array $lotPrice, array $lastPrice): array
    {
        $taken = [];

        foreach ($rows as $row) {
            if ($row['quantity'] < 0.0 && isset($thingOf[$row['lot']])) {
                $taken[$row['lot']] = ($taken[$row['lot']] ?? 0.0) - $row['quantity'];
            }
        }

        $out = [];

        foreach ($taken as $lot => $quantity) {
            $thing = $thingOf[$lot];
            [$price, $source] = match (true) {
                isset($lotPrice[$lot])     => [$lotPrice[$lot], self::LOT],
                isset($lastPrice[$thing])  => [$lastPrice[$thing], self::LAST_PURCHASE],
                default                    => [null, self::NONE],
            };
            $out[$thing][] = ['lot' => $lot, 'quantity' => $quantity, 'unit_price' => $price, 'source' => $source];
        }

        return $out;
    }

    /**
     * Material → the price per piece of its newest purchase that has one; the later order date wins, then the later purchase.
     *
     * @param array<int, array{material: int, date: string, unit: float|null}> $purchases purchase → its facts
     * @return array<int, float>
     */
    public static function lastPurchase(array $purchases): array
    {
        $best = [];

        foreach ($purchases as $id => $p) {
            if ($p['unit'] === null || $p['material'] <= 0) {
                continue;
            }

            $held = $best[$p['material']] ?? null;

            if ($held === null || [$p['date'], $id] > [$held['date'], $held['id']]) {
                $best[$p['material']] = ['id' => $id, 'date' => $p['date'], 'unit' => $p['unit']];
            }
        }

        return array_map(static fn (array $b): float => $b['unit'], $best);
    }
}
