<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\MaterialChanges;
use TaxmodStock\Core\MaterialInput;
use TaxmodStock\Core\MaterialNames;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\ReceiptPlan;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/**
 * Reads the Stock tables once per request and hands bookings to the gateway — the seam between page and core.
 */
final class StockService
{
    public const OPTION = 'taxmod_stock_structure';

    /** @var array<int, Table> node id → its records, read once */
    private array $tables = [];

    public function __construct(private readonly ModelGateway $model = new ModelGateway())
    {
    }

    public function structure(): Structure
    {
        $raw = get_option(self::OPTION, []);

        return Structure::fromArray(is_array($raw) ? $raw : []);
    }

    public function table(string $node): Table
    {
        return $this->tableOf($this->structure()->node($node));
    }

    public function tableOf(int $node): Table
    {
        return $this->tables[$node] ??= $this->model->table($node);
    }

    /** D-967: «#id · name», the name read from the referenced things — one read per node they live in (CD-7). */
    public function materialNames(): MaterialNames
    {
        $s         = $this->structure();
        $materials = $this->table('material');

        return new MaterialNames($s, $materials, $this->recordLabels(MaterialNames::referencedThings($s, $materials)));
    }

    /**
     * The labels of records anywhere in the model, grouped by node so that each node is read once.
     *
     * @param list<int> $records
     * @return array<int, string>
     */
    public function recordLabels(array $records): array
    {
        $field  = $this->structure()->field('label');
        $byNode = [];
        $out    = [];

        foreach ($this->model->nodesOf($records) as $record => $node) {
            $byNode[$node][] = $record;
        }

        foreach ($byNode as $node => $ids) {
            $table = $this->tableOf($node);

            foreach ($ids as $id) {
                $out[$id] = (string) $table->first($id, $field);
            }
        }

        return $out;
    }

    /** @return array<int, string> the choices of «Verwendung», empty if it is not configured */
    public function uses(): array
    {
        $s = $this->structure();

        return $s->hasNode('material_use') ? $this->model->children($s->node('material_use')) : [];
    }

    public function ledger(): Ledger
    {
        return new Ledger($this->structure(), $this->table('lot'), $this->table('line'));
    }

    /** @return array<int, string> record id → its label */
    public function labels(string $node): array
    {
        $table = $this->table($node);
        $field = $this->structure()->field('label');
        $out   = [];

        foreach ($table->ids() as $id) {
            $out[$id] = (string) ($table->first($id, $field) ?? '#' . $id);
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * The goods receipt's proposal per purchase of the order, and why a purchase is locked.
     *
     * @return array<int, array{lock: string|null, model: int|null, materials: list<int>, proposal: ReceiptChoice|null}>
     */
    public function receiptPlan(int $order, ?MaterialNames $names = null): array
    {
        $s = $this->structure();
        // A structure pasted before 0.1.2 has no «Modell»: say so instead of proposing nothing.
        $s->field('purchase_model');

        return (new ReceiptPlan($s))->of(
            $order,
            $this->table('order'),
            $this->table('purchase'),
            $names ?? $this->materialNames(),
            Ledger::receivedPurchases($s, $this->table('lot'))
        );
    }

    /**
     * @param list<InventoryRow> $rows
     * @return array<string, mixed>
     */
    public function bookInventory(array $rows, string $today, string $title): array
    {
        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        // The name of a row only labels its lot and line: an existing material is called «#id · name», a new one
        // with a reference after the referenced thing (D-967).
        $names  = $this->materialNames();
        $things = $this->recordLabels(array_values(array_filter(array_map(static fn (InventoryRow $r): ?int => $r->material === null ? $r->thing : null, $rows))));
        $named  = array_map(
            static fn (InventoryRow $r): InventoryRow => new InventoryRow(
                $r->material,
                match (true) {
                    $r->material !== null => $names->has($r->material) ? $names->name($r->material) : throw StockError::notAMaterial($r->material),
                    $r->name !== ''       => $r->name,
                    default               => (string) ($things[(int) $r->thing] ?? ''),
                },
                $r->thing,
                $r->quantity,
                $r->value,
                $r->note
            ),
            $rows
        );

        return $this->model->write((new Bookings($this->structure(), $today))->inventory($named, $title));
    }

    /**
     * @param array<int, ReceiptChoice> $choices purchase id → its material
     * @return array<string, mixed>
     */
    public function bookReceipt(int $order, array $choices, string $today, string $title): array
    {
        $s = $this->structure();
        // A structure pasted before 0.1.2 has no «Modell»: say so instead of proposing nothing.
        $s->field('purchase_model');

        $changes = (new Bookings($s, $today))->receipt(
            $order,
            $choices,
            $this->table('order'),
            $this->table('purchase'),
            $this->table('material'),
            $this->table('money'),
            Ledger::receivedPurchases($s, $this->table('lot')),
            $title
        );

        return $this->model->write($changes);
    }

    /** @return array<string, mixed> the modeller's answer; a new material's id is under `refs.m` */
    public function saveMaterial(?int $material, MaterialInput $input): array
    {
        $changes = new MaterialChanges($this->structure());

        if ($material !== null && ! $this->table('material')->has($material)) {
            throw StockError::notAMaterial($material);
        }

        return $this->model->write($material === null ? $changes->create($input) : $changes->update($material, $input));
    }

    /** @return array<string, mixed> */
    public function bookConsumption(int $material, ?int $lot, string $quantity, ?int $project, string $today, string $title): array
    {
        $ledger = $this->ledger();
        $lot  ??= $ledger->proposedLot($material);

        if ($ledger->materialOf($lot) !== $material) {
            throw StockError::noLot($material);
        }

        return $this->model->write((new Bookings($this->structure(), $today))->consumption($lot, $quantity, $project, $title));
    }
}
