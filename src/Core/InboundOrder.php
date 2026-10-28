<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * One order as the goods receipt cockpit reads it, and the stage that follows from it (D-1000).
 *
 * Nothing here is stored by the module: status, «Eingegangen am» and the tracking numbers are the order's own fields,
 * the DHL stages are the DHL module's last answers. The first rule that holds wins.
 *
 * ```mermaid
 * flowchart LR
 *   S["status draft/cancelled"] -->|none| X["no tab"]
 *   R["status received"] --> B["Booked"]
 *   A["partial · received on · DHL delivered"] --> AR["Arrived"]
 *   T["tracking number"] --> SH["Shipped"]
 *   E["else"] --> O["Ordered"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC2
 */
final class InboundOrder
{
    /** Days after «Bestellt am» an order without a tracking number counts as overdue (INFERRED, WC4). */
    public const ORDERED_DAYS = 14;

    /** Days after «Bestellt am» a shipped order that has not arrived counts as overdue (INFERRED, WC4). */
    public const SHIPPED_DAYS = 21;

    /** The DHL module's stage for a delivered parcel (`taxmod_dhl_tracking`). */
    public const DHL_DELIVERED = 'delivered';

    /**
     * @param list<string>          $tracking the order's tracking numbers
     * @param array<string, string> $dhl      tracking number → the DHL module's last stage, where it has one
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $status,
        public readonly ?string $orderedOn,
        public readonly ?string $receivedOn,
        public readonly array $tracking,
        public readonly array $dhl = [],
    ) {
    }

    public function stage(Structure $s): InboundStage
    {
        $is = static fn (string $value, ?int $status): bool => $s->hasValue($value) && $status === $s->value($value);

        return match (true) {
            $is('status_draft', $this->status), $is('status_cancelled', $this->status) => InboundStage::None,
            $is('status_received', $this->status)                                      => InboundStage::Booked,
            $is('status_partial', $this->status), $this->hasArrived()                   => InboundStage::Arrived,
            $this->tracking !== []                                                      => InboundStage::Shipped,
            default                                                                     => InboundStage::Ordered,
        };
    }

    /** Whether he said it is here, a delivery message said so, or DHL delivered a parcel of it. */
    public function hasArrived(): bool
    {
        return ($this->receivedOn ?? '') !== '' || in_array(self::DHL_DELIVERED, $this->dhl, true);
    }

    /** Overdue: ordered too long ago without a tracking number, or shipped too long ago and not arrived. */
    public function overdue(Structure $s, string $today): bool
    {
        if ($this->orderedOn === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $this->orderedOn) !== 1) {
            return false;
        }

        $days = (int) ((strtotime(substr($today, 0, 10)) - strtotime(substr($this->orderedOn, 0, 10))) / 86400);

        return match ($this->stage($s)) {
            InboundStage::Ordered => $days > self::ORDERED_DAYS,
            InboundStage::Shipped => $days > self::SHIPPED_DAYS,
            default               => false,
        };
    }
}
