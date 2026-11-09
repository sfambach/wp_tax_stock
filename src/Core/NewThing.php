<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A thing to create in the tree while booking: the group it goes into and its name (D-995: no thing, no material).
 */
final class NewThing
{
    /**
     * @param LagerInput|null    $lager  D-1071: what the purchase already tells about its Lager view, filled in at once
     * @param array<int, string> $values D-1255: further fields of the material asked in its dialog (the manufacturer), relation id → raw value
     */
    public function __construct(public readonly int $node, public readonly string $name, public readonly ?LagerInput $lager = null, public readonly array $values = [])
    {
        if (trim($name) === '') {
            throw StockError::thingWithoutName();
        }

        if ($node < 1) {
            throw StockError::thingWithoutGroup();
        }
    }
}
