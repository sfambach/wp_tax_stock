<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The records of one node with their raw values, as the modeller hands them out (one read per node, CD-7).
 *
 * A value is the stored text: a number, a date, or the id of the record or node it points at.
 *
 * A table may hold only the fields its reader named (D-1486): the big nodes carry values no page of the stock shows, and
 * reading them cost most of the memory of a page. Asked for another field, it reads the whole node once and answers from that.
 */
final class Table
{
    /** How often a narrow table had to read the whole node after all — what a check counts (D-1486). */
    public static int $widened = 0;

    /**
     * @param array<int, array<int, list<string>>>                 $records record id → relation id → values
     * @param array<int, true>|null                                $only    the fields that were read; null: all of them
     * @param (\Closure(): array<int, array<int, list<string>>>)|null $whole   reads every field, for a question outside `$only`
     */
    public function __construct(private array $records, private ?array $only = null, private readonly ?\Closure $whole = null)
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
        $this->reach($relationId);

        return $this->records[$recordId][$relationId][0] ?? null;
    }

    /** @return list<string> */
    public function all(int $recordId, int $relationId): array
    {
        $this->reach($relationId);

        return $this->records[$recordId][$relationId] ?? [];
    }

    /** @return array<int, list<string>> relation id → values */
    public function valuesOf(int $recordId): array
    {
        $this->widen();

        return $this->records[$recordId] ?? [];
    }

    /** The same records with one value replaced — what a booking reads when it writes that value in the same step. */
    public function with(int $recordId, int $relationId, string $value): self
    {
        $put = static function (array $records) use ($recordId, $relationId, $value): array {
            $records[$recordId][$relationId] = [$value];

            return $records;
        };
        $whole = $this->whole;

        return new self(
            $put($this->records),
            $this->only === null ? null : $this->only + [$relationId => true],
            $this->only === null || $whole === null ? null : static fn (): array => $put($whole())
        );
    }

    /** A field that was not read is never answered as empty: the whole node is read once, and the answer is the true one. */
    private function reach(int $relationId): void
    {
        if ($this->only !== null && ! isset($this->only[$relationId])) {
            $this->widen();
        }
    }

    private function widen(): void
    {
        if ($this->only === null) {
            return;
        }

        $this->records = $this->whole !== null ? ($this->whole)() : $this->records;
        $this->only    = null;
        ++self::$widened;
    }
}
