<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\Ledger;
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
        $id = $this->structure()->node($node);

        return $this->tables[$id] ??= $this->model->table($id);
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
     * Why a purchase of the order cannot be received, or null if it can (R1, and «received already»).
     *
     * @return array<int, string|null> purchase id → reason code
     */
    public function purchasesOf(int $order): array
    {
        $s         = $this->structure();
        $purchases = $this->table('purchase');
        $received  = Ledger::receivedPurchases($s, $this->table('lot'));
        $out       = [];

        foreach ($this->table('order')->all($order, $s->field('order_lines')) as $id) {
            $id = (int) $id;

            $out[$id] = match (true) {
                isset($received[$id])                                                     => 'received',
                $purchases->first($id, $s->field('purchase_undetermined')) === '1'        => 'purchase_undetermined',
                $purchases->all($id, $s->field('purchase_material')) === []               => 'purchase_without_material',
                default                                                                   => null,
            };
        }

        return $out;
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

        $labels = $this->labels('material');
        $named  = array_map(
            static fn (InventoryRow $r): InventoryRow => $r->material === null ? $r : new InventoryRow($r->material, $labels[$r->material] ?? '#' . $r->material, $r->thing, $r->quantity, $r->value, $r->note),
            $rows
        );

        return $this->model->write((new Bookings($this->structure(), $today))->inventory($named, $title));
    }

    /**
     * @param list<int> $purchases
     * @return array<string, mixed>
     */
    public function bookReceipt(int $order, array $purchases, string $today, string $title): array
    {
        $s       = $this->structure();
        $changes = (new Bookings($s, $today))->receipt(
            $order,
            $purchases,
            $this->table('order'),
            $this->table('purchase'),
            $this->table('money'),
            Ledger::receivedPurchases($s, $this->table('lot')),
            $title
        );

        return $this->model->write($changes);
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
