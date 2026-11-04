<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What happened in the stock between two days (D-1167): the Vorgänge of the span with their kind, what came in and what
 * went out, and which things moved most — computed from the movements like the stock itself (D-956), nothing stored.
 *
 * In and out are the lines of the Vorgänge: a positive quantity came in, a negative one went out. An Umbuchung or a
 * Box-Umlagerung moves between places and changes no stock, so it is counted as a Vorgang but neither in nor out. Lines of
 * a reservation move nothing (D-1083); a Storno and what it cancels are left out (D-1036).
 *
 * ```mermaid
 * flowchart LR
 *   V["Vorgänge: Art, Datum, Pos"] -->|date in span| E["events"]
 *   P["Pos: Menge ±, Posten"] --> E
 *   E --> K["count per kind"]
 *   E --> F["in / out per step"]
 *   E --> T["things moved most"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/kacheln/konzept.md §6
 */
final class PeriodActivity
{
    /** @var list<array{deal: int, date: string, kind: int, in: float, out: float, lines: int, things: array<int, float>}> newest first */
    private array $events = [];

    /**
     * @param string $from  first day counted, «YYYY-MM-DD»
     * @param string $until first day no longer counted, «YYYY-MM-DD»
     */
    public function __construct(Structure $s, Table $transactions, Table $lines, Table $lots, string $from, string $until)
    {
        $hidden = (new Storno($s))->pairedLines($lines) + Ledger::reservationLines($s, $transactions);
        $still  = array_flip(array_filter([
            $s->hasValue('kind_transfer') ? $s->value('kind_transfer') : 0,
            $s->hasValue('kind_relocation') ? $s->value('kind_relocation') : 0,
        ]));
        unset($still[0]);

        foreach ($transactions->ids() as $deal) {
            $date = substr((string) $transactions->first($deal, $s->field('transaction_date')), 0, 10);

            if ($date < $from || $date >= $until) {
                continue;
            }

            $kind  = (int) $transactions->first($deal, $s->field('transaction_kind'));
            $event = ['deal' => $deal, 'date' => $date, 'kind' => $kind, 'in' => 0.0, 'out' => 0.0, 'lines' => 0, 'things' => []];

            foreach ($transactions->all($deal, $s->field('transaction_lines')) as $line) {
                $line = (int) $line;

                if (! $lines->has($line)) {
                    continue;
                }

                $event['lines']++;

                if (isset($hidden[$line])) {
                    continue;
                }

                $quantity = (float) $lines->first($line, $s->field('line_quantity'));
                $thing    = (int) $lots->first((int) $lines->first($line, $s->field('line_lot')), $s->field('lot_thing'));

                if ($thing > 0) {
                    $event['things'][$thing] = ($event['things'][$thing] ?? 0.0) + abs($quantity);
                }

                if (! isset($still[$kind])) {
                    $event[$quantity >= 0 ? 'in' : 'out'] += abs($quantity);
                }
            }

            $this->events[] = $event;
        }

        usort($this->events, static fn (array $a, array $b): int => [$b['date'], $b['deal']] <=> [$a['date'], $a['deal']]);
    }

    /**
     * Every Vorgang of the span, newest first.
     *
     * @return list<array{deal: int, date: string, kind: int, in: float, out: float, lines: int, things: array<int, float>}>
     */
    public function events(string $from = '', string $until = '9999'): array
    {
        return array_values(array_filter($this->events, static fn (array $e): bool => $e['date'] >= $from && $e['date'] < $until));
    }

    /** @return array<int, int> kind → how many Vorgänge, between `$from` (inclusive) and `$until` (exclusive) */
    public function countsByKind(string $from = '', string $until = '9999'): array
    {
        $counts = array_count_values(array_column($this->events($from, $until), 'kind'));
        ksort($counts);

        return $counts;
    }

    /**
     * In and out per step of a chart.
     *
     * @param list<string> $steps «YYYY-MM-DD» per day, «YYYY-MM» per month; a date falls in the step it starts with
     * @return array{in: list<float>, out: list<float>}
     */
    public function flow(array $steps): array
    {
        $at  = array_flip($steps);
        $in  = array_fill(0, count($steps), 0.0);
        $out = $in;

        foreach ($this->events as $e) {
            $step = $at[substr($e['date'], 0, strlen((string) ($steps[0] ?? '')))] ?? null;

            if ($step !== null) {
                $in[$step]  += $e['in'];
                $out[$step] += $e['out'];
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    /** @return array<int, float> thing → quantity moved in or out, largest first, at most `$limit` */
    public function mostMoved(int $limit, string $from = '', string $until = '9999'): array
    {
        $moved = [];

        foreach ($this->events($from, $until) as $e) {
            foreach ($e['things'] as $thing => $quantity) {
                $moved[$thing] = ($moved[$thing] ?? 0.0) + $quantity;
            }
        }

        arsort($moved);

        return array_slice($moved, 0, $limit, true);
    }
}
