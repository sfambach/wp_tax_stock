<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A purchase that is closed without going into stock — private, built in at once, given away, sent back (D-1058).
 *
 * The purchase keeps its reason in «Nicht eingelagert»; no lot is made. For the order it counts as done, so an order
 * whose other purchases are booked stands as received. Taking it back empties the reason (D-1035: undo, not delete).
 *
 * ```mermaid
 * flowchart LR
 *   K["Kauf"] -->|"Nicht einlagern + Grund"| N["closed, no lot"]
 *   N -->|"Zurücknehmen"| K
 * ```
 */
final class NotStocked
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return array<int, true> purchases closed without stock */
    public static function purchases(Structure $s, Table $purchases): array
    {
        if (! $s->hasField('purchase_not_stocked')) {
            return [];
        }

        $out = [];

        foreach ($purchases->ids() as $id) {
            if (trim((string) $purchases->first($id, $s->field('purchase_not_stocked'))) !== '') {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * @param array<int, true> $received purchases some lot already names
     * @return list<array<string, mixed>>
     */
    public function close(int $order, int $purchase, string $reason, Table $orders, Table $purchases, array $received): array
    {
        $s      = $this->structure;
        $closed = self::purchases($s, $purchases);
        $this->inOrder($order, $purchase, $orders);

        if (trim($reason) === '') {
            throw StockError::notStockedWithoutReason($purchase);
        }

        if (isset($received[$purchase]) || isset($closed[$purchase])) {
            throw StockError::alreadyReceived($purchase);
        }

        $closed[$purchase] = true;

        return [
            ['op' => 'set_values', 'record' => (string) $purchase, 'values' => [(string) $s->field('purchase_not_stocked') => trim($reason)]],
            $this->status($order, $orders, $received + $closed),
        ];
    }

    /**
     * @param array<int, true> $received purchases some lot already names
     * @return list<array<string, mixed>>
     */
    public function reopen(int $order, int $purchase, Table $orders, Table $purchases, array $received): array
    {
        $s      = $this->structure;
        $closed = self::purchases($s, $purchases);
        $this->inOrder($order, $purchase, $orders);

        if (! isset($closed[$purchase])) {
            throw StockError::notClosedWithoutStock($purchase);
        }

        unset($closed[$purchase]);

        return [
            ['op' => 'set_values', 'record' => (string) $purchase, 'values' => [(string) $s->field('purchase_not_stocked') => '']],
            $this->status($order, $orders, $received + $closed),
        ];
    }

    private function inOrder(int $order, int $purchase, Table $orders): void
    {
        if (! in_array((string) $purchase, $orders->all($order, $this->structure->field('order_lines')), true)) {
            throw StockError::notInOrder($purchase);
        }
    }

    /**
     * Received when every purchase is booked or closed, partly when some are, none when nothing is.
     *
     * @param array<int, true> $done
     * @return array<string, mixed>
     */
    private function status(int $order, Table $orders, array $done): array
    {
        $s      = $this->structure;
        $lines  = array_map('intval', $orders->all($order, $s->field('order_lines')));
        $left   = array_diff($lines, array_keys($done));
        $status = match (true) {
            $lines !== [] && $left === []    => (string) $s->value('status_received'),
            count($left) < count($lines)     => (string) $s->value('status_partial'),
            default                          => '',
        };

        return ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => $status]];
    }
}
