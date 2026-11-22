<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The reservations, read from the Vorgänge of kind «Reservierung» (D-1083): one per subject — a project or a sale; build
 * runs and repairs are no Bezug (D-1088) — with a date, a deadline 0..1 and positions (material, quantity, lot 0..1). A
 * reservation lowers the available stock, not the stock. Taken back as a Storno (D-1036), whole or per position: the
 * Storno's lines name the positions they cancel, and a position counts with what is left of it.
 *
 * D-1088: a consumption for the subject lowers its open reservation — computed, nothing stored twice: what is open is
 * reserved − consumed for that Bezug. A consumed lot first lowers the positions naming it, then those of its material
 * without a lot.
 *
 * The reservation of a reserved place (D-1082) is not stored here: its positions are computed from what lies there
 * ({@see ReservedPlaces::holdings()}).
 *
 * ```mermaid
 * flowchart LR
 *   V["Vorgang «Reservierung»: Bezug, Datum, Frist"] --> P["Pos: Material, Menge, Posten 0..1"]
 *   S["Storno: Pos −Menge, Storno von"] -.-> P
 *   C["Verbrauch mit diesem Bezug"] -.->|mindert| P
 *   P --> A["verfügbar = Bestand − reserviert"]
 * ```
 */
final class Reservations
{
    private const NOTHING = 0.0000001;

    /**
     * @param array<int, array{label: string, subject: int, date: string, deadline: string, positions: array<int, array{thing: int, lot: int|null, quantity: float}>}> $heads
     *        reservation id → its head and its positions (line id → what is still reserved of it)
     */
    private function __construct(private readonly array $heads)
    {
    }

    public static function from(Structure $s, Table $transactions, Table $lines, Ledger $ledger): self
    {
        if (! $s->hasValue('kind_reservation')) {
            return new self([]);
        }

        $field     = static fn (string $key): ?int => $s->hasField($key) ? $s->field($key) : null;
        $reverses  = $field('line_reverses');
        $project   = $field('transaction_project');
        $paired    = (new Storno($s))->pairedLines($lines);
        $consumed  = [];
        $thingOf   = $field('line_thing');
        $subject   = $field('transaction_reference');
        $deadline  = $field('transaction_deadline');
        $cancelled = [];
        $deals     = [];

        foreach ($transactions->ids() as $deal) {
            $kind = (int) $transactions->first($deal, $s->field('transaction_kind'));

            // D-1088: what was consumed for a subject, a Storno and what it cancelled left out.
            if (in_array($kind, $s->consumptionKinds(), true)) {
                $for = self::purposeOf($deal, $transactions, $subject, $project);

                foreach ($for > 0 ? $transactions->all($deal, $s->field('transaction_lines')) : [] as $line) {
                    $lot = (int) $lines->first((int) $line, $s->field('line_lot'));

                    if ($lot > 0 && ! isset($paired[(int) $line])) {
                        $consumed[$for][$lot] = ($consumed[$for][$lot] ?? 0.0) - (float) $lines->first((int) $line, $s->field('line_quantity'));
                    }
                }

                continue;
            }

            if ($kind !== $s->value('kind_reservation')) {
                continue;
            }

            $own = [];

            foreach ($transactions->all($deal, $s->field('transaction_lines')) as $line) {
                $line     = (int) $line;
                $original = $reverses === null ? 0 : (int) $lines->first($line, $reverses);

                if ($original > 0) {
                    $cancelled[$original] = ($cancelled[$original] ?? 0.0) + (float) $lines->first($line, $s->field('line_quantity'));
                } else {
                    $own[] = $line;
                }
            }

            // A Vorgang made only of Storno lines is a Storno, not a reservation.
            if ($own !== []) {
                $deals[$deal] = $own;
            }
        }

        $heads = [];

        foreach ($deals as $deal => $own) {
            $positions = [];

            foreach ($own as $line) {
                $lot      = (int) $lines->first($line, $s->field('line_lot'));
                $thing    = $thingOf === null ? 0 : (int) $lines->first($line, $thingOf);
                $quantity = (float) $lines->first($line, $s->field('line_quantity')) + ($cancelled[$line] ?? 0.0);

                if ($quantity > self::NOTHING) {
                    $positions[$line] = ['thing' => $thing > 0 ? $thing : (int) $ledger->thingOf($lot), 'lot' => $lot > 0 ? $lot : null, 'quantity' => $quantity];
                }
            }

            $heads[$deal] = [
                'label'     => (string) $transactions->first($deal, $s->field('label')),
                'subject'   => $subject === null ? 0 : (int) $transactions->first($deal, $subject),
                'date'      => substr((string) $transactions->first($deal, $s->field('transaction_date')), 0, 10),
                'deadline'  => $deadline === null ? '' : substr((string) $transactions->first($deal, $deadline), 0, 10),
                'positions' => $positions,
            ];
        }

        return new self(self::lowered($heads, $consumed, $ledger));
    }

