<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1381: a bought set held against what it should contain — the set material's «Set-Inhalt» (wanted) beside the lots that
 * name the set lot as «aus Set» (held). Piece counts live only on those sub-lots; the set lot itself stays one set.
 *
 * @see docs/NewConcept/90-decision-log.md D-1381
 */
final class SetContent
{
    /**
     * One row per material, wanted first in their order, then what is held but not wanted. «fehlt» has no sub-lot,
     * «nicht im Set» no wanted quantity; «weniger» and «mehr» compare the held rest with the wanted quantity.
     *
     * @param array<int, float>                                      $wanted material → wanted quantity
     * @param list<array{lot: int, thing: int, quantity: float}>     $held   the sub-lots with their rest
     * @return list<array{thing: int, wanted: ?float, held: float, lots: list<int>, state: string}>
     */
    public static function rows(array $wanted, array $held): array
    {
        $byThing = [];

        foreach ($held as $one) {
            $byThing[$one['thing']] ??= ['quantity' => 0.0, 'lots' => []];
            $byThing[$one['thing']]['quantity'] += $one['quantity'];
            $byThing[$one['thing']]['lots'][]    = $one['lot'];
        }

        $rows = [];

        foreach ($wanted + array_fill_keys(array_keys($byThing), null) as $thing => $quantity) {
            $have   = $byThing[$thing]['quantity'] ?? 0.0;
            $lots   = $byThing[$thing]['lots'] ?? [];
            $rows[] = [
                'thing'  => (int) $thing,
                'wanted' => $quantity,
                'held'   => $have,
                'lots'   => $lots,
                'state'  => match (true) {
                    $quantity === null                   => 'nicht im Set',
                    $lots === []                         => 'fehlt',
                    abs($have - $quantity) < 0.000001    => 'ok',
                    $have < $quantity                    => 'weniger',
                    default                              => 'mehr',
                },
            ];
        }

        return $rows;
    }

    /**
     * Whether a lot may name this head as its set: not itself, and the head is no sub-lot of another set — a set holds lots,
     * never a chain of sets.
     *
     * @param array<int, int> $headOf lot → the set lot it names, only those that name one
     */
    public static function allowed(int $lot, int $head, array $headOf): bool
    {
        return $head > 0 && $lot !== $head && ! isset($headOf[$head]);
    }
}
