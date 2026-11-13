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

    /**
     * D-1250: the modeller's own controls for these fields, posted as `$prefix[relation]`; nothing on a modeller without them.
     *
     * @param list<int>          $relations
     * @param array<int, string> $values characters by relation id where there is no record yet
     * @param array<int, int>    $roots  relation id → the node its chooser is narrowed to
     * @return array<int, string> relation id → markup, escaped
     */
    public function controls(int $node, array $relations, string $prefix, string $formId = '', int $record = 0, array $values = [], array $roots = []): array
    {
        // D-1298: `$roots` narrows a chooser to a branch (modeller 0.2.63); an older modeller ignores the extra argument.
        return method_exists($this->door, 'controls') ? $this->door->controls($node, $relations, $prefix, $formId, $record, $values, $roots) : [];
    }

    /**
     * @param array<int|string, mixed> $submitted `$_POST[$prefix]`; `mixed`: as posted
     * @return array<int, string> relation id → raw value, empty ones left out
     */
    public function typed(int $node, array $submitted): array
    {
        return method_exists($this->door, 'typed') ? $this->door->typed($node, $submitted) : [];
    }

    /**
     * D-1259: a group property of these nodes, own or inherited; empty on a modeller without group properties.
     *
     * @param list<int> $nodes
     * @return array<int, bool|int|null> node → value
     */
    public function properties(array $nodes, string $key): array
    {
        return $nodes !== [] && method_exists($this->door, 'propertiesOf') ? $this->ask(fn (): array => $this->door->propertiesOf($nodes, $key)) : [];
    }

    /**
     * D-1259: only the values set at these nodes themselves, nothing inherited (modeller 0.2.43).
     *
     * @param list<int> $nodes
     * @return array<int, bool|int> node → own value; a node without one is missing
     */
    public function ownProperties(array $nodes, string $key): array
    {
        return $nodes !== [] && method_exists($this->door, 'ownPropertiesOf') ? $this->ask(fn (): array => $this->door->ownPropertiesOf($nodes, $key)) : [];
    }

    /**
     * D-1268: who points at these records through any field, in one read (modeller 0.2.45; deleting needs 0.2.47).
     *
     * @param list<int> $records
     * @return array<int, list<int>> referred record → the records pointing at it
     */
    public function referrersOf(array $records): array
    {
        // D-1270: before 0.2.47 removing a place emptied the oldest line naming it, and that line's stock moved home.
        if (! method_exists($this->door, 'referrersOf') || version_compare((string) \Taxmod\WordPress\Plugin::VERSION, '0.2.47', '<')) {
            throw StockError::fromModel('The Taxonomy Modeller is too old to delete a place (0.2.47).');
        }

        return $records === [] ? [] : $this->ask(fn (): array => $this->door->referrersOf($records));
    }

    /** D-1284: the node a field points to (modeller 0.2.56); 0 before, so a label by Bauform simply stays away. */
    public function targetOf(int $relation): int
    {
        return method_exists($this->door, 'targetOf') ? $this->ask(fn (): array => [$this->door->targetOf($relation)])[0] : 0;
    }

    /**
     * D-1291: the modeller's words for these fields of these records (modeller 0.2.59); none before, and the caller keeps its
     * plain number.
     *
     * @param list<int> $records
     * @param list<int> $relations
     * @return array<int, array<int, string>> relation id → record id → word
     */
    public function fieldWords(array $records, array $relations): array
    {
        return method_exists($this->door, 'fieldWords') ? $this->ask(fn (): array => $this->door->fieldWords($records, $relations)) : [];
    }

    /** D8, D-1331: whether the modeller can make a node below another (0.2.79). */
    public function canCreateChild(): bool
    {
        return method_exists($this->door, 'createChild');
    }

    /** D8, D-1331: a new node below `$parent`, of its default child class; one act, undoable. */
    public function createChild(int $parent, string $name): int
    {
        if (! $this->canCreateChild()) {
            throw StockError::fromModel('The Taxonomy Modeller is too old to create a group (0.2.79).');
        }

        return (int) ($this->ask(fn (): array => ['id' => $this->door->createChild($parent, $name)])['id'] ?? 0);
    }

    /** D-1259: one act, undoable; null lets the node inherit again. */
    public function setProperty(int $node, string $key, bool|int|null $value): void
    {
        if (! method_exists($this->door, 'ownPropertiesOf')) {
            throw StockError::fromModel('The Taxonomy Modeller is too old for group properties (0.2.43).');
        }

        $this->ask(function () use ($node, $key, $value): array {
            $this->door->setProperty($node, $key, $value);

            return [];
        });
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
