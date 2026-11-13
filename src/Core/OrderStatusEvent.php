<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1038: the inbox reports «versendet» or «zugestellt» for an order, the stock process sets the order status.
 *
 * The status only moves forward: ordered → shipped → delivered. An order already received, partly received, cancelled
 * or still a draft keeps its status; the same message twice changes nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1038
 */
final class OrderStatusEvent
{
    public const SHIPPED   = 'shipped';
    public const DELIVERED = 'delivered';

    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return list<array<string, mixed>> the change for the order, none when the event does not move it forward */
    public function changes(int $order, ?int $current, string $event): array
    {
        $s      = $this->structure;
        $target = match ($event) {
            self::SHIPPED   => 'status_shipped',
            self::DELIVERED => 'status_delivered',
            default         => null,
        };

        if ($target === null || ! $s->hasValue($target) || $order <= 0) {
            return [];
        }

        $from = $event === self::SHIPPED ? ['status_ordered'] : ['status_ordered', 'status_shipped'];
        $open = $current === null || $current === 0 || in_array($current, array_map(
            static fn (string $value): ?int => $s->hasValue($value) ? $s->value($value) : null,
            $from
        ), true);

        return $open ? [['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => (string) $s->value($target)]]] : [];
    }
}
