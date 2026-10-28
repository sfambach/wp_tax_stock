<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * When an order leaves the cockpit's working tabs for «Archiviert» (D-1008): nothing is stored, it follows from the dates.
 *
 * Booked orders go some days after their receipt; orders from before the start day are history and go at once.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC10
 */
final class InboundArchive
{
    /** @param string $since Y-m-d, the start day · @param string $today Y-m-d */
    public function __construct(
        public readonly string $since,
        public readonly int $days,
        public readonly string $today,
    ) {
    }

    /** An order placed before the start day: history, not an open receipt. */
    public function isHistory(?string $orderedOn): bool
    {
        return self::day($orderedOn) !== '' && self::day($orderedOn) < self::day($this->since);
    }

    /** A booked order whose receipt lies more than the set days back. */
    public function isDone(?string $receivedOn): bool
    {
        $day = self::day($receivedOn);

        return $day !== '' && (strtotime(self::day($this->today)) - strtotime($day)) / 86400 > $this->days;
    }

    private static function day(?string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $date) === 1 ? substr((string) $date, 0, 10) : '';
    }
}
