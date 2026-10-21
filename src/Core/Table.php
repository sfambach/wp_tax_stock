<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The records of one node with their raw values, as the modeller hands them out (one read per node, CD-7).
 *
 * A value is the stored text: a number, a date, or the id of the record or node it points at.
 */
final class Table
{
    /** @param array<int, array<int, list<string>>> $records record id → relation id → values */
    public function __construct(private readonly array $records)
    {
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->records);
    }

    public function has(int $recordId): bool
    {
        return isset($this->records[$recordId]);
    }

    public function first(int $recordId, int $relationId): ?string
    {
        return $this->records[$recordId][$relationId][0] ?? null;
    }

    /** @return list<string> */
    public function all(int $recordId, int $relationId): array
    {
        return $this->records[$recordId][$relationId] ?? [];
    }

    /** @return array<int, list<string>> relation id → values */
    public function valuesOf(int $recordId): array
    {
        return $this->records[$recordId] ?? [];
    }
}
