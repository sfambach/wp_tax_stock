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
    private const OPTIONAL_NODES = ['lager_use', 'source', 'firm', 'base_units', 'prefixes', 'project', 'sale',
        // D-1123: the kinds of place and the parts a kind brings along.
        'location_type', 'location_type_part',
        // D-1139: a project's boards, their revisions and the revisions' positions — the parts list of a project box.
        'board', 'board_revision', 'bom_position',
        // D-1153, D-1159: the labels; D-1160: the category a material prints from.
        'label_template', 'label_materials'];

    /** Fields the module uses when they are there and leaves out when they are not. */
    private const OPTIONAL_FIELDS = [
        'description', 'title_image', 'lager_use', 'lager_location', 'transaction_project',
        // D-1002: the test after the goods receipt; its result is the lot's condition.
        'lot_condition', 'lot_tested_on', 'lot_test_note',
        // D-1018: «Bezugsquellen» of the Lager view — firm and number — and the name of a firm.
        'lager_sources', 'source_firm', 'source_number', 'firm_name',
        // D-1019: the base unit the Lager view counts in, and how much of it one pack of a source holds.
        'lager_unit', 'lager_prefix', 'source_content',
        // D-1022: shipping and fees of a purchase, from which the model calculates the landed price.
        'purchase_shipping_share', 'purchase_fees_share',
        // The purchase tab of a material: who sold it, when, and what it cost in all.
        'order_merchant', 'order_platform', 'order_date', 'purchase_total_price',
        // D-1027, purchasing: the supplier's order number, the article number of a purchase, and the demand it came from.
        'order_number', 'purchase_number', 'purchase_demand',
        // D-1058: why a purchase was closed without going into stock.
        'purchase_not_stocked',
        // D-1030: the place a place sits in, whether it is a box, and the project a box belongs to.
        'location_parent', 'location_movable', 'location_project',
        // D-1039: the place a Pos line moves at; without it a line sits where its lot came in («Lagerort» of the lot).
        'line_location',
        // D-1036: a line that cancels another one (Storno), pointing at it.
        'line_reverses',
        // D-1082: a place reserved for production; everything on it and below it is reserved.
        'location_reserved',
        // D-1083: the reservation's subject (project or sale; D-1088: also the purpose of a consumption), its deadline, and the material of a position.
        'transaction_reference', 'transaction_deadline', 'line_thing',
        // D-1091: the box (a movable place) a Vorgang «Umlagerung» moved.
        'transaction_carrier',
        // D-1123: a place's kind; a kind's movable mark and parts; a part's kind and count.
        'location_place_type', 'type_movable', 'type_parts', 'part_type', 'part_count',
        // D-1139: the way from a project to its parts list.
        'project_boards', 'board_revisions', 'revision_positions', 'position_part', 'position_count',
        // D-1150: a place's short mark (A1-S1), put before its name wherever the place is named.
        'location_short',
        // D-1151: the unit name a line of a place type's parts gives the places it brings.
        'part_unit_name',
        // D-1153: label formats and templates as records; a place type names its template and how many labels.
        // D-1159: a label carries its own size; the separate format records are gone.
        'template_key', 'template_width', 'template_height', 'template_roll', 'template_copies', 'template_layout',
        'type_label_template', 'type_label_copies',
    ];

    private const VALUES = ['kind_receipt', 'kind_consumption', 'kind_count', 'source_manual', 'status_partial', 'status_received', 'location_default'];

    /** D-1001: draft and cancelled orders stand in no stage of the goods receipt cockpit. */
    private const OPTIONAL_VALUES = [
        'currency_default', 'status_draft', 'status_cancelled', 'status_ordered',
        'test_ok', 'test_partial', 'test_maintenance', 'test_defect', 'condition_new', 'condition_untested',
        // D-1039 «Umbuchung», D-1083 «Reservierung»: kinds of Vorgang.
        'kind_transfer', 'kind_reservation',
        // D-1091: a box moved with its content, no lot rebooked.
        'kind_relocation',
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
     * D-1097: the lists of positions that may be compositions — then the positions are parts of their head. Which kind a
     * list has is read from the model ({@see withComposed()}), never stored here, so the module works before and after the
     * switch. Field key → the key of the head node that declares it.
     */
    private const POSITION_LISTS = ['transaction_lines' => 'transaction', 'order_lines' => 'order'];

    /**
     * @param array<string, int>  $nodes
     * @param array<string, int>  $fields
     * @param array<string, int>  $values
     * @param array<string, true> $composed field keys of position lists that are compositions in the model
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $fields,
        private readonly array $values,
        private readonly array $composed = [],
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

    /**
     * The same ids, told which position lists the model holds as compositions (D-1097): their positions are written as
     * parts of the head, moved with `move_part` (D-1098) and go with the head (D-1099).
     *
     * @param list<string> $keys field keys, e.g. «order_lines»
     */
    public function withComposed(array $keys): self
    {
        return new self($this->nodes, $this->fields, $this->values, array_fill_keys(array_values(array_intersect(array_keys(self::POSITION_LISTS), $keys)), true));
    }

    /** Whether the positions of this list are parts of their head (D-1097), as the model said when it was read. */
    public function composes(string $key): bool
    {
        return isset($this->composed[$key]);
    }

    /** @return array<string, string> the position lists that may be compositions: field key → key of its head node */
    public static function positionLists(): array
    {
        return self::POSITION_LISTS;
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