    /** The subject a consumption names: its Bezug, or — before the Bezug field — its project. */
    private static function purposeOf(int $deal, Table $transactions, ?int $reference, ?int $project): int
    {
        $subject = $reference === null ? 0 : (int) $transactions->first($deal, $reference);

        return $subject > 0 || $project === null ? $subject : (int) $transactions->first($deal, $project);
    }

    /**
     * The positions lowered by what was consumed for their subject: a lot first from the positions naming it, the rest
     * from the positions of its material without a lot; a position consumed whole is no longer open.
     *
     * @param array<int, array{label: string, subject: int, date: string, deadline: string, positions: array<int, array{thing: int, lot: int|null, quantity: float}>}> $heads
     * @param array<int, array<int, float>> $consumed subject → lot → quantity consumed
     * @return array<int, array{label: string, subject: int, date: string, deadline: string, positions: array<int, array{thing: int, lot: int|null, quantity: float}>}>
     */
    private static function lowered(array $heads, array $consumed, Ledger $ledger): array
    {
        foreach ($consumed as $subject => $lots) {
            foreach ($lots as $lot => $left) {
                $thing = (int) $ledger->thingOf($lot);

                foreach ([true, false] as $named) {
                    foreach ($heads as $deal => $head) {
                        if ($head['subject'] !== $subject) {
                            continue;
                        }

                        foreach ($head['positions'] as $line => $position) {
                            $fits = $named ? $position['lot'] === $lot : $position['lot'] === null && $position['thing'] === $thing;

                            if (! $fits || $left <= self::NOTHING) {
                                continue;
                            }

                            $taken = min($left, $position['quantity']);
                            $left -= $taken;
                            $rest  = $position['quantity'] - $taken;

                            if ($rest > self::NOTHING) {
                                $heads[$deal]['positions'][$line]['quantity'] = $rest;
                            } else {
                                unset($heads[$deal]['positions'][$line]);
                            }
                        }
                    }
                }
            }
        }

        return $heads;
    }

    /**
     * Every reservation, open ones and those taken back whole (no open position left).
     *
     * @return array<int, array{label: string, subject: int, date: string, deadline: string, positions: array<int, array{thing: int, lot: int|null, quantity: float}>}>
     */
    public function all(): array
    {
        return $this->heads;
    }

    /** @return array<int, array{label: string, subject: int, date: string, deadline: string, positions: array<int, array{thing: int, lot: int|null, quantity: float}>}> */
    public function open(): array
    {
        return array_filter($this->heads, static fn (array $head): bool => $head['positions'] !== []);
    }

    /** The open reservation of a subject — one per subject; a new position goes into it. */
    public function forSubject(int $subject): ?int
    {
        foreach ($this->open() as $deal => $head) {
            if ($subject > 0 && $head['subject'] === $subject) {
                return $deal;
            }
        }

        return null;
    }

    /** @return array<int, float> thing → reserved quantity */
    public function reservedByThing(): array
    {
        $out = [];

        foreach ($this->heads as $head) {
            foreach ($head['positions'] as $position) {
                $out[$position['thing']] = ($out[$position['thing']] ?? 0.0) + $position['quantity'];
            }
        }

        return $out;
    }

    /** @return array<int, float> lot → quantity a reservation names */
    public function boundLots(): array
    {
        $out = [];

        foreach ($this->heads as $head) {
            foreach ($head['positions'] as $position) {
                if ($position['lot'] !== null) {
                    $out[$position['lot']] = ($out[$position['lot']] ?? 0.0) + $position['quantity'];
                }
            }
        }

        return $out;
    }

