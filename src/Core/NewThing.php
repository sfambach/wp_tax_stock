<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A thing to create in the tree while booking: the group it goes into and its name (D-995: no thing, no material).
 */
final class NewThing
{
    public function __construct(public readonly int $node, public readonly string $name)
    {
        if (trim($name) === '') {
            throw StockError::thingWithoutName();
        }

        if ($node < 1) {
            throw StockError::thingWithoutGroup();
        }
    }
}
