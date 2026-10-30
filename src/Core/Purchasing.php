<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Purchasing in three steps (D-1027): a demand without a supplier, a source per position, an order per supplier.
 *
 * A demand is an order «Entwurf» without «Händler»; a supplier's draft is an order «Entwurf» with one. Choosing a source
 * moves the purchase from the demand into its supplier's draft and lets it point back at the demand, so the demand stays
 * the bracket — all parts of a project, bought from several suppliers. Every step has its way back.
 *
 * ```mermaid
 * flowchart LR
 *   M["Material «Nachbestellen»"] --> B["Bedarf (Entwurf, kein Händler)"]
 *   B -->|Bezugsquelle| E["Entwurf beim Händler"] -->|bestellt| O["Bestellung «bestellt»"]
 *   E -->|zurück| B
 *   O -->|zurück| E
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/einkauf.md
 */
final class Purchasing
{
    /** @var list<array<string, mixed>> */
    private array $changes = [];

    private int $counter = 0;

    public function __construct(private readonly Structure $structure, private readonly string $today)
    {
    }

    /** A demand: a draft without a supplier. */
    public function isDemand(int $order, Table $orders): bool
    {
        return $this->isDraft($order, $orders) && $this->merchantOf($order, $orders) === '';
    }

    /** A supplier's draft: a draft with a supplier, not yet ordered. */
    public function isSupplierDraft(int $order, Table $orders): bool
    {
        return $this->isDraft($order, $orders) && $this->merchantOf($order, $orders) !== '';
    }

    public function isOrdered(int $order, Table $orders): bool
    {
        $s = $this->structure;

        return $s->hasValue('status_ordered') && (int) $orders->first($order, $s->field('order_status')) === $s->value('status_ordered');
    }

    public function isCancelled(int $order, Table $orders): bool
    {
        return $this->statusIs($order, $orders, 'status_cancelled');
    }

    public function merchantOf(int $order, Table $orders): string
    {
        $s = $this->structure;

        return $s->hasField('order_merchant') ? trim((string) $orders->first($order, $s->field('order_merchant'))) : '';
    }

    /**
     * The quantity «Nachbestellen» proposes: what is missing to the minimum, counting what is already on its way; at least 1.
     */
    public static function proposedQuantity(?float $minimum, float $stock, float $open): int
    {
        if ($minimum === null || $minimum <= 0.0) {
            return 1;
        }

        return max(1, (int) ceil($minimum - $stock - $open));
    }

    /**
     * Per thing, the quantity in drafts and ordered orders — what is wanted or coming but not yet in stock.
     *
     * @return array<int, float> thing → quantity
     */
    public function openQuantities(Table $orders, Table $purchases): array
    {
        $s   = $this->structure;
        $out = [];

        foreach ($orders->ids() as $order) {
            if (! $this->isDraft($order, $orders) && ! $this->isOrdered($order, $orders)) {
                continue;
            }

            foreach ($orders->all($order, $s->field('order_lines')) as $line) {
                $thing = (int) $purchases->first((int) $line, $s->field('purchase_model'));

                if ($thing > 0) {
                    $out[$thing] = ($out[$thing] ?? 0.0) + (float) ($purchases->first((int) $line, $s->field('purchase_quantity')) ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * «Nachbestellen»: the things go into a demand — an open one, or a new one with this name. A thing already in the
     * demand gets the quantity added instead of a second position.
     *
     * @param array<int, array{label: string, quantity: string}> $things thing → its label and the quantity
     * @return list<array<string, mixed>>
     */
    public function reorder(array $things, ?int $demand, string $newName, Table $orders, Table $purchases): array
    {
        $s = $this->structure;

        if ($things === []) {
            throw StockError::nothingToBook();
        }

        $this->start();

        if ($demand !== null) {
            if (! $orders->has($demand) || ! $this->isDemand($demand, $orders)) {
                throw StockError::notADemand($demand);
            }

            $record = (string) $demand;
            $lines  = $orders->all($demand, $s->field('order_lines'));
        } else {
            $newName = trim($newName);

            if ($newName === '') {
                throw StockError::demandWithoutName();
            }

            $record          = '@' . $this->ref('b');
            $lines           = [];
            $this->changes[] = ['op' => 'create_record', 'ref' => substr($record, 1), 'node' => $s->node('order')];
            $this->changes[] = ['op' => 'set_values', 'record' => $record, 'values' => $this->draftValues($newName, '')];
        }

        $held = [];

        foreach ($lines as $line) {
            $held[(int) $purchases->first((int) $line, $s->field('purchase_model'))] = (int) $line;
        }

        $added = [];

        foreach ($things as $thing => $row) {
            $quantity = (int) Quantity::positive($row['quantity']);
            $quantity = max(1, $quantity);

            if (isset($held[$thing])) {
                $was             = (int) ($purchases->first($held[$thing], $s->field('purchase_quantity')) ?? 0);
                $this->changes[] = ['op' => 'set_values', 'record' => (string) $held[$thing], 'values' => [(string) $s->field('purchase_quantity') => (string) ($was + $quantity)]];
                continue;
            }

            $ref             = $this->ref('k');
            $added[]         = '@' . $ref;
            $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('purchase')];
            $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => [
                (string) $s->field('label')                 => trim($row['label']) !== '' ? trim($row['label']) : '#' . $thing,
                (string) $s->field('purchase_model')        => (string) $thing,
                (string) $s->field('purchase_quantity')     => (string) $quantity,
                (string) $s->field('purchase_undetermined') => '0',
            ]];
        }

        if ($added !== []) {
            $this->changes[] = ['op' => 'set_values', 'record' => $record, 'values' => [(string) $s->field('order_lines') => ['values' => [...$lines, ...$added]]]];
        }

        return $this->changes;
    }

    /**
     * «Bezugsquelle wählen»: the position leaves its demand for the supplier's draft — the open one of that supplier, or
     * a new one — and keeps the demand as its bracket. Article number and price per piece come along where given.
     *
     * @return list<array<string, mixed>>
     */
    public function source(int $demand, int $purchase, string $merchant, string $number, ?string $unitPrice, Table $orders, Table $purchases): array
    {
        $s        = $this->structure;
        $merchant = trim($merchant);

        if (! $orders->has($demand) || ! $this->isDemand($demand, $orders)) {
            throw StockError::notADemand($demand);
        }

        $lines = $orders->all($demand, $s->field('order_lines'));

        if (! in_array((string) $purchase, $lines, true)) {
            throw StockError::notInOrder($purchase);
        }

        if ($merchant === '') {
            throw StockError::noMerchant();
        }

        $this->start();
        $draft = $this->draftOf($merchant, $orders);

        if ($draft === null) {
            $target          = '@' . $this->ref('e');
            $draftLines      = [];
            $label           = $merchant . ' · ' . trim((string) $orders->first($demand, $s->field('label')));
            $this->changes[] = ['op' => 'create_record', 'ref' => substr($target, 1), 'node' => $s->node('order')];
            $this->changes[] = ['op' => 'set_values', 'record' => $target, 'values' => $this->draftValues($label, $merchant)];
        } else {
            $target     = (string) $draft;
            $draftLines = $orders->all($draft, $s->field('order_lines'));
        }

        $values = [(string) $s->field('purchase_demand') => (string) $demand];

        if ($s->hasField('purchase_number') && trim($number) !== '') {
            $values[(string) $s->field('purchase_number')] = trim($number);
        }

        if ($unitPrice !== null && trim($unitPrice) !== '') {
            $values[(string) $s->field('purchase_unit_price')] = $this->moneyPart(Quantity::amount($unitPrice), $purchases->first($purchase, $s->field('purchase_unit_price')) !== null);
        }

        $this->changes[] = ['op' => 'set_values', 'record' => (string) $purchase, 'values' => $values];
        $this->changes[] = ['op' => 'set_values', 'record' => (string) $demand, 'values' => [(string) $s->field('order_lines') => ['values' => array_values(array_diff($lines, [(string) $purchase]))]]];
        $this->changes[] = ['op' => 'set_values', 'record' => $target, 'values' => [(string) $s->field('order_lines') => ['values' => [...$draftLines, (string) $purchase]]]];

        return $this->changes;
    }

    /**
     * Back from the supplier's draft into the demand it came from. A draft left without positions is cancelled, not deleted.
     *
     * @return list<array<string, mixed>>
     */
    public function unsource(int $draft, int $purchase, Table $orders, Table $purchases): array
    {
        $s = $this->structure;

        if (! $orders->has($draft) || ! $this->isSupplierDraft($draft, $orders)) {
            throw StockError::notADraft($draft);
        }

        $lines  = $orders->all($draft, $s->field('order_lines'));
        $demand = (int) $purchases->first($purchase, $s->field('purchase_demand'));

        if (! in_array((string) $purchase, $lines, true)) {
            throw StockError::notInOrder($purchase);
        }

        if ($demand <= 0 || ! $orders->has($demand) || ! $this->isDemand($demand, $orders)) {
            throw StockError::notADemand($demand);
        }

        $left = array_values(array_diff($lines, [(string) $purchase]));
        $this->start();
        $this->changes[] = ['op' => 'set_values', 'record' => (string) $demand, 'values' => [(string) $s->field('order_lines') => ['values' => [...$orders->all($demand, $s->field('order_lines')), (string) $purchase]]]];
        $this->changes[] = ['op' => 'set_values', 'record' => (string) $draft, 'values' => [(string) $s->field('order_lines') => ['values' => $left]]
            + ($left === [] && $s->hasValue('status_cancelled') ? [(string) $s->field('order_status') => (string) $s->value('status_cancelled')] : [])];

        return $this->changes;
    }

    /**
     * A position's quantity in a demand or a supplier's draft.
     *
     * @return list<array<string, mixed>>
     */
    public function setQuantity(int $order, int $purchase, string $quantity, Table $orders): array
    {
        $s = $this->structure;
        $this->requireDraftLine($order, $purchase, $orders);

        return [['op' => 'set_values', 'record' => (string) $purchase, 'values' => [(string) $s->field('purchase_quantity') => (string) max(1, (int) Quantity::positive($quantity))]]];
    }

    /**
     * A position taken out of a demand or a supplier's draft; «Nachbestellen» brings it back.
     *
     * @return list<array<string, mixed>>
     */
    public function removeLine(int $order, int $purchase, Table $orders): array
    {
        $s     = $this->structure;
        $lines = $this->requireDraftLine($order, $purchase, $orders);

        return [
            ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_lines') => ['values' => array_values(array_diff($lines, [(string) $purchase]))]]],
            ['op' => 'remove_record', 'record' => (string) $purchase],
        ];
    }

    /**
     * «Als bestellt markieren»: he has ordered at the supplier; the order gets its number and today's date.
     *
     * @return list<array<string, mixed>>
     */
    public function markOrdered(int $draft, string $number, Table $orders): array
    {
        $s = $this->structure;

        if (! $orders->has($draft) || ! $this->isSupplierDraft($draft, $orders)) {
            throw StockError::notADraft($draft);
        }

        if ($orders->all($draft, $s->field('order_lines')) === []) {
            throw StockError::nothingToBook();
        }

        $values = [(string) $s->field('order_status') => (string) $s->value('status_ordered')];

        if ($s->hasField('order_number') && trim($number) !== '') {
            $values[(string) $s->field('order_number')] = trim($number);
        }

        if ($s->hasField('order_date')) {
            $values[(string) $s->field('order_date')] = $this->today;
        }

        return [['op' => 'set_values', 'record' => (string) $draft, 'values' => $values]];
    }

    /**
     * Back from «bestellt» to the supplier's draft — only while nothing of it has arrived.
     *
     * @return list<array<string, mixed>>
     */
    public function backToDraft(int $order, Table $orders, string $receivedOn): array
    {
        $s = $this->structure;

        if (! $orders->has($order) || ! $this->isOrdered($order, $orders) || $this->merchantOf($order, $orders) === '') {
            throw StockError::notADraft($order);
        }

        if (trim($receivedOn) !== '') {
            throw StockError::alreadyBooked();
        }

        return [['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => (string) $s->value('status_draft')]]];
    }

    /**
     * «Verwerfen»: a demand or draft is cancelled, nothing is deleted; «Wiederherstellen» makes it a draft again.
     *
     * @return list<array<string, mixed>>
     */
    public function discard(int $order, Table $orders): array
    {
        $s = $this->structure;

        if (! $orders->has($order) || ! $this->isDraft($order, $orders)) {
            throw StockError::notADraft($order);
        }

        return [['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => (string) $s->value('status_cancelled')]]];
    }

    /** @return list<array<string, mixed>> */
    public function restore(int $order, Table $orders): array
    {
        $s = $this->structure;

        if (! $orders->has($order) || ! $this->isCancelled($order, $orders)) {
            throw StockError::notADraft($order);
        }

        return [['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_status') => (string) $s->value('status_draft')]]];
    }

    /**
     * The positions of a supplier's draft as one line each, «Art.-Nr. Menge», for the shop's quick order.
     *
     * @return list<string>
     */
    public function cartLines(int $draft, Table $orders, Table $purchases): array
    {
        $s   = $this->structure;
        $out = [];

        foreach ($orders->all($draft, $s->field('order_lines')) as $line) {
            $number = $s->hasField('purchase_number') ? trim((string) $purchases->first((int) $line, $s->field('purchase_number'))) : '';
            $out[]  = ($number !== '' ? $number : (string) $purchases->first((int) $line, $s->field('label'))) . ' ' . (int) ($purchases->first((int) $line, $s->field('purchase_quantity')) ?? 1);
        }

        return $out;
    }

    /** The open draft of a supplier, the oldest if several; null when there is none. */
    public function draftOf(string $merchant, Table $orders): ?int
    {
        $ids = $orders->ids();
        sort($ids);

        foreach ($ids as $order) {
            if ($this->isSupplierDraft($order, $orders) && mb_strtolower($this->merchantOf($order, $orders)) === mb_strtolower(trim($merchant))) {
                return $order;
            }
        }

        return null;
    }

    private function isDraft(int $order, Table $orders): bool
    {
        return $this->statusIs($order, $orders, 'status_draft');
    }

    private function statusIs(int $order, Table $orders, string $value): bool
    {
        $s = $this->structure;

        return $s->hasValue($value) && (int) $orders->first($order, $s->field('order_status')) === $s->value($value);
    }

    /** @return list<string> the lines of the order */
    private function requireDraftLine(int $order, int $purchase, Table $orders): array
    {
        if (! $orders->has($order) || ! $this->isDraft($order, $orders)) {
            throw StockError::notADraft($order);
        }

        $lines = $orders->all($order, $this->structure->field('order_lines'));

        if (! in_array((string) $purchase, $lines, true)) {
            throw StockError::notInOrder($purchase);
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function draftValues(string $label, string $merchant): array
    {
        $s      = $this->structure;
        $values = [
            (string) $s->field('label')        => $label,
            (string) $s->field('order_status') => (string) $s->value('status_draft'),
        ];

        if ($merchant !== '') {
            $values[(string) $s->field('order_merchant')] = $merchant;
        }

        return $values;
    }

    /** @return array<string, array<string, string>> a new money part, or the one the purchase holds overwritten */
    private function moneyPart(string $amount, bool $held): array
    {
        $s    = $this->structure;
        $part = [(string) $s->field('money_amount') => $amount];

        if ($s->hasValue('currency_default')) {
            $part[(string) $s->field('money_unit')] = (string) $s->value('currency_default');
        }

        // A non-numeric key adds a new part, «0» fills the one the record holds (taxmod/apply, set_values).
        return [$held ? '0' : 'neu' => $part];
    }

    private function start(): void
    {
        $this->changes = [];
    }

    private function ref(string $prefix): string
    {
        return $prefix . ++$this->counter;
    }
}
