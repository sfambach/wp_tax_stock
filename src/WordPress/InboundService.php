<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\BuildPrices;
use TaxmodStock\Core\InboundChanges;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\NotStocked;
use TaxmodStock\Core\PlatformLink;
use TaxmodStock\Core\PriceCheck;
use TaxmodStock\Core\PurchaseStatus;
use TaxmodStock\Core\ReceiptReversal;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestQueue;
use TaxmodStock\Core\TestResult;

/**
 * Reads the incoming orders for the goods receipt cockpit and writes its buttons (D-1001).
 *
 * The order fields come from the modeller's import targets — the inbox writes there, so they are one fact with one owner
 * (D-952). The carrier stages come from the carrier modules' filters in their default mode, which hand back the last
 * answer and never call the carrier; only {@see refreshTracking()} asks. D-1132: DHL first, then GLS, the first answer wins.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC2, WC3
 */
final class InboundService
{
    /** The DHL module's filter (wp_tax_api_dhl); its third argument `refresh` asks DHL now. */
    public const DHL_FILTER = 'taxmod_dhl_tracking';

    /** The GLS module's filter (wp_tax_api_gls), same arguments and answer as DHL's (D-1132). */
    public const GLS_FILTER = 'taxmod_gls_tracking';

    /** Carrier → its filter, in the order they are asked (D-1132). */
    public const CARRIERS = ['DHL' => self::DHL_FILTER, 'GLS' => self::GLS_FILTER];

    /** The roles of the order fields the cockpit reads, as the import targets name them. */
    private const ROLES = ['ordered_at', 'received_at', 'tracking', 'platform', 'merchant', 'order_number'];

    /** @var array<int, InboundOrder>|null read once per request */
    private ?array $orders = null;

    /** @var array<string, int>|null role → field of the order, {@see self::fields()} */
    private ?array $fields = null;

    /** @var array<int, list<int>>|null purchase → its lots, read once per request */
    private ?array $lotsOfPurchase = null;

    /** @var array<string, array<string, mixed>> tracking number → the first carrier's last answer plus `carrier`; `mixed`: its summary */
    private array $answers = [];

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
        $booked = $this->stock->receivedPurchases();
        $closed = NotStocked::purchases($s, $this->stock->table('purchase'));
        $arrivedOn = $this->arrivedOn();

        foreach ($table->ids() as $id) {
            $tracking = array_values(array_filter(array_map('trim', $table->all($id, $fields['tracking'])), static fn (string $n): bool => $n !== ''));
            $stages   = [];

            foreach ($tracking as $number) {
                $answer = $this->trackingAnswer($number);

                if ($answer !== null) {
                    $stages[$number] = (string) ($answer['stage'] ?? '');
                }
            }

            $status   = $table->first($id, $s->field('order_status'));
            $make     = static fn (array $purchases): InboundOrder => new InboundOrder(
                $id,
                $status === null || $status === '' ? null : (int) $status,
                $table->first($id, $fields['ordered_at']),
                $table->first($id, $fields['received_at']),
                $tracking,
                $stages,
                NotStocked::wholeOrder($s, $table, $id, $booked, $closed),
                NotStocked::allIn($s, $table, $id, $booked, $closed),
                $purchases
            );
            $lines    = array_map('intval', $table->all($id, $s->field('order_lines')));
            // D-1411: each purchase's own status, from the one rule in the core.
            $out[$id] = $make(PurchaseStatus::of($s, $make([]), $lines, $arrivedOn, $booked, $closed));
        }

