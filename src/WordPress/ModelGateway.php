<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use Taxmod\Core\Exception\ModelDoorRefused;
use Taxmod\WordPress\ModelDoor;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/**
 * The module's side of the modeller's one door into the model (`Taxmod\WordPress\ModelDoor`, D-1186): it only wraps the
 * answers in the core's {@see Table} and words a refusal as {@see StockError}. Reads, caching and the few repository
 * reads no ability offers live in the modeller, once for every module.
 */
final class ModelGateway
{
    private readonly ModelDoor $door;

    public function __construct()
    {
        $this->door = new ModelDoor();
    }

    public function table(int $node): Table
    {
        return new Table($this->ask(fn (): array => $this->door->records($node)));
    }

    /** @return array<int, array{name: string, target: int, type: string|null, kind: string, multiplicity: string}> relation id → field */
    public function fields(int $node): array
    {
        return $this->ask(fn (): array => $this->door->fields($node));
    }

    /** @return array<int, array{name: string, parent: int, class: string, hidden: bool}> */
    public function tree(int $root): array
    {
        return $this->ask(fn (): array => $this->door->tree($root));
    }

    /**
     * @param list<int> $records
     * @return array<int, int> record id → node id
     */
    public function nodesOf(array $records): array
    {
        return $this->door->nodesOf($records);
    }

    /**
     * @param list<int>                                   $nodes
     * @param list<array{text: string, nodes: list<int>}> $conditions
     * @return list<int>
     */
    public function recordsMatching(array $nodes, array $conditions, int $limit): array
    {
        return $this->door->recordsMatching($nodes, $conditions, $limit);
    }

    /**
     * @param list<int> $nodes
     * @return array<int, int> record id → node id
     */
    public function recordsOf(array $nodes, int $limit): array
    {
        return $this->door->recordsOf($nodes, $limit);
    }

    /**
     * The records whose field `$relation` points at one of these, or null from a modeller without that read (before 0.1.184).
     *
     * @param list<int> $records
     * @return list<int>|null
     */
    public function referrers(array $records, int $relation): ?array
    {
        return method_exists($this->door, 'referrers') ? $this->door->referrers($records, $relation) : null;
    }

    /** @param list<int> $records */
    public function records(array $records): ?Table
    {
        return new Table($this->door->valuesOf($records));
    }

    /** @return array<int, string> child node id → name */
    public function children(int $node): array
    {
        return $this->ask(fn (): array => $this->door->children($node));
    }

    /** @return array{name: string, steps: array<int, int>} */
    public function subtree(int $root): array
    {
        return $this->ask(fn (): array => $this->door->subtree($root));
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        return $this->ask(fn (): array => $this->door->write($changes));
    }

    /**
     * @param callable(): array<mixed> $read
     * @return array<mixed>
     */
    private function ask(callable $read): array
    {
        try {
            return $read();
        } catch (ModelDoorRefused $refused) {
            throw StockError::fromModel($refused->getMessage());
        }
    }
}
