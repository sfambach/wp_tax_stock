<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What the buttons of the goods receipt cockpit write: only fields of the order itself, one change list per click (D-1001).
 *
 * The relation ids are those of the modeller's import targets, so the cockpit writes where the inbox writes.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC3
 */
final class InboundChanges
{
    /** @param int|null $arrivedField D-1411: the purchase's own «Angekommen am»; null where the model has none yet */
    public function __construct(private readonly int $receivedField, private readonly int $trackingField, private readonly ?int $arrivedField = null)
    {
    }

    /**
     * «Angekommen»: «Eingegangen am» becomes today; an order that already has a date keeps it.
     *
     * @param list<InboundOrder> $orders
     * @return list<array<string, mixed>>
     */
    public function arrived(array $orders, string $today): array
    {
        $changes = [];

        foreach ($orders as $order) {
            $changes = [...$changes, ...$this->purchasesArrived($order, array_keys($order->purchases), $today)];
        }

        return $changes;
    }

    /**
     * D-1411, «Angekommen …» for the ticked purchases: each open one not yet here gets today as its own «Angekommen am»;
     * the order's «Eingegangen am» is its own fact (the delivery message) and is only set while empty.
     *
     * @param list<int> $purchases
     * @return list<array<string, mixed>>
     */
    public function purchasesArrived(InboundOrder $order, array $purchases, string $today): array
    {
        $day     = substr($today, 0, 10);
        $changes = ($order->receivedOn ?? '') === '' ? [$this->set($order->id, $this->receivedField, $day)] : [];

        if ($this->arrivedField === null) {
            return $changes;
        }

        foreach ($purchases as $purchase) {
            $status = $order->purchases[$purchase] ?? null;

            if ($status === PurchaseStatus::Ordered || $status === PurchaseStatus::Shipped) {
                $changes[] = $this->set($purchase, $this->arrivedField, $day);
            }
        }

        return $changes;
    }

    /**
     * «Zurück» from «Angekommen»: «Eingegangen am» is emptied. A booked purchase keeps the order where it is — its lot
     * says it is here.
     *
     * @param array<int, string> $arrivedOn purchase → its own «Angekommen am» (D-1411)
     * @return list<array<string, mixed>>
     */
    public function notArrived(InboundOrder $order, int $bookedPurchases, array $arrivedOn = []): array
    {
        if ($bookedPurchases > 0) {
            throw StockError::alreadyBooked();
        }

        $changes = ($order->receivedOn ?? '') === '' ? [] : [$this->set($order->id, $this->receivedField, '')];

        // D-1411: the purchases' own dates step back with it.
        foreach ($this->arrivedField === null ? [] : array_keys($order->purchases) as $purchase) {
            if (trim((string) ($arrivedOn[$purchase] ?? '')) !== '') {
                $changes[] = $this->set($purchase, $this->arrivedField, '');
            }
        }

        return $changes;
    }

    /**
     * D-1411, «Nicht angekommen …» for ticked purchases: their own «Angekommen am» is emptied. Where the order's date alone
     * spoke for every purchase, the purchases that stay get it as their own, so only the ticked ones step back.
     *
     * @param list<int>          $purchases
     * @param array<int, string> $arrivedOn purchase → its own «Angekommen am»
     * @return list<array<string, mixed>>
     */
    public function purchasesNotArrived(InboundOrder $order, array $purchases, array $arrivedOn): array
    {
        if ($this->arrivedField === null) {
            throw StockError::notConfigured('fields.purchase_arrived_on');
        }

        $arrived = array_keys(array_filter($order->purchases, static fn (PurchaseStatus $p): bool => $p === PurchaseStatus::Arrived));
        $own     = array_filter($arrived, static fn (int $p): bool => trim((string) ($arrivedOn[$p] ?? '')) !== '') !== [];
        $changes = [];

        foreach ($arrived as $purchase) {
            if (in_array($purchase, $purchases, true)) {
                $changes[] = $this->set($purchase, $this->arrivedField, '');
            } elseif (! $own) {
                $changes[] = $this->set($purchase, $this->arrivedField, substr((string) $order->receivedOn, 0, 10));
            }
        }

        // Every arrived purchase ticked while the order's date spoke for them: the order steps back as a whole.
        if (! $own && array_diff($arrived, $purchases) === [] && ($order->receivedOn ?? '') !== '') {
            return [$this->set($order->id, $this->receivedField, '')];
        }

        $kept = array_filter($arrived, static fn (int $p): bool => ! in_array($p, $purchases, true) && trim((string) ($arrivedOn[$p] ?? '')) !== '');

        // No purchase keeps a day of its own: the order's date would speak for all of them again, so it goes too.
        if ($own && $kept === [] && ($order->receivedOn ?? '') !== '') {
            $changes[] = $this->set($order->id, $this->receivedField, '');
        }

        return $changes;
    }

    /**
     * A tracking number added by hand, after the ones the order has; spaces and dashes do not make it a new one.
     *
     * @return list<array<string, mixed>>
     */
    public function tracking(InboundOrder $order, string $number): array
    {
        $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));

        if ($number === '') {
            throw StockError::noTrackingNumber();
        }

        $known = array_map(static fn (string $n): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $n)), $order->tracking);

        return in_array($number, $known, true) ? [] : [$this->set($order->id, $this->trackingField, ['values' => [...$order->tracking, $number]])];
    }

    /**
     * D-1009: a tracking number taken off again — without one, a shipped order is «Bestellt» again.
     *
     * @return list<array<string, mixed>>
     */
    public function untrack(InboundOrder $order, string $number): array
    {
        $key  = static fn (string $n): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $n));
        $left = array_values(array_filter($order->tracking, static fn (string $n): bool => $key($n) !== $key($number)));

        return count($left) === count($order->tracking) ? [] : [$this->set($order->id, $this->trackingField, ['values' => $left])];
    }

    /**
     * @param string|array{values: list<string>} $value
     * @return array<string, mixed>
     */
    private function set(int $order, int $field, string|array $value): array
    {
        return ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $field => $value]];
    }
}
