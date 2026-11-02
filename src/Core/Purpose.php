<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What a consumption is for (D-1088): a subject record — a project or a sale, the Bezug a reservation names — or
 * «Produktion», the Bezug of a reserved place without project; or nothing named. Reserved goods are consumed only for
 * exactly their purpose.
 */
final class Purpose
{
    private function __construct(public readonly int $subject, public readonly bool $production)
    {
    }

    public static function none(): self
    {
        return new self(0, false);
    }

    public static function subject(int $subject): self
    {
        return $subject > 0 ? new self($subject, false) : self::none();
    }

    /** Chosen explicitly: «für Produktion», no record named. */
    public static function production(): self
    {
        return new self(0, true);
    }

    /** Whether this purpose is the Bezug of a reserved place: its project, or «Produktion» when it has none (0). */
    public function matchesPlace(int $project): bool
    {
        return $project > 0 ? $this->subject === $project : $this->production;
    }
}
