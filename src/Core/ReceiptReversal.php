<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * «Eingebucht» → «Angekommen»: takes an order's goods receipt back out of stock (D-1009).
 *
 * Only while nothing else has touched its lots — each lot carries just its receipt line. Then the receipt Vorgänge of the
 * order, their lines and the lots go, and the order loses its status; «Eingegangen am» stays, so it stands under
 * «Angekommen» again. Materials created during the receipt stay: they are the tree's, not the receipt's.
 *
 * ```mermaid
 * flowchart LR
 *   O["order"] -->|lines| P["purchases"] -->|lot_purchase| L["lots"]
 *   L -->|line_lot| Z["lines"] -->|transaction_lines| V["receipt Vorgang"]
 *   L -. "other line on a lot" .-> X["refused"]
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

        $onMine = [];

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $s->field('line_lot'));

            if (! in_array($lot, $mine, true)) {
                continue;
            }

            if (! isset($ofReceipt[$line]) || isset($onMine[$lot])) {
                throw StockError::lotAlreadyUsed($lot);
            }

            $onMine[$lot] = $line;
        }

        $remove  = static fn (int $id): array => ['op' => 'remove_record', 'record' => (string) $id];
        $changes = [
            ...array_map($remove, $receipts),
            ...array_map($remove, array_keys($ofReceipt)),
            ...array_map($remove, $mine),
        ];
        $changes[] = ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => '']];

        return $changes;
    }
}
