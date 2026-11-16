<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Purchasing in three steps (D-1027): a demand without a supplier, a source per position, an order per supplier.
 *
 * A demand is an order «Entwurf» without «Händler»; a supplier's draft is an order «Entwurf» with one. Choosing a source
 * moves the purchase from the demand into its supplier's draft and lets it point back at the demand, so the demand stays
 * the bracket — all parts of a project, bought from several suppliers. Every step has its way back.
 *
 * D-1097, D-1098: when the order's «Positionen» is a composition in the model, a purchase is a part of its order — a new one
 * is written as a part of it, and choosing a source re-hangs it with `move_part`, keeping its id. While it is an aggregation,
 * the order's list of ids is rewritten as before ({@see Structure::composes()}).
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

    /**
     * D-1320 ergänzt, his word 2026-11-14 «wenn ein bedarf bestellt ist geht er in ein oder mehrere bestellungen über»: a demand
     * with no position of its own whose positions all went to suppliers has passed on. It stays as their bracket, unlisted.
     *
     * @param array<int, true> $brackets demands that purchases name as theirs, from {@see self::brackets()}
     */
    public function hasPassedOn(int $order, Table $orders, array $brackets): bool
    {
        return isset($brackets[$order]) && $this->isDemand($order, $orders) && $orders->all($order, $this->structure->field('order_lines')) === [];
    }

    /**
     * D-1320 ergänzt, his words 2026-11-15 «den bedarf auf beendet setzen und nach nem monat geht er nach archiviert»: what a
     * passed-on demand is now — «beendet», or «archiviert» once every order it went into is placed and the newest was placed
     * at least `$days` ago. Reckoned from the positions and the dates, never stored. '' for a demand still open.
     *
     * @param array<int, true>      $brackets  from {@see self::brackets()}
     * @param array<int, list<int>> $into      demand → the orders its positions stand in, from {@see self::passedInto()}
     * @param array<int, string>    $orderedOn order → the day it was placed, YYYY-MM-DD
     */
    public function endState(int $demand, Table $orders, array $brackets, array $into, array $orderedOn, string $today, int $days): string
    {
        if (! $this->hasPassedOn($demand, $orders, $brackets)) {
            return '';
        }

        $newest = '';

        foreach ($into[$demand] ?? [] as $order) {
            if ($this->isDraft($order, $orders) || ($orderedOn[$order] ?? '') === '') {
                return 'beendet';
            }

            $newest = max($newest, substr($orderedOn[$order], 0, 10));
        }

        return $newest !== '' && $newest <= date('Y-m-d', (int) strtotime($today . ' -' . max(1, $days) . ' days')) ? 'archiviert' : 'beendet';
    }

    /**
     * The orders the positions of each demand stand in now, in one pass over the orders.
     *
     * @return array<int, list<int>> demand → orders
     */
    public function passedInto(Table $orders, Table $purchases): array
    {
        $field = $this->structure->field('purchase_demand');
        $into  = [];

        foreach ($orders->ids() as $order) {
            foreach ($orders->all($order, $this->structure->field('order_lines')) as $line) {
                $demand = (int) $purchases->first((int) $line, $field);

                if ($demand > 0 && $demand !== $order && ! in_array($order, $into[$demand] ?? [], true)) {
                    $into[$demand][] = $order;
                }
            }
        }

        return $into;
    }

    /**
     * The demands some purchase names as its bracket, in one pass.
     *
     * @return array<int, true>
     */
    public function brackets(Table $purchases): array
    {
        $field = $this->structure->field('purchase_demand');
        $out   = [];

        foreach ($purchases->ids() as $id) {
            $demand = (int) $purchases->first($id, $field);

            if ($demand > 0) {
                $out[$demand] = true;
            }
        }

        return $out;
    }

    /** A supplier's draft: a draft with a supplier, not yet ordered. */
    public function isSupplierDraft(int $order, Table $orders): bool
    {
        return $this->isDraft($order, $orders) && $this->merchantOf($order, $orders) !== '';
    }

    public function isOrdered(int $order, Table $orders): bool
    {
        $s = $this->structure;

        // D-1038: shipped and delivered are still ordered — nothing of it is booked yet.
        return $this->statusIs($order, $orders, 'status_ordered') || $this->statusIs($order, $orders, 'status_shipped') || $this->statusIs($order, $orders, 'status_delivered');
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
            // His word 2026-11-14 «wenn ich in der Bestellung bin … eine Position hinzufügen»: a supplier's draft takes one as well.
            if (! $orders->has($demand) || ! ($this->isDemand($demand, $orders) || $this->isSupplierDraft($demand, $orders))) {
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

            $ref    = $this->ref('k');
            $values = [
                (string) $s->field('label')                 => trim($row['label']) !== '' ? trim($row['label']) : '#' . $thing,
                (string) $s->field('purchase_model')        => (string) $thing,
                (string) $s->field('purchase_quantity')     => (string) $quantity,
                (string) $s->field('purchase_undetermined') => '0',
            ];

            // D-1097: a part of the demand when «Positionen» is a composition, else a purchase of its own the demand lists.
            if ($s->composes('order_lines')) {
                $added[$ref] = $values;
                continue;
            }

            $added[]         = '@' . $ref;
            $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('purchase')];
            $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];
        }

        if ($added !== []) {
            $this->changes[] = ['op' => 'set_values', 'record' => $record, 'values' => [(string) $s->field('order_lines') => $s->composes('order_lines') ? $added : ['values' => [...$lines, ...$added]]]];
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

        // D-1098, his word «f2 wecheln erlauben»: the part is re-hung, its id stays — no list is rewritten.
        if ($s->composes('order_lines')) {
            $this->changes[] = $this->movePart($purchase, $target);

            return $this->changes;
        }

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

        $left      = array_values(array_diff($lines, [(string) $purchase]));
        $cancelled = $left === [] && $s->hasValue('status_cancelled') ? [(string) $s->field('order_status') => (string) $s->value('status_cancelled')] : [];
        $this->start();

        // D-1098: back into the demand by re-hanging the part; only a draft left empty still needs its status.
        if ($s->composes('order_lines')) {
            $this->changes[] = $this->movePart($purchase, (string) $demand);

            if ($cancelled !== []) {
                $this->changes[] = ['op' => 'set_values', 'record' => (string) $draft, 'values' => $cancelled];
            }

            return $this->changes;
        }

        $this->changes[] = ['op' => 'set_values', 'record' => (string) $demand, 'values' => [(string) $s->field('order_lines') => ['values' => [...$orders->all($demand, $s->field('order_lines')), (string) $purchase]]]];
        $this->changes[] = ['op' => 'set_values', 'record' => (string) $draft, 'values' => [(string) $s->field('order_lines') => ['values' => $left]] + $cancelled];

        return $this->changes;
    }

    /**
     * C5 (D-1320): positions of a demand moved together into another demand or a new one of this name; each keeps its
     * id (D-1098). A demand left empty stays, as an empty bracket he removes himself.
     *
     * @param list<int> $positions
     * @return list<array<string, mixed>>
     */
    public function moveLines(int $from, array $positions, ?int $to, string $newName, Table $orders): array
    {
        $s = $this->structure;

        if (! $orders->has($from) || ! $this->isDemand($from, $orders)) {
            throw StockError::notADemand($from);
        }

        $lines = $orders->all($from, $s->field('order_lines'));

        foreach ($positions as $position) {
            if (! in_array((string) $position, $lines, true)) {
                throw StockError::notInOrder($position);
            }
        }

        if ($positions === [] || $to === $from) {
            throw StockError::nothingToBook();
        }

        $this->start();

        if ($to !== null) {
            if (! $orders->has($to) || ! $this->isDemand($to, $orders)) {
                throw StockError::notADemand($to);
            }

            $target      = (string) $to;
            $targetLines = $orders->all($to, $s->field('order_lines'));
        } else {
            $newName = trim($newName);

            if ($newName === '') {
                throw StockError::demandWithoutName();
            }

            $target          = '@' . $this->ref('b');
            $targetLines     = [];
            $this->changes[] = ['op' => 'create_record', 'ref' => substr($target, 1), 'node' => $s->node('order')];
            $this->changes[] = ['op' => 'set_values', 'record' => $target, 'values' => $this->draftValues($newName, '')];
        }

        if ($s->composes('order_lines')) {
            foreach ($positions as $position) {
                $this->changes[] = $this->movePart($position, $target);
            }

            return $this->changes;
        }

        $moved           = array_map('strval', $positions);
        $this->changes[] = ['op' => 'set_values', 'record' => (string) $from, 'values' => [(string) $s->field('order_lines') => ['values' => array_values(array_diff($lines, $moved))]]];
        $this->changes[] = ['op' => 'set_values', 'record' => $target, 'values' => [(string) $s->field('order_lines') => ['values' => [...$targetLines, ...$moved]]]];

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

        // D-1097: removing a part takes it off its order's list as well.
        if ($s->composes('order_lines')) {
            return [['op' => 'remove_record', 'record' => (string) $purchase]];
        }

        return [
            ['op' => 'set_values', 'record' => (string) $order, 'values' => [(string) $s->field('order_lines') => ['values' => array_values(array_diff($lines, [(string) $purchase]))]]],
            ['op' => 'remove_record', 'record' => (string) $purchase],
        ];
    }

    /**
     * «Als bestellt markieren»: he has ordered at the supplier; the order gets its number and today's date. D-1405: when the
     * merchant is a platform the order also names it as «Plattform», so the mail import finds the order by platform and number.
     *
     * @return list<array<string, mixed>>
     */
    public function markOrdered(int $draft, string $number, Table $orders, bool $merchantIsPlatform = false): array
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

        $merchant = $this->merchantOf($draft, $orders);

        // D-1250: only an empty «Plattform» is filled.
        if ($merchantIsPlatform && $merchant !== '' && $s->hasField('order_platform') && trim((string) $orders->first($draft, $s->field('order_platform'))) === '') {
            $values[(string) $s->field('order_platform')] = $merchant;
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

        // Once on its way (D-1038) it is no draft again.
        if (! $orders->has($order) || ! $this->statusIs($order, $orders, 'status_ordered') || $this->merchantOf($order, $orders) === '') {
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

    /**
     * D-1098: the purchase re-hung onto another order's «Positionen» — appended there, its id unchanged.
     *
     * @return array<string, mixed>
     */
    private function movePart(int $purchase, string $order): array
    {
        return ['op' => 'move_part', 'part' => (string) $purchase, 'to' => $order, 'field' => (string) $this->structure->field('order_lines')];
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
