<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1375: whether a purchase's unit price lies more than a set share above the last earlier purchase of the same material.
 * It only warns and never blocks; downwards nothing is checked — he asked for «über dem letzten Kauf» only.
 * His wish 2026-11-15 after a pack price stood as the unit price (D-1374).
 *
 * @see docs/NewConcept/90-decision-log.md D-1375
 */
final class PriceCheck
{
    public const DEFAULT_PERCENT = 20;

    /**
     * Amounts in the reference currency, so a dollar and a euro purchase compare; a purchase without a unit price is
     * never the last one. Earlier means an earlier date, on the same date the smaller id.
     *
     * @param list<int>                                                   $checked   the purchases to check
     * @param array<int, array{material: int, date: string, unit: ?float}> $purchases every purchase of their materials
     * @return array<int, array{previous: int, previous_unit: float, unit: float, percent: float}> checked purchase → the warning
     */
    public static function warnings(array $checked, array $purchases, float $percent): array
    {
        $byMaterial = [];

        foreach ($purchases as $id => $purchase) {
            if ($purchase['material'] > 0 && $purchase['unit'] !== null && $purchase['unit'] > 0.0) {
                $byMaterial[$purchase['material']][$id] = $purchase;
            }
        }

        $out = [];

        foreach ($checked as $id) {
            $own = $purchases[$id] ?? null;

            if ($own === null || $own['unit'] === null || $own['unit'] <= 0.0) {
                continue;
            }

            $previous = null;

            foreach ($byMaterial[$own['material']] ?? [] as $other => $candidate) {
                $earlier = $candidate['date'] < $own['date'] || ($candidate['date'] === $own['date'] && $other < $id);
                $later   = $previous === null || [$candidate['date'], $other] > [$purchases[$previous]['date'], $previous];

                if ($other !== $id && $earlier && $later) {
                    $previous = $other;
                }
            }

            if ($previous === null) {
                continue;
            }

            $before = (float) $purchases[$previous]['unit'];
            $rise   = ($own['unit'] / $before - 1.0) * 100.0;

            if ($rise > $percent) {
                $out[$id] = ['previous' => $previous, 'previous_unit' => $before, 'unit' => $own['unit'], 'percent' => $rise];
            }
        }

        return $out;
    }
}
