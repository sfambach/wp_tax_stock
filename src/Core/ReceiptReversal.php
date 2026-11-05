<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * «Eingebucht» → «Angekommen»: takes an order's goods receipt back out of stock (D-1009).
 *
 * Only while nothing else has used its lots — each lot carries its receipt line and at most Umbuchungen that leave its
 * quantity as it was, or lines taken back by a Storno; those lines go too. Then the receipt Vorgänge of the
 * order, their lines and the lots go, and the order loses its status; «Eingegangen am» stays, so it stands under
 * «Angekommen» again. Materials created during the receipt stay: they are the tree's, not the receipt's.
 *
 * D-1097, D-1099: when «Positionen» of the Vorgänge is a composition, the lines are parts of their Vorgang and go with it —
 * they are not removed one by one after it (the second removal would find nothing). Should another record still point at one
 * of them, the modeller refuses the whole list and names who ({@see StockError::fromModel()}).
 *
 * ```mermaid
 * flowchart LR
 *   O["order"] -->|lines| P["purchases"] -->|lot_purchase| L["lots"]
 *   L -->|line_lot| Z["lines"] -->|transaction_lines| V["receipt Vorgang"]
 *   L -. "Umbuchung or Storno pair, net 0" .-> M["their lines go too"]
 *   L -. "any other line" .-> X["refused"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC11
 */
final class ReceiptReversal
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return list<array<string, mixed>> */
    public function reverse(int $order, Table $orders, Table $lots, Table $lines, Table $transactions): array
    {
        $s         = $this->structure;
        $purchases = array_map('intval', $orders->all($order, $s->field('order_lines')));
        $mine      = array_values(array_filter($lots->ids(), static fn (int $lot): bool => in_array((int) $lots->first($lot, $s->field('lot_purchase')), $purchases, true)));

        if ($mine === []) {
            throw StockError::nothingToUnbook();
        }

        $receipts = array_values(array_filter($transactions->ids(), static fn (int $t): bool => (int) $transactions->first($t, $s->field('transaction_order')) === $order
            && (int) $transactions->first($t, $s->field('transaction_kind')) === $s->value('kind_receipt')));
        $ofReceipt = [];

        foreach ($receipts as $receipt) {
            foreach ($transactions->all($receipt, $s->field('transaction_lines')) as $line) {
                $ofReceipt[(int) $line] = true;
            }
        }

        // His report 2026-11-05: «zurückbuchen … nicht möglich» on a card that was only moved — an Umbuchung (or one taken
        // back) leaves the lot's quantity as it was, so it does not keep the receipt; its lines go with it.
        $owner = [];

        foreach ($transactions->ids() as $transaction) {
            foreach ($transactions->all($transaction, $s->field('transaction_lines')) as $line) {
                $owner[(int) $line] = $transaction;
            }
        }

        $moves  = array_values(array_filter([
            $s->hasValue('kind_transfer') ? $s->value('kind_transfer') : null,
            $s->hasValue('kind_relocation') ? $s->value('kind_relocation') : null,
        ]));
        $cancelled = [];

        if ($s->hasField('line_reverses')) {
            foreach ($lines->ids() as $line) {
                $back = (int) $lines->first($line, $s->field('line_reverses'));

                if ($back > 0 && ! isset($ofReceipt[$back])) {
                    $cancelled[$line] = true;
                    $cancelled[$back] = true;
                }
            }
        }

        $onMine = [];
        $moved  = [];
        $net    = [];

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $s->field('line_lot'));

            if (! in_array($lot, $mine, true)) {
                continue;
            }

            if (isset($ofReceipt[$line])) {
                if (isset($onMine[$lot])) {
                    throw StockError::lotAlreadyUsed($lot);
                }

                $onMine[$lot] = $line;
                continue;
            }

            $kind = (int) $transactions->first($owner[$line] ?? 0, $s->field('transaction_kind'));

            // A Storno and the line it takes back cancel out, whatever their kind — a consumption taken back used nothing.
            if (! in_array($kind, $moves, true) && ! isset($cancelled[$line])) {
                throw StockError::lotAlreadyUsed($lot);
            }

            $moved[$line] = $owner[$line] ?? 0;
            $net[$lot]    = ($net[$lot] ?? 0.0) + (float) $lines->first($line, $s->field('line_quantity'));
        }

        foreach ($net as $lot => $sum) {
            if (abs($sum) > 1e-9) {
                throw StockError::lotAlreadyUsed($lot);
            }
        }

        $remove  = static fn (int $id): array => ['op' => 'remove_record', 'record' => (string) $id];
        $changes = $this->withoutMoves($moved, $transactions, $lines);
        $changes = [
            ...$changes,
            ...array_map($remove, $receipts),
            ...($s->composes('transaction_lines') ? [] : array_map($remove, array_keys($ofReceipt))),
            ...array_map($remove, $mine),
        ];
        $changes[] = ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => '']];

        return $changes;
    }

    /**
     * The Umbuchung lines of the lots taken back: a Vorgang left without lines goes whole, otherwise only those lines go
     * (an aggregation first drops them from the Vorgang's list, or the modeller would refuse removing a line still named).
     *
     * @param array<int, int> $moved line → its Vorgang
     * @return list<array<string, mixed>>
     */
    private function withoutMoves(array $moved, Table $transactions, Table $allLines): array
    {
        $s       = $this->structure;
        $field   = $s->field('transaction_lines');
        $composed = $s->composes('transaction_lines');
        $changes = [];
        // A line no Vorgang names stands alone and simply goes.
        $lines   = array_keys(array_filter($moved, static fn (int $transaction): bool => $transaction === 0));

        // A Storno points at the line it takes back, so it goes first; the modeller refuses removing a line still named.
        $order = array_values(array_unique(array_filter(array_values($moved))));
        $backs = static fn (int $transaction): int => count(array_filter($transactions->all($transaction, $field), static fn (string $line): bool => $s->hasField('line_reverses') && (int) $allLines->first((int) $line, $s->field('line_reverses')) > 0));
        usort($order, static fn (int $a, int $b): int => $backs($b) <=> $backs($a));

        foreach ($order as $transaction) {
            $all  = array_map('intval', $transactions->all($transaction, $field));
            $kept = array_values(array_filter($all, static fn (int $line): bool => ! isset($moved[$line])));

            if ($kept === []) {
                $changes[] = ['op' => 'remove_record', 'record' => (string) $transaction];
                $lines     = [...$lines, ...($composed ? [] : $all)];
                continue;
            }

            if (! $composed) {
                $changes[] = ['op' => 'set_values', 'record' => (string) $transaction, 'values' => [(string) $field => ['values' => array_map('strval', $kept)]]];
            }

            $lines = [...$lines, ...array_values(array_diff($all, $kept))];
        }

        return [...$changes, ...array_map(static fn (int $line): array => ['op' => 'remove_record', 'record' => (string) $line], $lines)];
    }
}
