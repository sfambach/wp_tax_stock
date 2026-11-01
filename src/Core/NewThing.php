<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A thing to create in the tree while booking: the group it goes into and its name (D-995: no thing, no material).
 */
final class NewThing
{
    /** @param LagerInput|null $lager D-1071: what the purchase already tells about its Lager view, filled in at once */
    public function __construct(public readonly int $node, public readonly string $name, public readonly ?LagerInput $lager = null)
    {
        if (trim($name) === '') {
            throw StockError::thingWithoutName();
        }

        if ($node < 1) {
            throw StockError::thingWithoutGroup();
        }
    }
}
