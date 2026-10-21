<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Where the Stock tables live in the model: node, field and value ids, read from one option.
 *
 * The module never names a node by its label (code standard: no special-casing by name); every id it
 * writes to comes from here. `structure-live.json` holds the ids of fambach.net.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §12
 */
final class Structure
{
    private const NODES = ['material', 'lot', 'transaction', 'line', 'location', 'order', 'purchase', 'money'];

    private const FIELDS = [
        'label',
        'material_single', 'material_min',
        'lot_material', 'lot_purchase', 'lot_location', 'lot_value', 'lot_date',
        'transaction_kind', 'transaction_date', 'transaction_order', 'transaction_source', 'transaction_lines',
        'line_lot', 'line_quantity', 'line_counted',
        'order_status', 'order_lines',
        'purchase_material', 'purchase_quantity', 'purchase_undetermined', 'purchase_landed_price', 'purchase_unit_price',
        'money_amount', 'money_unit',
    ];

    /** Nodes the module uses when they are there: the choices of «Verwendung». */
    private const OPTIONAL_NODES = ['material_use'];

    /**
     * Fields the module uses when they are there and leaves out when they are not.
     *
     * `purchase_model` came with 0.1.2: an older pasted structure keeps working, only the goods receipt asks for it.
     */
    private const OPTIONAL_FIELDS = ['description', 'material_ref_record', 'material_use', 'material_location', 'transaction_project', 'purchase_model'];

    private const VALUES = ['kind_receipt', 'kind_consumption', 'kind_count', 'source_manual', 'status_partial', 'status_received', 'location_default'];

    private const OPTIONAL_VALUES = ['currency_default'];

    /**
     * @param array<string, int> $nodes
     * @param array<string, int> $fields
     * @param array<string, int> $values
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $fields,
        private readonly array $values,
    ) {
    }

    /** @param array<string, mixed> $raw The decoded JSON or the stored option. */
    public static function fromArray(array $raw): self
    {
        return new self(
            self::ids($raw, 'nodes', self::NODES, self::OPTIONAL_NODES),
            self::ids($raw, 'fields', self::FIELDS, self::OPTIONAL_FIELDS),
            self::ids($raw, 'values', self::VALUES, self::OPTIONAL_VALUES),
        );
    }

    public function node(string $key): int
    {
        return $this->nodes[$key] ?? throw StockError::notConfigured('nodes.' . $key);
    }

    public function field(string $key): int
    {
        return $this->fields[$key] ?? throw StockError::notConfigured('fields.' . $key);
    }

    public function value(string $key): int
    {
        return $this->values[$key] ?? throw StockError::notConfigured('values.' . $key);
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function hasField(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function hasValue(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /** @return array{nodes: array<string, int>, fields: array<string, int>, values: array<string, int>} */
    public function toArray(): array
    {
        return ['nodes' => $this->nodes, 'fields' => $this->fields, 'values' => $this->values];
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>         $required
     * @param list<string>         $optional
     * @return array<string, int>
     */
    private static function ids(array $raw, string $group, array $required, array $optional): array
    {
        $given = is_array($raw[$group] ?? null) ? $raw[$group] : [];
        $ids   = [];

        foreach ([...$required, ...$optional] as $key) {
            $id = filter_var($given[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false) {
                $ids[$key] = $id;
            } elseif (in_array($key, $required, true)) {
                throw StockError::notConfigured($group . '.' . $key);
            }
        }

        return $ids;
    }
}
