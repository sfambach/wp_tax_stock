<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Turns a stock count, a goods receipt or a consumption into one change list for the modeller.
 *
 * Every booking is one Vorgang with its Pos lines; a receipt or a count also creates the Bestandsposten the lines
 * name (D-956). A lot belongs to a thing of the tree, which is the material (D-995); a new thing is created in the
 * same list. The list is written in one call, all or nothing — the module never writes half a booking.
 *
 * ```mermaid
 * flowchart LR
 *   F["form"] --> B["Bookings: change list"] --> W["taxmod/write-data, one call"]
 *   B -.- V["(neues Ding) · Vorgang · Bestandsposten · Pos"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §3.5, §3.5a, §4
 */
final class Bookings
{
    /** @var list<array<string, mixed>> */
    private array $changes = [];

    /** @var list<string> refs of the Pos lines of the booking being built */
    private array $lineRefs = [];

    private int $counter = 0;

    public function __construct(private readonly Structure $structure, private readonly string $today)
    {
    }

    /**
     * A stock count: per row a lot without purchase and a line «+Menge, Gezählt = Menge» (concept §4, Inventur).
     *
     * @param list<InventoryRow> $rows
     * @return list<array<string, mixed>>
     */
    public function inventory(array $rows, string $title): array
    {
        $this->start();

        foreach ($rows as $row) {
            $quantity = Quantity::positive($row->quantity);
            $thing    = $row->new !== null ? '@' . $this->newThing($row->new) : (string) ($row->thing ?? throw StockError::nothingToBook());
            $value    = $row->value === null || $row->value === '' ? null : $this->moneyPart(Quantity::amount($row->value), null);
            $lot      = $this->lot($row->name, $thing, null, $value, $row->note);

            $this->line($row->name, $lot, $quantity, $quantity);
        }

        return $this->finish($title, 'kind_count', []);
    }

    /**
     * A goods receipt of chosen purchases of one order (D-956: partial receipts are allowed).
     *
     * Each purchase goes in as the thing chosen for it; a choice other than «keep» is also written into the
     * purchase's «Modell», in the same list (D-995). ⚠️ R1: a purchase that is «not yet determined» is refused.
     *
     * @param array<int, ReceiptChoice> $choices  purchase record id → its thing
     * @param array<int, true>          $received purchases some lot already names
     * @return list<array<string, mixed>>
     */
    public function receipt(int $order, array $choices, Table $orders, Table $purchases, Table $money, array $received, string $title): array
    {
        $s       = $this->structure;
        $inOrder = array_map('intval', $orders->all($order, $s->field('order_lines')));

        if ($choices === []) {
            throw StockError::nothingToBook();
        }

        $this->start();

        foreach ($choices as $purchase => $choice) {
            if (! in_array($purchase, $inOrder, true)) {
                throw StockError::notInOrder($purchase);
            }

            if (isset($received[$purchase])) {
                throw StockError::alreadyReceived($purchase);
            }

            if ($purchases->first($purchase, $s->field('purchase_undetermined')) === '1') {
                throw StockError::purchaseUndetermined($purchase);
            }

            $name      = (string) $purchases->first($purchase, $s->field('label'));
            $thing    = $this->thingFor($purchase, $choice, $purchases);
            $count    = $purchases->first($purchase, $s->field('purchase_quantity'));
            $quantity = $count === null || (int) $count < 1 ? '1' : (string) (int) $count;
            $lot      = $this->lot($name, $thing, (string) $purchase, $this->priceOf($purchase, $purchases, $money), '', $choice->location);

            $this->line($name, $lot, $quantity, null);
        }

        $all    = $inOrder !== [] && array_diff($inOrder, array_keys($choices), array_keys($received)) === [];
        $status = $s->value($all ? 'status_received' : 'status_partial');

        $this->changes[] = ['op' => 'set_values', 'record' => $order, 'values' => [(string) $s->field('order_status') => (string) $status]];

        return $this->finish($title, 'kind_receipt', [(string) $s->field('transaction_order') => (string) $order]);
    }

    /**
     * A lot purchase (Konvolut) told apart (concept R1, R3): the purchase itself becomes the first part, every further
     * part a purchase of its own in the same order. Each part names its material, so each can go into the goods
     * receipt. No booking yet — nothing is in stock until the receipt.
     *
     * ⚠️ Without prices the whole price stays on the first part (INFERRED). The landed price is not written: the model
     * calculates it from the unit price and the shares of shipping and fees (D-1022), so the shares are divided instead —
     * by the value of each part, by its quantity when no part has a price (INFERRED).
     *
     * @param list<SplitRow> $rows
     * @return list<array<string, mixed>>
     */
    public function split(int $order, int $purchase, array $rows, Table $orders, Table $purchases, Table $money): array
    {
        $s       = $this->structure;
        $inOrder = $orders->all($order, $s->field('order_lines'));

        if (! in_array((string) $purchase, $inOrder, true)) {
            throw StockError::notInOrder($purchase);
        }

        if ($rows === [] || $purchases->first($purchase, $s->field('purchase_undetermined')) !== '1') {
            throw StockError::notALot($purchase);
        }

        $this->start();
        $added  = [];
        $shares = $this->sharesOf($purchase, $rows, $purchases, $money);

        foreach ($rows as $index => $row) {
            $quantity = (string) (int) Quantity::positive($row->quantity);
            $thing    = $row->new !== null ? '@' . $this->newThing($row->new) : (string) ($row->thing ?? throw StockError::purchaseWithoutThing($purchase));
            $values   = [
                (string) $s->field('label')                 => trim($row->name),
                (string) $s->field('purchase_model')        => $thing,
                (string) $s->field('purchase_quantity')     => $quantity === '0' ? '1' : $quantity,
                (string) $s->field('purchase_undetermined') => '0',
            ];
            $first    = $index === 0;

            if ($row->unitPrice !== null && $row->unitPrice !== '') {
                $amount = Quantity::amount($row->unitPrice);
                $values[(string) $s->field('purchase_unit_price')] = $this->priceFor($first ? $purchases->first($purchase, $s->field('purchase_unit_price')) : null, $amount);
            }

            foreach ($shares as $key => $parts) {
                if ($parts[$index] !== null) {
                    $values[(string) $s->field($key)] = $parts[$index];
                }
            }

            if ($first) {
                $this->changes[] = ['op' => 'set_values', 'record' => $purchase, 'values' => $values];
                continue;
            }

            $ref             = $this->ref('k');
            $added[]         = '@' . $ref;
            $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('purchase')];
            $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];
        }

        if ($added !== []) {
            $this->changes[] = ['op' => 'set_values', 'record' => $order, 'values' => [(string) $s->field('order_lines') => ['values' => [...$inOrder, ...$added]]]];
        }

        return $this->changes;
    }

    /**
     * The material chosen for some purchases of an order, kept without a receipt — his word 2026-10-28: «zwischenstände
     * speichern also wenn ich was zugeordnet habe will ich das auch speichern können ohne we». Only the purchase's «Modell»
     * changes; nothing is booked.
     *
     * @param array<int, int> $things purchase id → the thing it is
     * @return list<array<string, mixed>>
     */
    public function assign(int $order, array $things, Table $orders, Table $purchases): array
    {
        $s       = $this->structure;
        $inOrder = array_map('intval', $orders->all($order, $s->field('order_lines')));
        $this->start();

        foreach ($things as $purchase => $thing) {
            if (! in_array($purchase, $inOrder, true)) {
                throw StockError::notInOrder($purchase);
            }

            if ((int) $purchases->first($purchase, $s->field('purchase_model')) !== $thing) {
                $this->changes[] = ['op' => 'set_values', 'record' => $purchase, 'values' => [(string) $s->field('purchase_model') => (string) $thing]];
            }
        }

        return $this->changes;
    }

    /**
     * The shares of shipping and fees (D-1022) of a lot purchase divided over its parts, as money parts per row: the first
     * part fills the part it holds and takes what rounding leaves over, so the sum stays the lot's.
     *
     * @param list<SplitRow> $rows
     * @return array<string, list<array<string, array<string, string>>|null>>
     */
    private function sharesOf(int $purchase, array $rows, Table $purchases, Table $money): array
    {
        $s       = $this->structure;
        $weights = $this->weightsOf($purchase, $rows, $purchases, $money);
        $total   = array_sum($weights);
        $out     = [];

        foreach (['purchase_shipping_share', 'purchase_fees_share'] as $key) {
            $held   = $s->hasField($key) ? $purchases->first($purchase, $s->field($key)) : null;
            $amount = $held === null ? null : $money->first((int) $held, $s->field('money_amount'));

            if ($amount === null || (float) $amount === 0.0 || count($rows) < 2) {
                continue;
            }

            $unit  = $money->first((int) $held, $s->field('money_unit'));
            $cents = (int) round((float) $amount * 100);
            $parts = array_map(static fn (float $w): int => (int) floor($cents * $w / $total), $weights);
            $parts[0] += $cents - array_sum($parts);
            $out[$key] = [];

            foreach ($parts as $index => $part) {
                $text = number_format($part / 100, 2, '.', '');
                $out[$key][] = $index === 0 ? ['0' => $this->moneyPart($text, $unit)['neu']] : ($part === 0 ? null : $this->moneyPart($text, $unit));
            }
        }

        return $out;
    }

    /**
     * What each part is worth: its price times its quantity; a first part without a price keeps the lot's. When no part
     * has a price, each counts by its quantity.
     *
     * @param list<SplitRow> $rows
     * @return list<float>
     */
    private function weightsOf(int $purchase, array $rows, Table $purchases, Table $money): array
    {
        $s       = $this->structure;
        $held    = $purchases->first($purchase, $s->field('purchase_unit_price'));
        $lotUnit = $held === null ? null : $money->first((int) $held, $s->field('money_amount'));
        $values  = [];

        foreach ($rows as $index => $row) {
            $quantity = (float) Quantity::positive($row->quantity);
            $price    = $row->unitPrice !== null && $row->unitPrice !== '' ? Quantity::amount($row->unitPrice) : ($index === 0 ? $lotUnit : null);
            $values[] = $price === null ? 0.0 : (float) $price * $quantity;
        }

        return array_sum($values) > 0.0 ? $values : array_map(static fn (SplitRow $r): float => (float) Quantity::positive($r->quantity), $rows);
    }

    /**
     * A price on a purchase: the money part it already holds is filled (key «0»), else a new one is added.
     *
     * @return array<string, array<string, string>>
     */
    private function priceFor(?string $held, string $amount): array
    {
        $part = $this->moneyPart($amount, null);

        return $held === null ? $part : ['0' => $part['neu']];
    }

    /**
     * A consumption: one line «−Menge» on the lot it is taken from.
     *
     * @return list<array<string, mixed>>
     */
    public function consumption(int $lot, string $quantity, ?int $project, string $title): array
    {
        $amount = Quantity::positive($quantity);

        $this->start();
        $this->line($title, (string) $lot, '-' . $amount, null);

        $extra = $project === null || ! $this->structure->hasField('transaction_project')
            ? []
            : [(string) $this->structure->field('transaction_project') => (string) $project];

        return $this->finish($title, 'kind_consumption', $extra);
    }

    private function start(): void
    {
        $this->changes  = [];
        $this->lineRefs = [];
    }

    /** The thing a purchase goes in as; a new one is created here, and the purchase is told its «Modell». */
    private function thingFor(int $purchase, ReceiptChoice $choice, Table $purchases): string
    {
        $s = $this->structure;

        if ($choice->keep) {
            $model = (int) $purchases->first($purchase, $s->field('purchase_model'));

            return $model > 0 ? (string) $model : throw StockError::purchaseWithoutThing($purchase);
        }

        $thing = match (true) {
            $choice->new !== null   => '@' . $this->newThing($choice->new),
            $choice->thing !== null => (string) $choice->thing,
            default                 => throw StockError::purchaseWithoutThing($purchase),
        };

        $this->changes[] = ['op' => 'set_values', 'record' => $purchase, 'values' => [(string) $s->field('purchase_model') => $thing]];

        return $thing;
    }

    /** D-995: a material that is not yet in the tree becomes a thing there, named and nothing more. */
    private function newThing(NewThing $new): string
    {
        $ref = $this->ref('d');

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $new->node];
        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => [(string) $this->structure->field('label') => trim($new->name)]];

        return $ref;
    }

    /** @param array<string, array<string, string>>|null $value */
    private function lot(string $name, string $thing, ?string $purchase, ?array $value, string $note, ?int $location = null): string
    {
        $s   = $this->structure;
        $ref = $this->ref('l');

        $values = [
            (string) $s->field('label')        => $name,
            (string) $s->field('lot_thing')    => $thing,
            (string) $s->field('lot_location') => (string) ($location ?? $s->value('location_default')),
            (string) $s->field('lot_date')     => $this->today,
        ];

        if ($purchase !== null) {
            $values[(string) $s->field('lot_purchase')] = $purchase;
        }

        if ($value !== null) {
            $values[(string) $s->field('lot_value')] = $value;
        }

        if ($note !== '' && $s->hasField('description')) {
            $values[(string) $s->field('description')] = $note;
        }

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('lot')];
        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];

        return '@' . $ref;
    }

    private function line(string $name, string $lot, string $quantity, ?string $counted): void
    {
        $s   = $this->structure;
        $ref = $this->ref('p');

        $values = [
            (string) $s->field('label')         => $name,
            (string) $s->field('line_lot')      => $lot,
            (string) $s->field('line_quantity') => $quantity,
        ];

        if ($counted !== null) {
            $values[(string) $s->field('line_counted')] = $counted;
        }

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('line')];
        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];
        $this->lineRefs[] = '@' . $ref;
    }

    /**
     * The Vorgang itself, last: its «Positionen» name the lines created above.
     *
     * @param array<string, string> $extra
     * @return list<array<string, mixed>>
     */
    private function finish(string $title, string $kind, array $extra): array
    {
        $s   = $this->structure;
        $ref = $this->ref('v');

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('transaction')];
        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => [
            (string) $s->field('label')              => $title,
            (string) $s->field('transaction_kind')   => (string) $s->value($kind),
            (string) $s->field('transaction_date')   => $this->today,
            (string) $s->field('transaction_source') => (string) $s->value('source_manual'),
            (string) $s->field('transaction_lines')  => ['values' => $this->lineRefs],
        ] + $extra];

        return $this->changes;
    }

    /**
     * The purchase's price per piece as a new money part — landed price if it has one, else the price paid.
     *
     * @return array<string, array<string, string>>|null
     */
    private function priceOf(int $purchase, Table $purchases, Table $money): ?array
    {
        $s    = $this->structure;
        $part = $purchases->first($purchase, $s->field('purchase_landed_price'))
            ?? $purchases->first($purchase, $s->field('purchase_unit_price'));

        $amount = $part === null ? null : $money->first((int) $part, $s->field('money_amount'));

        return $amount === null ? null : $this->moneyPart($amount, $money->first((int) $part, $s->field('money_unit')));
    }

    /** @return array<string, array<string, string>> */
    private function moneyPart(string $amount, ?string $unit): array
    {
        $s    = $this->structure;
        $unit ??= $s->hasValue('currency_default') ? (string) $s->value('currency_default') : null;

        $part = [(string) $s->field('money_amount') => $amount];

        if ($unit !== null) {
            $part[(string) $s->field('money_unit')] = $unit;
        }

        // A non-numeric key adds a new part (taxmod/apply, set_values).
        return ['neu' => $part];
    }

    private function ref(string $prefix): string
    {
        return $prefix . ++$this->counter;
    }
}
