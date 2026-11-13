<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Turns a stock count, a goods receipt, a consumption or an Umbuchung into one change list for the modeller.
 *
 * Every booking is one Vorgang with its Pos lines; a receipt or a count also creates the Bestandsposten the lines
 * name (D-956). A lot belongs to a thing of the tree, which is the material (D-995); a new thing is created in the
 * same list. The list is written in one call, all or nothing — the module never writes half a booking.
 *
 * D-1097: when «Positionen» is a composition in the model, the lines are parts of their Vorgang — written inside its
 * `set_values` under part keys, not created on their own; while it is an aggregation they stay records of their own whose
 * ids the Vorgang lists. The kind is the model's ({@see Structure::composes()}), so both hold until the switch.
 *
 * ```mermaid
 * flowchart LR
 *   F["form"] --> B["Bookings: change list"] --> W["taxmod/write-data, one call"]
 *   B -.- V["(neues Ding) · Bestandsposten · Vorgang mit Pos"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §3.5, §3.5a, §4
 */
final class Bookings
{
    /** @var list<array<string, mixed>> */
    private array $changes = [];

    /** @var list<string> refs of the Pos lines of the booking being built, while «Positionen» is an aggregation */
    private array $lineRefs = [];

    /** @var array<string, array<string, string>> D-1097: the Pos lines as parts of the Vorgang, part key → values, while «Positionen» is a composition */
    private array $lineParts = [];

    private int $counter = 0;

    /** @var array<int, string> D-1264: purchase → the thing it went in as (an id, or the ref of a new one) in the last booking built */
    private array $chosen = [];

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
            $value    = $this->countedValue($row, $quantity);
            $lot      = $this->lot($row->name, $thing, null, $value, $row->note);

            $this->line($row->name, $lot, $quantity, $quantity, $this->homeOf($lot));
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
     * @param array<int, Pack>          $packs    D-1178: thing → its pack; a purchase of such a thing counts packs
     * @return list<array<string, mixed>>
     */
    public function receipt(int $order, array $choices, Table $orders, Table $purchases, Table $money, array $received, string $title, array $packs = []): array
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
            $price    = $this->priceOf($purchase, $purchases, $money);

            // D-1178: «Der Wareneingang bucht ganze Leisten» — packs bought, pieces in stock, the price per piece.
            $pack = $packs[(int) $thing] ?? null;

            if ($pack !== null && ctype_digit($thing)) {
                $quantity = (string) ((int) $quantity * $pack->content);
                $part     = $price === null ? null : (array) array_values($price)[0];
                $amount   = $part === null ? null : ($part[(string) $s->field('money_amount')] ?? null);

                if ($part !== null && is_numeric($amount)) {
                    $price = ['neu' => [(string) $s->field('money_amount') => self::signed((float) $amount / $pack->content)] + $part];
                }
            }

            $lot = $this->lot($name, $thing, (string) $purchase, $price, '', $choice->location);

            $this->line($name, $lot, $quantity, null, $this->homeOf($lot));
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

            $ref = $this->ref('k');

            // D-1097: a part of the order when «Positionen» is a composition, else a purchase of its own the order lists.
            if ($s->composes('order_lines')) {
                $added[$ref] = $values;
                continue;
            }

            $added[]         = '@' . $ref;
            $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('purchase')];
            $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];
        }

        if ($added !== []) {
            $this->changes[] = ['op' => 'set_values', 'record' => $order, 'values' => [(string) $s->field('order_lines') => $s->composes('order_lines') ? $added : ['values' => [...$inOrder, ...$added]]]];
        }

        return $this->changes;
    }

    /**
     * The material chosen for some purchases of an order, kept without a receipt — his word 2026-10-28: «zwischenstände
     * speichern also wenn ich was zugeordnet habe will ich das auch speichern können ohne we». Only the purchase's «Modell»
     * changes; nothing is booked.
     *
     * @param array<int, int|NewThing> $things purchase id → the thing it is (0: none any more), or a new one in a group (D-1062)
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

            if ($thing instanceof NewThing) {
                $this->chosen[$purchase] = '@' . $this->newThing($thing);
                $this->changes[] = ['op' => 'set_values', 'record' => $purchase, 'values' => [(string) $s->field('purchase_model') => $this->chosen[$purchase]]];
                continue;
            }

            if ($thing > 0) {
                $this->chosen[$purchase] = (string) $thing;
            }

            // 0 is a material taken away with «Zurücksetzen»: the purchase forgets its «Modell» (his report 2026-11-01).
            if ((int) $purchases->first($purchase, $s->field('purchase_model')) !== $thing) {
                $this->changes[] = ['op' => 'set_values', 'record' => $purchase, 'values' => [(string) $s->field('purchase_model') => $thing === 0 ? '' : (string) $thing]];
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
     * A consumption: one line «−Menge» on the lot it is taken from, at the place it is taken from (D-1039) when the
     * lines carry a place. D-1088: the Vorgang names its purpose in its Bezug — before the model has that field, in its
     * project — and its reason («Grund», e.g. «Reparatur») in its description. «Für Produktion» names no record; the
     * place it is taken from says enough.
     *
     * @return list<array<string, mixed>>
     */
    public function consumption(int $lot, string $quantity, Purpose $purpose, string $title, ?int $place = null, string $reason = '', int $art = 0): array
    {
        $s      = $this->structure;
        $amount = Quantity::positive($quantity);

        $this->start();
        $this->line($title, (string) $lot, '-' . $amount, null, $place);

        $field = $s->hasField('transaction_reference') ? 'transaction_reference' : 'transaction_project';
        $extra = $purpose->subject > 0 && $s->hasField($field) ? [(string) $s->field($field) => (string) $purpose->subject] : [];

        if (trim($reason) !== '' && $s->hasField('description')) {
            $extra[(string) $s->field('description')] = trim($reason);
        }

        // D-1297: a kind below «Verbrauch», where one is chosen; else «Verbrauch» itself, as before.
        return $this->finish($title, $art > 0 && $s->isConsumption($art) ? $art : 'kind_consumption', $extra);
    }

    /**
     * D-1146: what a build takes from its box and gives back, one Vorgang «Verbrauch» with the order as its Bezug.
     *
     * @param list<array{lot: int, place: int, quantity: float}> $rows signed: negative is taken
     * @return list<array<string, mixed>>
     */
    public function build(array $rows, int $reference, string $title, int $art = 0, string $reason = ''): array
    {
        $s = $this->structure;

        if (! $s->hasField('transaction_reference')) {
            throw StockError::notConfigured('fields.transaction_reference');
        }

        $this->start();

        foreach ($rows as $row) {
            $this->line($title, (string) $row['lot'], self::signed($row['quantity']), null, $row['place']);
        }

        $extra = [(string) $s->field('transaction_reference') => (string) $reference];

        if (trim($reason) !== '' && $s->hasField('description')) {
            $extra[(string) $s->field('description')] = trim($reason);
        }

        // D-1302: scrap is the kind «Defekt/Ausschuss» below «Verbrauch» (D-1297); a build stays «Verbrauch».
        return $this->finish($title, $art > 0 && $s->isConsumption($art) ? $art : 'kind_consumption', $extra);
    }

    /**
     * D-1328 (D9): strips cut for a build order in one Vorgang «Verbrauch» whose Bezug is the order — the long strips
     * leave their place, the pieces come into the box as new lots, what is left of a strip stays at its place as a lot
     * of the shorter strip. `$prelude` creates rest materials first; their refs stand in `rests[].thing`.
     *
     * @param list<array{lot: int, place: int, take: int, thing: int, pieces: int, into: int, name: string, rests: list<array{thing: string, quantity: int, name: string}>}> $rows
     * @param list<array<string, mixed>> $prelude
     * @return list<array<string, mixed>>
     */
    public function cut(array $rows, int $reference, string $title, array $prelude = []): array
    {
        $s = $this->structure;

        if (! $s->hasField('transaction_reference')) {
            throw StockError::notConfigured('fields.transaction_reference');
        }

        $this->start();
        $this->changes = $prelude;

        foreach ($rows as $row) {
            $this->line($title, (string) $row['lot'], self::signed(-$row['take']), null, $row['place']);

            $pieces = $this->lot($row['name'], (string) $row['thing'], null, null, '', $row['into']);
            $this->line($row['name'], $pieces, self::signed($row['pieces']), null, $row['into']);

            foreach ($row['rests'] as $rest) {
                $lot = $this->lot($rest['name'], $rest['thing'], null, null, '', $row['place']);
                $this->line($rest['name'], $lot, self::signed($rest['quantity']), null, $row['place']);
            }
        }

        return $this->finish($title, 'kind_consumption', [(string) $s->field('transaction_reference') => (string) $reference]);
    }

    /**
     * D-1143: the finished things of a build order as one Vorgang «Zugang» with the order as its Bezug — new lots at the
     * place, and cancelled pieces taken back from this order's lots.
     *
     * ⚠️ *INFERRED: the kind is the goods receipt without a purchase, like a count; a kind «Fertigung» of its own is not in
     * the model.*
     *
     * @param list<array{thing: int, quantity: float, name: string}> $new
     * @param list<array{lot: int, place: int, quantity: float}>     $back
     * @return list<array<string, mixed>>
     */
    public function output(array $new, array $back, int $place, int $reference, string $title, bool $tested = false, int $stage = 0): array
    {
        $s = $this->structure;

        if (! $s->hasField('transaction_reference')) {
            throw StockError::notConfigured('fields.transaction_reference');
        }

        $this->start();

        // D-1143: a finished board comes in untested; the test after the receipt (D-1002) frees it.
        // D-1177, his word «prüfung ist pflicht»: one tested in the manufacturing comes in as «funktioniert», tested today.
        $condition = $tested ? 'test_ok' : 'condition_untested';
        $untested  = $s->hasField('lot_condition') && $s->hasValue($condition);

        foreach ($new as $row) {
            $lot = $this->lot($row['name'], (string) $row['thing'], null, null, '', $place);

            if ($untested) {
                $this->changes[] = ['op' => 'set_values', 'record' => $lot, 'values' => [(string) $s->field('lot_condition') => (string) $s->value($condition)]
                    + ($tested && $s->hasField('lot_tested_on') ? [(string) $s->field('lot_tested_on') => $this->today] : [])];
            }

            // D-1303: a finished board starts its stage on the production's receipt line in the same act.
            if ($stage > 0 && $s->hasField('lot_stage')) {
                $this->changes[] = ['op' => 'set_values', 'record' => $lot, 'values' => [(string) $s->field('lot_stage') => (string) $stage]];
            }

            $this->line($row['name'], $lot, self::signed($row['quantity']), null, $place);
        }

        foreach ($back as $row) {
            $this->line($title, (string) $row['lot'], self::signed(-$row['quantity']), null, $row['place']);
        }

        return $this->finish($title, 'kind_receipt', [(string) $s->field('transaction_reference') => (string) $reference]);
    }

    /**
     * D-1260: the goods of a sale leave in one Vorgang «Verkauf» whose Bezug is the sale.
     *
     * @param list<array{lot: int, place: int|null, quantity: string}> $rows
     * @return list<array<string, mixed>>
     */
    public function sale(array $rows, int $sale, string $title): array
    {
        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        $this->start();

        foreach ($rows as $row) {
            $this->line($title, (string) $row['lot'], '-' . Quantity::positive($row['quantity']), null, $row['place']);
        }

        return $this->saleDeal($title, $sale);
    }

    /**
     * D-1260, an old sale nobody booked stock for: a new lot comes in as «Nacherfassung» on the day of the sale and leaves
     * at once as «Verkauf» — two Vorgänge in one act, the stock of today unchanged, the history complete.
     *
     * @return list<array<string, mixed>>
     */
    public function backfill(string $name, int $thing, string $quantity, ?string $value, int $place, int $sale, string $title): array
    {
        $s      = $this->structure;
        $amount = Quantity::positive($quantity);

        $this->start();
        $lot = $this->lot($name, (string) $thing, null, $value === null ? null : $this->moneyPart(Quantity::amount($value), null), '', $place);
        $this->line($name, $lot, $amount, null, $place);
        $this->finish($name, 'kind_receipt', [(string) $s->field('transaction_reference') => (string) $sale]);

        $this->lineRefs  = [];
        $this->lineParts = [];
        $this->line($title, $lot, '-' . $amount, null, $place);

        return $this->saleDeal($title, $sale);
    }

    /** @return list<array<string, mixed>> */
    private function saleDeal(string $title, int $sale): array
    {
        $s = $this->structure;

        if (! $s->hasValue('kind_sale')) {
            throw StockError::notConfigured('values.kind_sale');
        }

        if (! $s->hasField('transaction_reference')) {
            throw StockError::notConfigured('fields.transaction_reference');
        }

        return $this->finish($title, 'kind_sale', [(string) $s->field('transaction_reference') => (string) $sale]);
    }

    /**
     * D-1036 for a consumption: a Vorgang «Verbrauch» whose lines put back what each line took, at its place, naming
     * the line it cancels. A reservation the consumption lowered is open again by as much (D-1088).
     *
     * @param list<array{lot: int, place: int|null, quantity: string, reverses: int}> $rows
     * @return list<array<string, mixed>>
     */
    public function cancelConsumption(array $rows, string $title, string $note): array
    {
        return $this->reversal($rows, $title, $note, 'kind_consumption');
    }

    /**
     * D-1036 for a count: a Vorgang «Inventur» whose lines take out what each counted line put in, naming the line it
     * cancels. The lot stays, at zero.
     *
     * @param list<array{lot: int, place: int|null, quantity: string, reverses: int}> $rows
     * @return list<array<string, mixed>>
     */
    public function cancelCount(array $rows, string $title, string $note): array
    {
        return $this->reversal($rows, $title, $note, 'kind_count');
    }

    /**
     * A Storno Vorgang of `$kind`: per row one line naming the line it cancels (D-1036).
     *
     * @param list<array{lot: int, place: int|null, quantity: string, reverses: int}> $rows
     * @return list<array<string, mixed>>
     */
    private function reversal(array $rows, string $title, string $note, string $kind): array
    {
        $s = $this->structure;

        if (! $s->hasField('line_reverses')) {
            throw StockError::notConfigured('fields.line_reverses');
        }

        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        $this->start();

        foreach ($rows as $row) {
            $this->line($title, (string) $row['lot'], $row['quantity'], null, $row['place'], $row['reverses']);
        }

        $extra = $note !== '' && $s->hasField('description') ? [(string) $s->field('description') => $note] : [];

        return $this->finish($title, $kind, $extra);
    }

    /**
     * An Umbuchung (D-1039): one Vorgang, per row a line «−Menge» at the old place and «+Menge» at the new one — also
     * when a whole lot moves. The lot keeps its «Lagerort» (where it came in); where it lies now is the sum of its lines.
     * A Storno (D-1036) is the same with the places swapped and each line naming the line it cancels.
     *
     * D-1082: with `$reserved`, nothing reserved goes into a reserved area; `$bound` are the lots a reservation names.
     * D-1146: a pick for a build order names the order as its Bezug (`$reference`).
     *
     * @param list<TransferRow> $rows
     * @param array<int, true>  $bound
     * @return list<array<string, mixed>>
     */
    public function transfer(array $rows, string $title, Ledger $ledger, string $note = '', ?ReservedPlaces $reserved = null, array $bound = [], int $reference = 0): array
    {
        $s = $this->structure;

        if (! $s->hasField('line_location')) {
            throw StockError::notConfigured('fields.line_location');
        }

        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        $this->start();

        foreach ($rows as $row) {
            $quantity = Quantity::positive($row->quantity);

            if ($row->from === $row->to) {
                throw StockError::samePlace($row->lot);
            }

            if ((float) $quantity > $ledger->restAt($row->lot, $row->from) + 0.0000001) {
                throw StockError::notEnoughAt($row->lot, $row->from);
            }

            $reserved?->checkTransfer($row->lot, $row->from, $row->to, isset($bound[$row->lot]));

            $this->line($title, (string) $row->lot, '-' . $quantity, null, $row->from, $row->reversesOut);
            $this->line($title, (string) $row->lot, $quantity, null, $row->to, $row->reversesIn);
        }

        $extra = $note !== '' && $s->hasField('description') ? [(string) $s->field('description') => $note] : [];

        if ($reference > 0 && $s->hasField('transaction_reference')) {
            $extra[(string) $s->field('transaction_reference')] = (string) $reference;
        }

        return $this->finish($title, 'kind_transfer', $extra);
    }

    /**
     * D-1091: a box (a movable place) hung elsewhere is a Vorgang «Umlagerung» — the box in its own field and two positions
     * without a lot (− one at the old place, + one at the new), so from and to stay readable; the stock counts only
     * positions with a lot, so nothing in the box is rebooked. A place of 0 is the top level, written as a position
     * without a place.
     *
     * @return list<array<string, mixed>>
     */
    public function relocate(int $box, int $from, int $to, string $title): array
    {
        $s = $this->structure;

        if (! $s->hasValue('kind_relocation') || ! $s->hasField('transaction_carrier') || ! $s->hasField('line_location')) {
            throw StockError::notConfigured('values.kind_relocation, fields.transaction_carrier');
        }

        if ($from === $to) {
            throw StockError::samePlace($box);
        }

        $this->start();
        $this->line($title, '', '-1', null, $from);
        $this->line($title, '', '1', null, $to);

        return $this->finish($title, 'kind_relocation', [(string) $s->field('transaction_carrier') => (string) $box]);
    }

    /**
     * D-1083: positions of a subject's reservation. The first make the Vorgang «Reservierung» with its subject, date and
     * deadline; later ones join the open reservation of the same subject (one per subject). Checks of the quantities are
     * {@see Reservations::check()}'s.
     *
     * @param list<ReservationRow> $rows
     * @param int|null             $open the subject's open reservation
     * @return list<array<string, mixed>>
     */
    public function reserve(int $subject, string $deadline, array $rows, ?int $open, Table $transactions, string $title): array
    {
        $s = $this->structure;

        foreach (['transaction_reference', 'line_thing'] as $key) {
            if (! $s->hasField($key)) {
                throw StockError::notConfigured('fields.' . $key);
            }
        }

        if ($subject <= 0) {
            throw StockError::noSubject();
        }

        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        $this->start();

        foreach ($rows as $row) {
            $this->line($title, $row->lot === null ? '' : (string) $row->lot, Quantity::positive($row->quantity), null, null, null, $row->thing, $row->position);
        }

        $deadline = $this->deadline($deadline);

        if ($open === null) {
            return $this->finish($title, 'kind_reservation', [(string) $s->field('transaction_reference') => (string) $subject] + $deadline);
        }

        // D-1097: new parts join the parts the reservation holds; an aggregation is written whole, the old ids first.
        $this->changes[] = ['op' => 'set_values', 'record' => (string) $open, 'values' => [
            (string) $s->field('transaction_lines') => $s->composes('transaction_lines')
                ? $this->lineParts
                : ['values' => [...$transactions->all($open, $s->field('transaction_lines')), ...$this->lineRefs]],
        ] + $deadline];

        return $this->changes;
    }

    /**
     * D-1036 for a reservation: a Vorgang «Reservierung» whose lines take back what is left of the positions, each naming
     * the position it cancels.
     *
     * @param array<int, array{thing: int, lot: int|null, quantity: float}> $positions line → what to cancel
     * @return list<array<string, mixed>>
     */
    public function cancelReservation(int $subject, array $positions, string $title, string $note): array
    {
        $s = $this->structure;

        if (! $s->hasField('line_reverses')) {
            throw StockError::notConfigured('fields.line_reverses');
        }

        $this->start();

        foreach ($positions as $line => $position) {
            $this->line($title, $position['lot'] === null ? '' : (string) $position['lot'], '-' . rtrim(rtrim(number_format($position['quantity'], 6, '.', ''), '0'), '.'), null, null, $line, $position['thing']);
        }

        $extra = ($subject > 0 && $s->hasField('transaction_reference') ? [(string) $s->field('transaction_reference') => (string) $subject] : [])
            + ($note !== '' && $s->hasField('description') ? [(string) $s->field('description') => $note] : []);

        return $this->finish($title, 'kind_reservation', $extra);
    }

    /** @return array<string, string> the deadline's value, when one is given and the field is there */
    private function deadline(string $deadline): array
    {
        $deadline = trim($deadline);

        if ($deadline === '' || ! $this->structure->hasField('transaction_deadline')) {
            return [];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) !== 1) {
            throw StockError::notADate($deadline);
        }

        return [(string) $this->structure->field('transaction_deadline') => $deadline];
    }

    /**
     * D-1264: purchase → the thing it was booked or assigned as in the last booking built — an id, or the ref of a thing
     * made in the same act.
     *
     * @return array<int, string>
     */
    public function chosenThings(): array
    {
        return $this->chosen;
    }

    private function start(): void
    {
        $this->chosen    = [];
        $this->changes   = [];
        $this->lineRefs  = [];
        $this->lineParts = [];
    }

    /** The thing a purchase goes in as; a new one is created here, and the purchase is told its «Modell». */
    private function thingFor(int $purchase, ReceiptChoice $choice, Table $purchases): string
    {
        $s = $this->structure;

        if ($choice->keep) {
            $model = (int) $purchases->first($purchase, $s->field('purchase_model'));

            return $model > 0 ? $this->chosen[$purchase] = (string) $model : throw StockError::purchaseWithoutThing($purchase);
        }

        $thing = match (true) {
            $choice->new !== null   => '@' . $this->newThing($choice->new),
            $choice->thing !== null => (string) $choice->thing,
            default                 => throw StockError::purchaseWithoutThing($purchase),
        };

        $this->changes[] = ['op' => 'set_values', 'record' => $purchase, 'values' => [(string) $s->field('purchase_model') => $thing]];
        $this->chosen[$purchase] = $thing;

        return $thing;
    }

    /** D-995: a material that is not yet in the tree becomes a thing there, named; D-1071: with what its purchase tells about its Lager view. */
    private function newThing(NewThing $new): string
    {
        $ref = $this->ref('d');

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $new->node];
        $values = [(string) $this->structure->field('label') => trim($new->name)];

        foreach ($new->values as $relation => $value) {
            $values[(string) $relation] = $value;
        }

        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];

        if ($new->lager !== null) {
            array_push($this->changes, ...(new LagerChanges($this->structure))->save('@' . $ref, null, $new->lager, $this->ref('l')));
        }

        return $ref;
    }

    /** @param array<string, array<string, string>>|null $value */
    /**
     * Where each lot of this booking came in. D-1233, the report 2026-11-08 (HD15, SMD parts): an Umbuchung and then a changed «Lagerort»
     * of the lot counted it twice at the new place — the first line had no place of its own and followed the lot's field. So the
     * first line names its place too; the lot's field no longer moves anything booked.
     *
     * @var array<string, int>
     */
    private array $homes = [];

    private function homeOf(string $lot): ?int
    {
        return $this->homes[$lot] ?? null;
    }

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
        $this->homes['@' . $ref] = (int) $values[(string) $s->field('lot_location')];

        return '@' . $ref;
    }

    private function line(string $name, string $lot, string $quantity, ?string $counted, ?int $place = null, ?int $reverses = null, ?int $thing = null, ?int $position = null): void
    {
        $s   = $this->structure;
        $ref = $this->ref('p');

        $values = [(string) $s->field('label') => $name];

        // D-1083: a reservation position may name no lot, only its material.
        if ($lot !== '') {
            $values[(string) $s->field('line_lot')] = $lot;
        }

        $values[(string) $s->field('line_quantity')] = $quantity;

        if ($thing !== null) {
            $values[(string) $s->field('line_thing')] = (string) $thing;
        }

        if ($counted !== null) {
            $values[(string) $s->field('line_counted')] = $counted;
        }

        if ($place !== null && $place > 0 && $s->hasField('line_location')) {
            $values[(string) $s->field('line_location')] = (string) $place;
        }

        if ($reverses !== null) {
            $values[(string) $s->field('line_reverses')] = (string) $reverses;
        }

        if ($position !== null && $position > 0 && $s->hasField('line_position')) {
            $values[(string) $s->field('line_position')] = (string) $position;
        }

        // D-1097: a part has no ref of its own — nothing later in the list names a line, so none is needed.
        if ($s->composes('transaction_lines')) {
            $this->lineParts[$ref] = $values;

            return;
        }

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('line')];
        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];
        $this->lineRefs[] = '@' . $ref;
    }

    /**
     * The Vorgang itself, last: its «Positionen» name the lines created above — or, as a composition, hold them as parts.
     *
     * @param array<string, string> $extra
     * @return list<array<string, mixed>>
     */
    private function finish(string $title, string|int $kind, array $extra): array
    {
        $s   = $this->structure;
        $ref = $this->ref('v');

        $this->changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('transaction')];
        $this->changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => [
            (string) $s->field('label')              => $title,
            (string) $s->field('transaction_kind')   => (string) (is_int($kind) ? $kind : $s->value($kind)),
            (string) $s->field('transaction_date')   => $this->today,
            (string) $s->field('transaction_source') => (string) $s->value('source_manual'),
            (string) $s->field('transaction_lines')  => $s->composes('transaction_lines') ? $this->lineParts : ['values' => $this->lineRefs],
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
    /**
     * The value per piece of a counted row: as given, else the total over the counted quantity; neither is no value.
     *
     * @return array<string, array<string, string>>|null
     */
    private function countedValue(InventoryRow $row, string $quantity): ?array
    {
        if ($row->value !== null && $row->value !== '') {
            return $this->moneyPart(Quantity::amount($row->value), null);
        }

        if ($row->total === null || $row->total === '') {
            return null;
        }

        return $this->moneyPart(self::signed((float) Quantity::amount($row->total) / (float) $quantity), null);
    }

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

    private static function signed(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }

    private function ref(string $prefix): string
    {
        return $prefix . ++$this->counter;
    }
}
