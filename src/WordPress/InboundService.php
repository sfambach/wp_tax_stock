<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\InboundChanges;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestQueue;
use TaxmodStock\Core\TestResult;

/**
 * Reads the incoming orders for the goods receipt cockpit and writes its buttons (D-1001).
 *
 * The order fields come from the modeller's import targets — the inbox writes there, so they are one fact with one owner
 * (D-952). The DHL stages come from the DHL module's filter in its default mode, which hands back the last answer and
 * never calls DHL; only {@see refreshDhl()} asks DHL.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC2, WC3
 */
final class InboundService
{
    /** The DHL module's filter (wp_tax_api_dhl); its third argument `refresh` asks DHL now. */
    public const DHL_FILTER = 'taxmod_dhl_tracking';

    /** The roles of the order fields the cockpit reads, as the import targets name them. */
    private const ROLES = ['ordered_at', 'received_at', 'tracking', 'platform', 'merchant', 'order_number'];

    /** @var array<int, InboundOrder>|null read once per request */
    private ?array $orders = null;

    /** @var array<string, array<string, mixed>> tracking number → the DHL module's last answer; `mixed`: its summary */
    private array $dhl = [];

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    public function stock(): StockService
    {
        return $this->stock;
    }

    /** @return array<int, InboundOrder> record id → order, every order the model has */
    public function orders(): array
    {
        if ($this->orders !== null) {
            return $this->orders;
        }

        $s      = $this->stock->structure();
        $table  = $this->stock->table('order');
        $fields = $this->fields();
        $out    = [];

        foreach ($table->ids() as $id) {
            $tracking = array_values(array_filter(array_map('trim', $table->all($id, $fields['tracking'])), static fn (string $n): bool => $n !== ''));
            $stages   = [];

            foreach ($tracking as $number) {
                $answer = $this->dhlAnswer($number);

                if ($answer !== null) {
                    $stages[$number] = (string) ($answer['stage'] ?? '');
                }
            }

            $status   = $table->first($id, $s->field('order_status'));
            $out[$id] = new InboundOrder(
                $id,
                $status === null || $status === '' ? null : (int) $status,
                $table->first($id, $fields['ordered_at']),
                $table->first($id, $fields['received_at']),
                $tracking,
                $stages
            );
        }

        return $this->orders = $out;
    }

    /** The order's own text fields the list shows: platform, seller, number. */
    public function about(int $order): string
    {
        $table  = $this->stock->table('order');
        $fields = $this->fields();

        return implode(' · ', array_filter(array_map(
            static fn (string $role): string => trim((string) $table->first($order, $fields[$role])),
            ['platform', 'merchant', 'order_number']
        )));
    }

    /**
     * Per order: its purchases, how many are not yet determined (R1) and how many are in stock.
     *
     * @return array{total: int, undetermined: int, booked: int}
     */
    public function purchaseCounts(int $order): array
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $received  = $this->stock->receivedPurchases();
        $ids       = array_map('intval', $this->stock->table('order')->all($order, $s->field('order_lines')));

