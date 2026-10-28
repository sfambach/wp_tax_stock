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
    /** D-995: the thing in the tree is the material; «lager» is the Lager view, one record per thing. */
    private const NODES = ['lager', 'lot', 'transaction', 'line', 'location', 'order', 'purchase', 'money'];

    private const FIELDS = [
        'label',
        'lager_thing', 'lager_single', 'lager_min',
        'lot_thing', 'lot_purchase', 'lot_location', 'lot_value', 'lot_date',
        'transaction_kind', 'transaction_date', 'transaction_order', 'transaction_source', 'transaction_lines',
        'line_lot', 'line_quantity', 'line_counted',
        'order_status', 'order_lines',
        'purchase_model', 'purchase_quantity', 'purchase_undetermined', 'purchase_landed_price', 'purchase_unit_price',
        'money_amount', 'money_unit',
    ];

    /** Nodes the module uses when they are there: the choices of «Verwendung». */
    private const OPTIONAL_NODES = ['lager_use'];

    /** Fields the module uses when they are there and leaves out when they are not. */
    private const OPTIONAL_FIELDS = [
        'description', 'title_image', 'lager_use', 'lager_location', 'transaction_project',
        // D-1001: the test after the goods receipt; its result is the lot's condition.
        'lot_condition', 'lot_tested_on', 'lot_test_note',
    ];

    private const VALUES = ['kind_receipt', 'kind_consumption', 'kind_count', 'source_manual', 'status_partial', 'status_received', 'location_default'];

    /** D-1000: draft and cancelled orders stand in no stage of the goods receipt cockpit. */
    private const OPTIONAL_VALUES = [
        'currency_default', 'status_draft', 'status_cancelled',
        'test_ok', 'test_partial', 'test_maintenance', 'test_defect', 'condition_new', 'condition_untested',
    ];

    /**
     * The keys of a structure pasted before 0.2.0, when the Lager view was still a material of its own (D-967).
     * The ids stay the same: «Material» of a lot was retargeted to the thing, not replaced (D-995).
     */
    private const FORMER_KEYS = [
        'nodes'  => ['material' => 'lager', 'material_use' => 'lager_use'],
        'fields' => [
            'material_ref_record' => 'lager_thing', 'material_single' => 'lager_single', 'material_min' => 'lager_min',
            'material_use' => 'lager_use', 'material_location' => 'lager_location', 'lot_material' => 'lot_thing',
        ],
    ];

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

    /**
     * The stored ids, completed by the bundled ones (`structure-live.json`) where the stored structure is that one: every
     * id both name is the same. A new release then reads the ids it added without pasting the JSON again; a structure of
     * another site, which differs somewhere, stays as it is.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $bundled
     * @return array<string, mixed>
     */
    public static function completed(array $stored, array $bundled): array
    {
        $shared = 0;

        foreach (['nodes', 'fields', 'values'] as $group) {
            foreach (is_array($stored[$group] ?? null) ? $stored[$group] : [] as $key => $id) {
                if (isset($bundled[$group][$key])) {
                    if ((int) $bundled[$group][$key] !== (int) $id) {
                        return $stored;
                    }

                    ++$shared;
                }
            }
        }

        if ($shared === 0) {
            return $stored;
        }

        foreach (['nodes', 'fields', 'values'] as $group) {
            $stored[$group] = (is_array($stored[$group] ?? null) ? $stored[$group] : []) + (is_array($bundled[$group] ?? null) ? $bundled[$group] : []);
        }

        return $stored;
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

        foreach (self::FORMER_KEYS[$group] ?? [] as $former => $key) {
            $given[$key] ??= $given[$former] ?? null;
        }

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
