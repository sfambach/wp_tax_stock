<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Where one purchase of an order stands in the goods receipt (D-1411) — derived, never stored.
 *
 * ```mermaid
 * flowchart LR
 *   N["«Nicht eingelagert» set"] --> NS["NotStocked"]
 *   L["a lot names it"] --> B["Booked"]
 *   A["own «Angekommen am» · or the order arrived and no purchase has its own date"] --> AR["Arrived"]
 *   T["order shipped or arrived otherwise"] --> SH["Shipped"]
 *   E["else"] --> O["Ordered"]
 * ```
 *
 * The first rule that holds wins. The order's «Eingegangen am» stays the order's own fact (the delivery message);
 * it only speaks for its purchases while none of them has a date of its own (the case of every order before D-1411).
 */
enum PurchaseStatus: string
{
    case Ordered   = 'ordered';
    case Shipped   = 'shipped';
    case Arrived   = 'arrived';
    case Booked    = 'booked';
    case NotStocked = 'not_stocked';

    /**
     * @param list<int>             $purchases the order's purchases
     * @param array<int, string>    $arrivedOn purchase → its own «Angekommen am», '' or missing without one
     * @param array<int, true>      $booked purchases some lot names
     * @param array<int, true>      $closed purchases closed without stock
     * @return array<int, self> purchase → its status, in the order's order
     */
    public static function of(Structure $s, InboundOrder $order, array $purchases, array $arrivedOn, array $booked, array $closed): array
    {
        $own     = array_filter($purchases, static fn (int $p): bool => trim((string) ($arrivedOn[$p] ?? '')) !== '') !== [];
        $is      = static fn (string $value): bool => $s->hasValue($value) && $order->status === $s->value($value);
        $whole   = $order->hasArrived() || $is('status_partial') || $is('status_delivered') || $is('status_received');
        $shipped = $order->tracking !== [] || $is('status_shipped') || $whole;
        $out     = [];

        foreach ($purchases as $p) {
            $out[$p] = match (true) {
                isset($closed[$p])                            => self::NotStocked,
                isset($booked[$p])                            => self::Booked,
                trim((string) ($arrivedOn[$p] ?? '')) !== '' => self::Arrived,
                ! $own && $whole                              => self::Arrived,
                $shipped                                      => self::Shipped,
                default                                       => self::Ordered,
            };
        }

        return $out;
    }

    /** The cockpit tab the purchase is shown under: a closed purchase is done like a booked one. */
    public function stage(): InboundStage
    {
        return match ($this) {
            self::Ordered => InboundStage::Ordered,
            self::Shipped => InboundStage::Shipped,
            self::Arrived => InboundStage::Arrived,
            self::Booked, self::NotStocked => InboundStage::Booked,
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Ordered, self::Shipped, self::Arrived], true);
    }
}