        return $this->orders = $out;
    }

    /** @return array<int, string> purchase → its own «Angekommen am» (D-1411); empty without the field */
    private function arrivedOn(): array
    {
        $s = $this->stock->structure();

        if (! $s->hasField('purchase_arrived_on')) {
            return [];
        }

        $purchases = $this->stock->table('purchase');
        $out       = [];

        foreach ($purchases->ids() as $id) {
            $on = trim((string) ($purchases->first($id, $s->field('purchase_arrived_on')) ?? ''));

            if ($on !== '') {
                $out[$id] = $on;
            }
        }

        return $out;
    }

    /** The order's own text fields the list shows: platform, seller, number. */
    public function about(int $order): string
    {
        $table  = $this->stock->table('order');
        $fields = $this->fields();

        $stock  = $this->stock;

        return implode(' · ', array_filter(array_map(
            // D-1487: the platform may point at a firm — its word, not its id.
            static fn (string $role): string => $role === 'platform' ? $stock->platformWord((string) $table->first($order, $fields[$role])) : trim((string) $table->first($order, $fields[$role])),
            ['platform', 'merchant', 'order_number']
        )));
    }

    /** The order's number as its platform gave it, '' without one. */
    public function orderNumber(int $order): string
    {
        return trim((string) ($this->stock->table('order')->first($order, $this->fields()['order_number']) ?? ''));
    }

    /** D-1249: the link to the order at its platform, built from the firm's template and the order number; null without either. */
    public function orderLink(int $order): ?string
    {
        $table  = $this->stock->table('order');
        $fields = $this->fields();

        return PlatformLink::filled($this->templates($order)['order'], (string) ($table->first($order, $fields['order_number']) ?? ''));
    }

    /**
     * D-1448: where an order was placed and under which number, with the link to it at the platform (null without template) —
     * read from the order table already in memory, no query per order.
     *
     * @return array{platform: string, number: string, link: ?string}
     */
    public function orderFacts(int $order): array
    {
        $table = $this->stock->table('order');

        return [
            'platform' => $this->stock->platformWord((string) ($table->first($order, $this->fields()['platform']) ?? '')),
            'number'   => trim((string) ($table->first($order, $this->fields()['order_number']) ?? '')),
            'link'     => $this->orderLink($order),
        ];
    }

    /** D-1249: the link to a purchase's product at the order's platform, from its article number; null without template or number. */
    public function productLink(int $order, string $articleNumber): ?string
    {
        return PlatformLink::filled($this->templates($order)['product'], $articleNumber);
    }

    /** @return array{order: string, product: string} the templates of the firm named in the order's «Plattform» */
    private function templates(int $order): array
    {
        return $this->stock->linkTemplates($this->stock->platformWord((string) ($this->stock->table('order')->first($order, $this->fields()['platform']) ?? '')));
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

    /**
     * The last answer for a number from the first carrier module that has one, DHL before GLS (D-1132).
     *
     * @return array<string, mixed>|null its summary with `carrier` (DHL, GLS) added; `mixed`: the module's summary array
     */
    public function trackingAnswer(string $number): ?array
    {
        if (! array_key_exists($number, $this->answers)) {
            $this->answers[$number] = [];

            foreach ($this->carriers() as $carrier => $filter) {
                $answer = apply_filters($filter, null, $number);

                if (is_array($answer)) {
                    $this->answers[$number] = ['carrier' => $carrier] + $answer;
                    break;
                }
            }
        }

        return $this->answers[$number] === [] ? null : $this->answers[$number];
    }

    public function hasTracking(): bool
    {
        return $this->carriers() !== [];
    }

    /** @return array<string, string> carrier → filter, only the modules that are active */
    private function carriers(): array
    {
        return array_filter(self::CARRIERS, static fn (string $filter): bool => has_filter($filter) !== false);
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

    /**
     * D-1411: «Angekommen …» for the ticked purchases of one order.
     *
     * @param list<int> $purchases
     * @return array<string, mixed>
     */
    public function markPurchasesArrived(int $id, array $purchases, string $today): array
    {
        $changes = $this->changes()->purchasesArrived($this->pick([$id])[0], $purchases, $today);
        $this->orders = null;

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /**
     * D-1411: «Nicht angekommen …» for the ticked purchases of one order.
     *
     * @param list<int> $purchases
     * @return array<string, mixed>
     */
    public function markPurchasesNotArrived(int $id, array $purchases): array
    {
        $changes = $this->changes()->purchasesNotArrived($this->pick([$id])[0], $purchases, $this->arrivedOn());
        $this->orders = null;

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /** @return array<string, mixed> */
    public function markNotArrived(int $id): array
    {
        $order   = $this->pick([$id])[0];
        $changes = $this->changes()->notArrived($order, $this->purchaseCounts($id)['booked'], $this->arrivedOn());

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /**
     * D-1009: «Eingebucht» → «Angekommen», the receipt goes back out of stock.
     *
     * @return array<string, mixed>
     */
    public function unbook(int $id): array
    {
        $stock = $this->stock;
        $this->pick([$id]);

        return $stock->write((new ReceiptReversal($stock->structureWithKinds()))->reverse($id, $stock->table('order'), $stock->table('lot'), $stock->table('line'), $stock->table('transaction')));
    }

    /**
     * Architecture 16.11. («alle rückgängig»): each booked purchase whose lots are untouched goes back; one whose lot was
     * moved on or used stays with its lot, and the others go on.
     *
     * @return array{done: list<int>, skipped: array<int, int>} purchases taken back; skipped purchase → its lot
     */
    public function unbookFree(int $id): array
    {
        $stock = $this->stock;
        $this->pick([$id]);
        $plan  = (new ReceiptReversal($stock->structureWithKinds()))->reverseFree($id, $stock->table('order'), $stock->table('lot'), $stock->table('line'), $stock->table('transaction'));
        $stock->write($plan['changes']);

        return ['done' => $plan['done'], 'skipped' => $plan['skipped']];
    }

    /** @return array<string, mixed> */
    public function addTracking(int $id, string $number): array
    {
        $changes = $this->changes()->tracking($this->pick([$id])[0], $number);

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /** @return array<string, mixed> */
    public function removeTracking(int $id, string $number): array
    {
        $changes = $this->changes()->untrack($this->pick([$id])[0], $number);

        return $changes === [] ? ['applied' => []] : $this->stock->write($changes);
    }

    /**
     * Asks the carriers now for every tracking number of the chosen orders: the one that answered before, else DHL, then
     * GLS until one answers (D-1132). Each module keeps its own pause between calls and its budget.
     *
     * @param list<int> $ids
     * @return int how many numbers got an answer
     */
    public function refreshTracking(array $ids): int
    {
        $answered = 0;

        foreach ($this->pick($ids) as $order) {
            foreach ($order->tracking as $number) {
                $known    = (string) ($this->trackingAnswer($number)['carrier'] ?? '');
                $carriers = $this->carriers();
                $carriers = isset($carriers[$known]) ? [$known => $carriers[$known]] : $carriers;

                foreach ($carriers as $filter) {
                    if (is_array(apply_filters($filter, null, $number, 'refresh'))) {
                        ++$answered;
                        break;
                    }
                }
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
        $parents = $stock->groupParents();

        foreach ($stock->table('lot')->ids() as $lot) {
            $thing       = $ledger->thingOf($lot);
            $nodes[$lot] = $thing === null ? null : $things->nodeOf($thing);
        }

        $lots = $this->tests()->waiting($ledger, $stock->table('lot'), $nodes, $parents, $stock->testSettings(array_values(array_filter($nodes))));
        $date = $stock->structure()->field('lot_date');
        usort($lots, static fn (int $a, int $b): int => strcmp((string) $stock->table('lot')->first($b, $date), (string) $stock->table('lot')->first($a, $date)) ?: $b <=> $a);

        return $lots;
    }

    /**
     * D-1375: the purchases among these whose unit price lies more than the set share above the last earlier purchase of
     * the same material, compared in the reference currency. One read of the purchases, their orders and their amounts (CD-7).
     *
     * @param list<int> $checked
     * @return array<int, array{previous: int, previous_unit: float, unit: float, percent: float, previous_order: ?int}>
     */
    public function priceWarnings(array $checked): array
    {
        $stock     = $this->stock;
        $s         = $stock->structure();
        $purchases = $stock->table('purchase');
        $wanted    = [];

        foreach ($checked as $id) {
            $material = (int) ($purchases->first($id, $s->field('purchase_model')) ?? 0);

            if ($material > 0) {
                $wanted[$material] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $orderOf = $this->orderOfPurchase();
        $facts   = $this->purchaseFacts(array_keys($wanted), $orderOf);

        return array_map(static fn (array $w): array => $w + ['previous_order' => $orderOf[$w['previous']] ?? null], PriceCheck::warnings($checked, $facts, (float) $stock->priceWarnPercent()));
    }

    /**
     * D-1375, D-1425: every purchase of these materials with its order date and its price per piece in the reference
     * currency (null where it has none or cannot be converted) — one read of the purchases, orders and amounts (CD-7).
     *
     * @param list<int>       $materials
     * @param array<int, int> $orderOf purchase → order, {@see orderOfPurchase()}
     * @return array<int, array{material: int, date: string, unit: float|null}>
     */
    public function purchaseFacts(array $materials, array $orderOf): array
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $orders    = $this->stock->table('order');
        $wanted    = array_flip($materials);
        $about     = array_values(array_filter($purchases->ids(), static fn (int $id): bool => isset($wanted[(int) ($purchases->first($id, $s->field('purchase_model')) ?? 0)])));
        $parts     = array_combine($about, array_map(static fn (int $id): int => (int) ($purchases->first($id, $s->field('purchase_unit_price')) ?? 0), $about)) ?: [];
        $euro      = $this->inEuro(array_values($parts));
        $facts     = [];

        foreach ($about as $id) {
            $order = $orderOf[$id] ?? null;
            $date  = $order === null || ! $s->hasField('order_date') ? '' : substr(trim((string) $orders->first($order, $s->field('order_date'))), 0, 10);

            $facts[$id] = ['material' => (int) $purchases->first($id, $s->field('purchase_model')), 'date' => $date, 'unit' => $euro[$parts[$id]] ?? null];
        }

        return $facts;
    }

    /**
     * Money parts → their amount in the reference currency; a foreign amount through the frozen rate the modeller keeps at
     * it (D-966: 1 euro = rate units). A part without amount, or foreign without rate, is left out.
     *
     * @param list<int> $parts
     * @return array<int, float>
     */
    public function inEuro(array $parts): array
    {
        $s         = $this->stock->structure();
        $parts     = array_values(array_unique(array_filter($parts)));
        $money     = $this->stock->recordsOf('money', $parts);
        $rateField = (int) get_option('taxmod_unit_value_rate_field', 0);
        $reference = $s->hasValue('currency_default') ? (string) $s->value('currency_default') : '';
        $out       = [];

        foreach ($parts as $part) {
            $amount = $money->first($part, $s->field('money_amount'));
            $unit   = (string) ($money->first($part, $s->field('money_unit')) ?? '');
            $rate   = $rateField > 0 ? $money->first($part, $rateField) : null;
            $value  = match (true) {
                $amount === null || ! is_numeric($amount) => null,
                $unit === '' || $unit === $reference      => (float) $amount,
                is_numeric($rate) && (float) $rate > 0.0  => (float) $amount / (float) $rate,
                default                                   => null,
            };

            if ($value !== null) {
                $out[$part] = $value;
            }
        }

        return $out;
    }

    /**
     * D-1425: what a build of `$lines` would take from `$place`, per material and lot with its price per piece, planned as
     * {@see StockService::bookBuild()} books it — and what this order scrapped so far, shown but not priced (INF-132).
     *
     * @param array<int, float> $lines thing → pieces
     * @return array{parts: array<int, list<array{lot: int, quantity: float, unit_price: float|null, source: string}>>, scrap: array<int, float>}
     */
    public function buildPrices(int $reference, int $place, array $lines): array
    {
        $stock  = $this->stock;
        $s      = $stock->structure();
        $taking = array_filter($lines, static fn (float $q): bool => $q > 0.0);
        $rows   = $taking === [] ? [] : $stock->buildPlan($reference, $place, $taking);
        $ledger = $stock->ledger();
        $lots   = array_values(array_unique(array_column($rows, 'lot')));
        $table  = $stock->table('lot');
        $thingOf = [];

        foreach ($lots as $lot) {
            $thing = $ledger->thingOf($lot);

            if ($thing !== null) {
                $thingOf[$lot] = $thing;
            }
        }

        // The consumed lot's own value per piece; else the price of the purchase it came from.
        $valueOf    = $s->hasField('lot_value') ? array_filter(array_combine($lots, array_map(static fn (int $lot): int => (int) $table->first($lot, $s->field('lot_value')), $lots)) ?: []) : [];
        $purchaseOf = array_filter(array_combine($lots, array_map(static fn (int $lot): int => (int) $table->first($lot, $s->field('lot_purchase')), $lots)) ?: []);
        $euro       = $this->inEuro(array_values($valueOf));
        $facts      = $thingOf === [] ? [] : $this->purchaseFacts(array_values(array_unique($thingOf)), $this->orderOfPurchase());
        $lotPrice   = [];

        foreach ($lots as $lot) {
            $price = isset($valueOf[$lot]) ? ($euro[$valueOf[$lot]] ?? null) : null;
            $price ??= isset($purchaseOf[$lot]) ? ($facts[$purchaseOf[$lot]]['unit'] ?? null) : null;

            if ($price !== null) {
                $lotPrice[$lot] = $price;
            }
        }

        return [
            'parts' => BuildPrices::of($rows, $thingOf, $lotPrice, BuildPrices::lastPurchase($facts)),
            'scrap' => $stock->scrappedFor($reference),
        ];
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

    /** @return list<int> the lots booked from a purchase — where its goods went */
    public function lotsOf(int $purchase): array
    {
        if ($this->lotsOfPurchase === null) {
            $s     = $this->stock->structure();
            $table = $this->stock->table('lot');
            $this->lotsOfPurchase = [];

            foreach ($table->ids() as $lot) {
                $from = (int) $table->first($lot, $s->field('lot_purchase'));

                if ($from > 0) {
                    $this->lotsOfPurchase[$from][] = $lot;
                }
            }
        }

        return $this->lotsOfPurchase[$purchase] ?? [];
    }

    /** @return array<string, mixed> */
    public function recordTest(int $lot, TestResult $result, string $reason, string $today): array
    {
        if (! $this->stock->table('lot')->has($lot)) {
            throw StockError::nothingToBook();
        }

        // D-1503: «defekt» and «teilweise OK» go on to «Klärung» in the same act.
        return $this->stock->recordTestsRouted([$lot], $result, $reason, $today, __('Test: zur Klärung', 'taxmod-stock'));
    }

    /**
     * Stefan 2026-11-20 «massen aktionen fehlen im zu testen»: one result for every ticked lot, as one act. A result that needs a
     * reason is not given in bulk — each lot has its own.
     *
     * @param list<int> $lots
     * @return array<string, mixed>
     */
    public function recordTests(array $lots, TestResult $result, string $today): array
    {
        $table = $this->stock->table('lot');
        $lots  = array_values(array_filter($lots, static fn (int $lot): bool => $table->has($lot)));

        if ($lots === []) {
            throw StockError::nothingToBook();
        }

        return $this->stock->recordTestsRouted($lots, $result, '', $today, __('Test: zur Klärung', 'taxmod-stock'));
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

        $s      = $this->stock->structure();

        return new InboundChanges($fields['received_at'], $fields['tracking'], $s->hasField('purchase_arrived_on') ? $s->field('purchase_arrived_on') : null);
    }

    /**
     * The order fields from the modeller's import targets.
     *
     * @return array<string, int> role → relation id
     */
    private function fields(): array
    {
        // Once per request: the modeller reads its import targets from the database on every call, and each order asks.
        if ($this->fields !== null) {
            return $this->fields;
        }

        $targets = \Taxmod\WordPress\Import\Targets::class;
        $raw     = class_exists($targets) && method_exists($targets, 'raw') ? (array) ($targets::raw()['order_fields'] ?? []) : [];
        $out     = [];

        foreach (self::ROLES as $role) {
            $out[$role] = (int) ($raw[$role] ?? 0) ?: throw StockError::noImportTargets($role);
        }

        return $this->fields = $out;
    }
}
