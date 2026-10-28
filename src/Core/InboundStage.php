<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Where an incoming order stands on the goods receipt cockpit (D-1001): one tab per case, «none» only under «Alle», «archived» only under its own tab (D-1008).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC1
 */
enum InboundStage: string
{
    case Ordered = 'ordered';
    case Shipped = 'shipped';
    case Arrived = 'arrived';
    case Booked  = 'booked';
    case Archived = 'archived';
    case None    = 'none';

    /** @return list<self> the stages that have a tab, in their order */
    public static function tabs(): array
    {
        return [self::Ordered, self::Shipped, self::Arrived, self::Booked];
    }
}
