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
    public function __construct(private readonly int $receivedField, private readonly int $trackingField)
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
            if (($order->receivedOn ?? '') === '') {
                $changes[] = $this->set($order->id, $this->receivedField, substr($today, 0, 10));
            }
        }

        return $changes;
    }

    /**
     * «Zurück» from «Angekommen»: «Eingegangen am» is emptied. A booked purchase keeps the order where it is — its lot
     * says it is here.
     *
     * @return list<array<string, mixed>>
     */
    public function notArrived(InboundOrder $order, int $bookedPurchases): array
    {
        if ($bookedPurchases > 0) {
            throw StockError::alreadyBooked();
        }

        return ($order->receivedOn ?? '') === '' ? [] : [$this->set($order->id, $this->receivedField, '')];
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
     * @param string|array{values: list<string>} $value
     * @return array<string, mixed>
     */
    private function set(int $order, int $field, string|array $value): array
    {
        return ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $field => $value]];
    }
}