    /**
     * The lots the open reservations of one subject name.
     *
     * @return array<int, float> lot → quantity
     */
    public function boundLotsOf(int $subject): array
    {
        $out = [];

        foreach ($this->heads as $head) {
            foreach ($subject > 0 && $head['subject'] === $subject ? $head['positions'] : [] as $position) {
                if ($position['lot'] !== null) {
                    $out[$position['lot']] = ($out[$position['lot']] ?? 0.0) + $position['quantity'];
                }
            }
        }

        return $out;
    }

    /**
     * D-1088: a consumption of a lot a reservation names takes what lies free, and beyond it only for exactly the
     * reservation's Bezug; for any other purpose it stays strict. Inside a reserved area the place decides
     * ({@see ReservedPlaces::checkConsumption()}); a lot no reservation names may still go below zero (concept §11).
     */
    public function checkConsumption(int $lot, string $quantity, int $place, Purpose $purpose, Ledger $ledger, ReservedPlaces $places): void
    {
        $amount = (float) Quantity::positive($quantity);
        $bound  = $this->boundLots()[$lot] ?? 0.0;

        if ($bound <= self::NOTHING) {
            return;
        }

        // D-1582: inside a box what one of its orders was given is not consumed for anyone else.
        if ($places->reservingPlaceOf($place) !== null) {
            $others = $bound - ($this->boundLotsOf($purpose->subject)[$lot] ?? 0.0);

            if ($amount > max(0.0, $ledger->restAt($lot, $place) - $others) + self::NOTHING) {
                throw StockError::reservedForOther($lot);
            }

            return;
        }

        $inAreas = 0.0;

        foreach ($places->holdings($ledger) as $lots) {
            $inAreas += array_sum($lots[$lot] ?? []);
        }

        $free = max(0.0, $ledger->restOf($lot) - $inAreas - $bound);

        if ($amount > $free + ($this->boundLotsOf($purpose->subject)[$lot] ?? 0.0) + self::NOTHING) {
            throw StockError::reservedForOther($lot);
        }
    }

    /** @return array<int, float> reservation id → what it holds of this thing */
    public function ofThing(int $thing): array
    {
        $out = [];

        foreach ($this->heads as $deal => $head) {
            foreach ($head['positions'] as $position) {
                if ($position['thing'] === $thing) {
                    $out[$deal] = ($out[$deal] ?? 0.0) + $position['quantity'];
                }
            }
        }

        return $out;
    }

    /**
     * New positions: the material of a named lot must be the lot's, and a lot gives only what lies free — not in a
     * reserved area, not named by another reservation. A position without a lot may reserve more than there is: the
     * available stock then goes below zero and shows what is missing (INFERRED).
     *
     * D-1582: for an order of project `$within` the boxes reserved for that project give what lies in them, so the
     * reservation of the place becomes more exact (project → order) instead of a second one.
     *
     * @param list<ReservationRow> $rows
     */
    public function check(array $rows, Ledger $ledger, ReservedPlaces $places, int $within = 0): void
    {
        $bound = $this->boundLots();
        $held  = array_filter($places->holdings($ledger), static fn (int $area): bool => $within <= 0 || $places->subjectAt($area) !== $within, ARRAY_FILTER_USE_KEY);
        $asked = [];

        foreach ($rows as $row) {
            $quantity = (float) Quantity::positive($row->quantity);

            if ($row->lot === null) {
                continue;
            }

            if ($ledger->thingOf($row->lot) !== $row->thing) {
                throw StockError::noLot($row->thing);
            }

            $inAreas = 0.0;

            foreach ($held as $lots) {
                $inAreas += array_sum($lots[$row->lot] ?? []);
            }

            $asked[$row->lot] = ($asked[$row->lot] ?? 0.0) + $quantity;

            if ($asked[$row->lot] > $ledger->restOf($row->lot) - $inAreas - ($bound[$row->lot] ?? 0.0) + self::NOTHING) {
                throw StockError::notEnoughFree($row->lot);
            }
        }
    }

