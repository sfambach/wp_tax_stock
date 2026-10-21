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
 * ⚠️ One exception, {@see nodesOf()}: no ability says which node a record belongs to, and «Bezug Ding» may point
 * at a record under any node of `Model`. Reading every node to find a label would be hundreds of reads.
 */
final class ModelGateway
{
    public function table(int $node): Table
    {
        $result  = $this->execute('taxmod/read-node', ['node' => $node]);
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
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        return $this->execute('taxmod/write-data', ['changes' => $changes]);
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
