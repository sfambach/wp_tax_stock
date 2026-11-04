<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A record copied beside itself under a name of its own, as one change list (D-1130): every value, its own copy of each part
 * (D-1012), and what it only points at it keeps pointing at.
 */
final class NamedCopy
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return list<array<string, mixed>> the copy answers the ref «kopie» */
    public function of(int $record, string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            throw StockError::placeWithoutName();
        }

        return [
            ['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => (string) $record],
            ['op' => 'set_values', 'record' => '@kopie', 'values' => [(string) $this->structure->field('label') => $name]],
        ];
    }
}