        return [
            'total'        => count($ids),
            'undetermined' => count(array_filter($ids, static fn (int $id): bool => $purchases->first($id, $s->field('purchase_undetermined')) === '1')),
            'booked'       => count(array_filter($ids, static fn (int $id): bool => isset($received[$id]))),
        ];
    }

    /** @return array<string, mixed>|null the DHL module's last answer for a number; `mixed`: its summary array */
    public function dhlAnswer(string $number): ?array
    {
        if (! has_filter(self::DHL_FILTER)) {
            return null;
        }

        if (! array_key_exists($number, $this->dhl)) {
            $answer             = apply_filters(self::DHL_FILTER, null, $number);
            $this->dhl[$number] = is_array($answer) ? $answer : [];
        }

        return $this->dhl[$number] === [] ? null : $this->dhl[$number];
    }

    public function hasDhl(): bool
    {
        return has_filter(self::DHL_FILTER) !== false;
    }

    /**
     * «Angekommen» for the chosen orders, in one write.
     *
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    public function markArrived(array $ids, string $today): array
    {
        $changes = $this->changes()->arrived($this->pick($ids), $today);

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /** @return array<string, mixed> */
    public function markNotArrived(int $id): array
    {
        $order   = $this->pick([$id])[0];
        $changes = $this->changes()->notArrived($order, $this->purchaseCounts($id)['booked']);

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /** @return array<string, mixed> */
    public function addTracking(int $id, string $number): array
    {
        $changes = $this->changes()->tracking($this->pick([$id])[0], $number);

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /**
     * Asks DHL now for every tracking number of the chosen orders; the DHL module keeps 5 s between calls and its budget.
     *
     * @param list<int> $ids
     * @return int how many numbers got an answer
     */
    public function refreshDhl(array $ids): int
    {
        if (! $this->hasDhl()) {
            return 0;
        }

        $answered = 0;

        foreach ($this->pick($ids) as $order) {
            foreach ($order->tracking as $number) {
                $answered += is_array(apply_filters(self::DHL_FILTER, null, $number, 'refresh')) ? 1 : 0;
            }
        }

        return $answered;
    }

    public function tests(): TestQueue
    {
        return new TestQueue($this->stock->structure());
    }

    /**
     * D-1002: the lots that wait for their test, newest receipt first.
     *
     * @return list<int>
     */
    public function lotsToTest(): array
    {
        $stock   = $this->stock;
        $ledger  = $stock->ledger();
        $things  = $stock->things();
        $nodes   = [];
        $parents = array_map(static fn (array $node): int => $node['parent'], $stock->tree());

        foreach ($stock->table('lot')->ids() as $lot) {
            $thing       = $ledger->thingOf($lot);
            $nodes[$lot] = $thing === null ? null : $things->nodeOf($thing);
        }

        $lots = $this->tests()->waiting($ledger, $stock->table('lot'), $nodes, $parents, $stock->testSettings());
        $date = $stock->structure()->field('lot_date');
        usort($lots, static fn (int $a, int $b): int => strcmp((string) $stock->table('lot')->first($b, $date), (string) $stock->table('lot')->first($a, $date)) ?: $b <=> $a);

        return $lots;
    }

    /** @return array<int, int> purchase → the order it is a line of */
    public function orderOfPurchase(): array
    {
        $s     = $this->stock->structure();
        $table = $this->stock->table('order');
        $out   = [];

        foreach ($table->ids() as $order) {
            foreach ($table->all($order, $s->field('order_lines')) as $purchase) {
                $out[(int) $purchase] = $order;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function recordTest(int $lot, TestResult $result, string $reason, string $today): array
    {
        if (! $this->stock->table('lot')->has($lot)) {
            throw StockError::nothingToBook();
        }

        return $this->stock->write($this->tests()->record($lot, $result, $reason, $today));
    }

    /**
     * @param list<int> $ids
     * @return list<InboundOrder>
     */
    private function pick(array $ids): array
    {
        $orders = $this->orders();
        $picked = array_values(array_filter(array_map(static fn (int $id): ?InboundOrder => $orders[$id] ?? null, $ids)));

        return $picked === [] ? throw StockError::nothingToBook() : $picked;
    }

    private function changes(): InboundChanges
    {
        $fields = $this->fields();

        return new InboundChanges($fields['received_at'], $fields['tracking']);
    }

    /**
     * The order fields from the modeller's import targets.
     *
     * @return array<string, int> role → relation id
     */
    private function fields(): array
    {
        $targets = \Taxmod\WordPress\Import\Targets::class;
        $raw     = class_exists($targets) && method_exists($targets, 'raw') ? (array) ($targets::raw()['order_fields'] ?? []) : [];
        $out     = [];

        foreach (self::ROLES as $role) {
            $out[$role] = (int) ($raw[$role] ?? 0) ?: throw StockError::noImportTargets($role);
        }

        return $out;
    }
}
