<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Taking a booking back means a Storno, never deleting it (D-1036): a new Vorgang whose lines cancel the old lines, each
 * pointing at the line it cancels («Storno von»). Both stay; lists leave the pair out.
 *
 * ```mermaid
 * flowchart LR
 *   U["Umbuchung: −A, +B"] -.-> S["Storno: −B (von +B), +A (von −A)"]
 *   V["Verbrauch: −n an A"] -.-> T["Storno: +n an A (von −n)"]
 * ```
 */
final class Storno
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * The lines that cancel another and the lines cancelled, both left out of lists.
     *
     * @return array<int, true>
     */
    public function pairedLines(Table $lines): array
    {
        $s = $this->structure;

        if (! $s->hasField('line_reverses')) {
            return [];
        }

        $out = [];

        foreach ($lines->ids() as $line) {
            $cancelled = (int) $lines->first($line, $s->field('line_reverses'));

            if ($cancelled > 0) {
                $out[$line]      = true;
                $out[$cancelled] = true;
            }
        }

        return $out;
    }

    /**
     * The rows of the Storno of an Umbuchung: every pair «− old place, + new place» goes back. Only whole: a pair half
     * cancelled would change the lot's quantity.
     *
     * @return list<TransferRow>
     */
    public function transferRows(int $deal, Table $transactions, Table $lines, Ledger $ledger): array
    {
        $s = $this->structure;

        if (! $s->hasField('line_reverses')) {
            throw StockError::notConfigured('fields.line_reverses');
        }

        if (! $transactions->has($deal) || ! $s->hasValue('kind_transfer') || (int) $transactions->first($deal, $s->field('transaction_kind')) !== $s->value('kind_transfer')) {
            throw StockError::notCancellable($deal);
        }

        $paired = $this->pairedLines($lines);
        $mine   = array_map('intval', $transactions->all($deal, $s->field('transaction_lines')));

        if ($mine === [] || count($mine) % 2 !== 0) {
            throw StockError::notCancellable($deal);
        }

        $rows = [];

        foreach (array_chunk($mine, 2) as [$out, $in]) {
            if (isset($paired[$out]) || isset($paired[$in])) {
                throw StockError::alreadyCancelled($deal);
            }

            $lot      = (int) $lines->first($out, $s->field('line_lot'));
            $quantity = (float) $lines->first($in, $s->field('line_quantity'));

            if ($lot !== (int) $lines->first($in, $s->field('line_lot')) || $quantity <= 0.0 || abs((float) $lines->first($out, $s->field('line_quantity')) + $quantity) > 0.0000001) {
                throw StockError::notCancellable($deal);
            }

            $rows[] = new TransferRow($lot, $this->placeOf($in, $lot, $lines, $ledger), $this->placeOf($out, $lot, $lines, $ledger), (string) $lines->first($in, $s->field('line_quantity')), $in, $out);
        }

        return $rows;
    }

    /**
     * The rows of the Storno of a consumption (D-1036): every line «−Menge» comes back «+Menge» at its place. Only whole.
     *
     * @return list<array{lot: int, place: int|null, quantity: string, reverses: int}>
     */
    public function consumptionRows(int $deal, Table $transactions, Table $lines): array
    {
        $s = $this->structure;

        if (! $s->hasField('line_reverses')) {
            throw StockError::notConfigured('fields.line_reverses');
        }

        if (! $transactions->has($deal) || (int) $transactions->first($deal, $s->field('transaction_kind')) !== $s->value('kind_consumption')) {
            throw StockError::notCancellable($deal);
        }

        $paired = $this->pairedLines($lines);
        $where  = $s->hasField('line_location') ? $s->field('line_location') : null;
        $rows   = [];

        foreach (array_map('intval', $transactions->all($deal, $s->field('transaction_lines'))) as $line) {
            if (isset($paired[$line])) {
                throw StockError::alreadyCancelled($deal);
            }

            $lot      = (int) $lines->first($line, $s->field('line_lot'));
            $quantity = trim((string) $lines->first($line, $s->field('line_quantity')));
            $place    = $where === null ? 0 : (int) $lines->first($line, $where);

            if ($lot <= 0 || ! str_starts_with($quantity, '-') || (float) $quantity >= 0.0) {
                throw StockError::notCancellable($deal);
            }

            $rows[] = ['lot' => $lot, 'place' => $place > 0 ? $place : null, 'quantity' => substr($quantity, 1), 'reverses' => $line];
        }

        return $rows === [] ? throw StockError::notCancellable($deal) : $rows;
    }

    private function placeOf(int $line, int $lot, Table $lines, Ledger $ledger): int
    {
        $place = (int) $lines->first($line, $this->structure->field('line_location'));

        return $place > 0 ? $place : $ledger->homeOf($lot);
    }
}
