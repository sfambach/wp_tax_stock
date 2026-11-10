<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1297: what left the stock as consumption, per kind and year — the pieces times each lot's value per piece; a line whose lot
 * has no value counts as «ohne Wert», never as nothing.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1297
 */
final class ConsumptionByKind
{
    /**
     * @param array<int, array<string, array{value: float, unvalued: int}>> $cells kind → year → value and lines without value
     */
    private function __construct(private readonly array $cells)
    {
    }

    /**
     * The money parts the report needs — the values per piece of the lots consumed — so a page reads only those, not every price
     * of the installation.
     *
     * @return list<int>
     */
    public static function valueParts(Structure $s, Table $transactions, Table $lines, Table $lots): array
    {
        $parts = [];
        foreach ($transactions->ids() as $deal) {
            if (! $s->isConsumption((int) $transactions->first($deal, $s->field('transaction_kind')))) {
                continue;
            }
            foreach ($transactions->all($deal, $s->field('transaction_lines')) as $line) {
                $part = (int) $lots->first((int) $lines->first((int) $line, $s->field('line_lot')), $s->field('lot_value'));
                if ($part > 0) {
                    $parts[$part] = true;
                }
            }
        }

        return array_keys($parts);
    }

    /** One pass over the Vorgänge, their lines, the lots and the money parts already read (CD-7); a Storno and what it cancelled left out. */
    public static function from(Structure $s, Table $transactions, Table $lines, Table $lots, Table $money): self
    {
        $paired = (new Storno($s))->pairedLines($lines);
        $sale   = $s->hasValue('kind_sale') ? $s->value('kind_sale') : 0;
        $cells  = [];

        foreach ($transactions->ids() as $deal) {
            $kind = (int) $transactions->first($deal, $s->field('transaction_kind'));
            if ($kind === $sale || ! $s->isConsumption($kind)) {
                continue;
            }
            $year = substr((string) $transactions->first($deal, $s->field('transaction_date')), 0, 4);

            foreach ($transactions->all($deal, $s->field('transaction_lines')) as $line) {
                $line = (int) $line;
                if (! $lines->has($line) || isset($paired[$line])) {
                    continue;
                }
                $pieces = -(float) $lines->first($line, $s->field('line_quantity'));
                $part   = $lots->first((int) $lines->first($line, $s->field('line_lot')), $s->field('lot_value'));
                $each   = $part === null ? null : $money->first((int) $part, $s->field('money_amount'));
                $cells[$kind][$year] ??= ['value' => 0.0, 'unvalued' => 0];
                if ($each === null || $each === '') {
                    $cells[$kind][$year]['unvalued']++;
                    continue;
                }
                $cells[$kind][$year]['value'] += $pieces * (float) $each;
            }
        }

        return new self($cells);
    }

    /** @return array<int, array<string, array{value: float, unvalued: int}>> kind → year → cell */
    public function cells(): array
    {
        return $this->cells;
    }

    /** @return list<string> the years that hold any consumption, oldest first */
    public function years(): array
    {
        $years = array_keys(array_replace(...array_values($this->cells ?: [[]])));
        sort($years);

        return array_values(array_map('strval', $years));
    }
}
