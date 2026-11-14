<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * «Kopieren» of a material as one change list: the thing with all its data, and a Lager record of its own (D-1130).
 *
 * No lot is copied — the stock of the copy starts at nothing. A source keeps its firm and pack content but not its number:
 * an order number belongs to exactly one material (D-1013).
 */
final class MaterialCopy
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * @param int|null        $lager   the original's Lager record, null when it has none
     * @param list<SourceRow> $sources the original's sources
     * @param string|null     $label   the copy's own label; null when the thing has no label of its own (it is derived, D-888)
     * @return list<array<string, mixed>> the copy answers the ref «kopie»
     */
    public function copy(int $thing, ?int $lager, Table $lagerRecords, array $sources, ?string $label): array
    {
        $s       = $this->structure;
        $changes = [['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => (string) $thing]];

        if ($label !== null && trim($label) !== '') {
            $changes[] = ['op' => 'set_values', 'record' => '@kopie', 'values' => [(string) $s->field('label') => trim($label)]];
        }

        if ($lager === null || ! $lagerRecords->has($lager)) {
            return $changes;
        }

        $id    = static fn (string $key): ?int => $s->hasField($key) && ($v = $lagerRecords->first($lager, $s->field($key))) !== null && (int) $v > 0 ? (int) $v : null;
        $min   = $lagerRecords->first($lager, $s->field('lager_min'));
        $input = new LagerInput(
            $lagerRecords->first($lager, $s->field('lager_single')) === '1',
            $id('lager_use'),
            $id('lager_location'),
            $min === null || $min === '' ? null : (int) $min,
            array_map(static fn (SourceRow $row): SourceRow => new SourceRow(null, $row->firm, '', $row->content), $sources),
            $id('lager_unit'),
            $id('lager_prefix'),
            // D-1360: the copy keeps every Standard-Lagerort, in order.
            $s->hasField('lager_location') ? array_values(array_slice(array_filter(array_map('intval', $lagerRecords->all($lager, $s->field('lager_location')))), 1)) : [],
        );

        return [...$changes, ...(new LagerChanges($s))->save('@kopie', null, $input, 'kopielager')];
    }
}