    /**
     * D-1582: the box of project `$within` gives only to an order of it — a subject that is neither a project nor a sale,
     * refining the box's Bezug project → order. Every other case is refused, before {@see check()} looks at the box.
     *
     * @param array<int, true> $projects the project records
     * @param array<int, true> $sales    the sale records
     */
    public static function checkRefinement(int $subject, int $within, array $projects, array $sales): void
    {
        if ($within > 0 && ($subject <= 0 || ! isset($projects[$within]) || isset($projects[$subject]) || isset($sales[$subject]))) {
            throw StockError::notAnOrderOfProject($subject);
        }
    }

    /**
     * The Storno of a reservation: every open position, or only `$line`, with what is left of it.
     *
     * @return array<int, array{thing: int, lot: int|null, quantity: float}> line → what to cancel
     */
    public function toCancel(int $deal, ?int $line = null): array
    {
        if (! isset($this->heads[$deal])) {
            throw StockError::notAReservation($deal);
        }

        $positions = $this->heads[$deal]['positions'];

        if ($line !== null) {
            $positions = isset($positions[$line]) ? [$line => $positions[$line]] : throw StockError::alreadyCancelled($deal);
        }

        return $positions === [] ? throw StockError::alreadyCancelled($deal) : $positions;
    }

    /**
     * D-1582 (Stefan 22.11. «Nimmst du die Teile aus dem Gebinde, endet die Reservierung», D-1082): what an Umbuchung
     * moves out of a reserved area ends the positions naming it there — first what lay free in the area, then the newest
     * reservation's (INFERRED: the order in which given pieces go is not decided).
     *
     * @param list<TransferRow> $moves
     * @return array<int, array<int, array{thing: int, lot: int|null, quantity: float}>> reservation → line → what to cancel
     */
    public function endedBy(array $moves, Ledger $ledger, ReservedPlaces $places): array
    {
        $out = [];
        $gone = [];
        $held = $places->holdings($ledger);

        foreach ($moves as $move) {
            $area = $places->reservingPlaceOf($move->from);

            if ($area !== null && $places->reservingPlaceOf($move->to) !== $area) {
                $gone[$area][$move->lot] = ($gone[$area][$move->lot] ?? 0.0) + (float) $move->quantity;
            }
        }

        $heads = $this->heads;
        krsort($heads);

        foreach ($gone as $area => $lots) {
            foreach ($lots as $lot => $moved) {
                $rest  = array_sum($held[$area][$lot] ?? []) - $moved;
                $given = 0.0;

                foreach ($heads as $head) {
                    foreach ($head['positions'] as $position) {
                        $given += $position['lot'] === $lot ? $position['quantity'] : 0.0;
                    }
                }

                $ended = $given - max(0.0, $rest);

                foreach ($heads as $deal => $head) {
                    foreach ($head['positions'] as $line => $position) {
                        if ($ended <= self::NOTHING || $position['lot'] !== $lot) {
                            continue;
                        }

                        $part              = min($ended, $position['quantity']);
                        $out[$deal][$line] = ['quantity' => $part] + $position;
                        $ended            -= $part;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * D-1582 for a whole box hung elsewhere (Prüftor 22.11.: «ganze Kiste umhängen»): what it carries out of its reserved
     * area ends there as if moved out lot by lot ({@see endedBy()}); an area inside the box goes along and ends nothing.
     *
     * @param list<int> $inside the box and every place in it
     * @return array<int, array<int, array{thing: int, lot: int|null, quantity: float}>> reservation → line → what to cancel
     */
    public function endedByHang(array $inside, int $parent, Ledger $ledger, ReservedPlaces $places): array
    {
        $set   = array_flip($inside);
        $moves = [];

        foreach ($ledger->stockByPlace() as $lot => $at) {
            foreach (array_intersect_key($at, $set) as $place => $quantity) {
                $area = $places->reservingPlaceOf($place);

                if ($area !== null && ! isset($set[$area]) && $quantity > self::NOTHING) {
                    $moves[] = new TransferRow($lot, $place, $parent, (string) $quantity);
                }
            }
        }

        return $this->endedBy($moves, $ledger, $places);
    }

    public function subjectOf(int $deal): int
    {
        return $this->heads[$deal]['subject'] ?? 0;
    }
}
