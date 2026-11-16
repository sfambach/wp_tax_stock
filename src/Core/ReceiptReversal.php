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
        $plan = $this->reverseFree($order, $orders, $lots, $lines, $transactions);

        if ($plan['skipped'] !== []) {
            throw StockError::lotAlreadyUsed((int) array_values($plan['skipped'])[0]);
        }

        return $plan['changes'];
    }

    /**
     * Architecture 16.11. (Stefan «alle rückgängig»): every purchase whose lots are still untouched goes back; one whose
     * lot was used is skipped and named, the others go on. All skipped is refused like before.
     *
     * @return array{changes: list<array<string, mixed>>, skipped: array<int, int>, done: list<int>} skipped: purchase → its used lot
     */
    public function reverseFree(int $order, Table $orders, Table $lots, Table $lines, Table $transactions): array
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
                $ofReceipt[(int) $line] = $receipt;
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
        $used   = [];

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $s->field('line_lot'));

            if (! in_array($lot, $mine, true)) {
                continue;
            }

            if (isset($ofReceipt[$line])) {
                if (isset($onMine[$lot])) {
                    $used[$lot] = true;
                }

                $onMine[$lot] = $line;
                continue;
            }

            $kind = (int) $transactions->first($owner[$line] ?? 0, $s->field('transaction_kind'));

            // A Storno and the line it takes back cancel out, whatever their kind — a consumption taken back used nothing.
            if (! in_array($kind, $moves, true) && ! isset($cancelled[$line])) {
                $used[$lot] = true;
            }

            $moved[$lot][$line] = $owner[$line] ?? 0;
            $net[$lot]          = ($net[$lot] ?? 0.0) + (float) $lines->first($line, $s->field('line_quantity'));
        }

        foreach ($net as $lot => $sum) {
            if (abs($sum) > 1e-9) {
                $used[$lot] = true;
            }
        }

        $purchaseOf = [];

        foreach ($mine as $lot) {
            $purchaseOf[$lot] = (int) $lots->first($lot, $s->field('lot_purchase'));
        }

        $skipped = [];

        foreach (array_keys($used) as $lot) {
            $skipped[$purchaseOf[$lot]] ??= $lot;
        }

        $free = array_values(array_filter($mine, static fn (int $lot): bool => ! isset($skipped[$purchaseOf[$lot]])));

        if ($free === []) {
            throw StockError::lotAlreadyUsed((int) array_key_first($used));
        }

        $remove = static fn (int $id): array => ['op' => 'remove_record', 'record' => (string) $id];
        $done   = array_values(array_unique(array_map(static fn (int $lot): int => $purchaseOf[$lot], $free)));

        if ($skipped === []) {
            $changes = $this->withoutMoves(array_replace([], ...array_values($moved)), $transactions, $lines);
            $changes = [
                ...$changes,
                ...array_map($remove, $receipts),
                ...($s->composes('transaction_lines') ? [] : array_map($remove, array_keys($ofReceipt))),
                ...array_map($remove, $mine),
            ];
            $changes[] = ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => '']];

            return ['changes' => $changes, 'skipped' => [], 'done' => $done];
        }

        // Only the free lots: their receipt lines and moves leave their Vorgänge (an emptied one goes whole), then the lots.
        $drop = [];

        foreach ($free as $lot) {
            $drop += $moved[$lot] ?? [];

            if (isset($onMine[$lot])) {
                $drop[$onMine[$lot]] = $ofReceipt[$onMine[$lot]];
            }
        }

        $changes   = [...$this->withoutMoves($drop, $transactions, $lines), ...array_map($remove, $free)];
        $changes[] = ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => $s->hasValue('status_partial') ? (string) $s->value('status_partial') : '']];

        return ['changes' => $changes, 'skipped' => $skipped, 'done' => $done];
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
