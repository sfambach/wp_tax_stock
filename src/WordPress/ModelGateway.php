<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Table;

/**
 * The module's only door into the model: the modeller's abilities, nothing of its internals.
 *
 * Reading is `taxmod/read-node` — one call per node, which is one query per table (CD-7). Writing is
 * `taxmod/write-data` (D-936): records only, one change number, all or nothing.
 *
 * ⚠️ One exception, {@see nodesOf()}: no ability says which node a record belongs to, and «Bezug» may point
 * at a record under any node of `Model`. Reading every node to find a label would be hundreds of reads.
 */
final class ModelGateway
{
    /** @var array<int, array<string, mixed>> node id → the answer of `read-node`, read once per request */
    private array $read = [];

    public function table(int $node): Table
    {
        $result  = $this->readNode($node);
        $records = [];

        foreach ($result['records'] ?? [] as $record) {
            $values = [];

            foreach ($record['values'] ?? [] as $value) {
                $values[(int) $value['relation_id']][] = (string) $value['value'];
            }

            $records[(int) $record['id']] = $values;
        }

        return new Table($records);
    }

    /**
     * The fields of a node as the modeller lists them, inherited ones included — from the same read as its records.
     *
     * @return array<int, array{name: string, target: int, type: string|null}> relation id → field
     */
    public function fields(int $node): array
    {
        $out = [];

        foreach ($this->readNode($node)['fields'] ?? [] as $field) {
            $out[(int) $field['relation_id']] = [
                'name'   => (string) ($field['name'] ?? ''),
                'target' => (int) ($field['target'] ?? 0),
                'type'   => isset($field['type']) ? (string) $field['type'] : null,
            ];
        }

        return $out;
    }

    /**
     * Every node under `$root` with its name and parent — one `read-tree` call.
     *
     * @return array<int, array{name: string, parent: int, class: string}>
     */
    public function tree(int $root): array
    {
        $out = [];

        foreach ($this->execute('taxmod/read-tree', ['root' => $root, 'depth' => 12])['rows'] ?? [] as $row) {
            $out[(int) $row['id']] = ['name' => (string) ($row['name'] ?? ''), 'parent' => (int) ($row['parent'] ?? 0), 'class' => (string) ($row['class'] ?? '')];
        }

        return $out;
    }

    /**
     * Which node each record belongs to, in one query through the modeller's record repository.
     *
     * @param list<int> $records
     * @return array<int, int> record id → node id; empty if the modeller does not offer it
     */
    public function nodesOf(array $records): array
    {
        $repository = \Taxmod\WordPress\Persistence\WpdbRecordRepository::class;

        if ($records === [] || ! class_exists($repository) || ! method_exists($repository, 'byIds')) {
            return [];
        }

        $out = [];

        foreach ((new $repository())->byIds($records) as $id => $record) {
            $out[(int) $id] = (int) $record->nodeId;
        }

        return $out;
    }

    /** @return array<int, string> child node id → name */
    public function children(int $node): array
    {
        $out = [];

        foreach ($this->execute('taxmod/read-tree', ['root' => $node, 'depth' => 1])['rows'] ?? [] as $row) {
            if ((int) ($row['parent'] ?? 0) === $node) {
                $out[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $out;
    }

    /**
     * The nodes below a node with their distance from it, and the node's own name — one `read-tree` call.
     *
     * @return array{name: string, steps: array<int, int>} node id → steps below `$root` (the root itself is 0)
     */
    public function subtree(int $root): array
    {
        $parents = [];
        $name    = '';

        foreach ($this->execute('taxmod/read-tree', ['root' => $root, 'depth' => 12])['rows'] ?? [] as $row) {
            $parents[(int) $row['id']] = (int) ($row['parent'] ?? 0);
            $name                      = (int) $row['id'] === $root ? (string) ($row['name'] ?? '') : $name;
        }

        $steps = [$root => 0];

        foreach (array_keys($parents) as $node) {
            $path = [];

            for ($at = $node; ! isset($steps[$at]) && isset($parents[$at]) && ! in_array($at, $path, true); $at = $parents[$at]) {
                $path[] = $at;
            }

            if (isset($steps[$at])) {
                foreach (array_reverse($path) as $i => $on) {
                    $steps[$on] = $steps[$at] + $i + 1;
                }
            }
        }

        return ['name' => $name, 'steps' => $steps];
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        return $this->execute('taxmod/write-data', ['changes' => $changes]);
    }

    /** @return array<string, mixed> */
    private function readNode(int $node): array
    {
        return $this->read[$node] ??= $this->execute('taxmod/read-node', ['node' => $node]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function execute(string $name, array $input): array
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;

        if ($ability === null) {
            throw StockError::modelRefused($name . ' is not available — is the Taxonomy Modeller active?');
        }

        $result = $ability->execute($input);

        if (is_wp_error($result)) {
            throw StockError::modelRefused($result->get_error_message());
        }

        return is_array($result) ? $result : [];
    }
}
