<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Saving the tab «Lager» of one thing as one change list: its Lager record, created the first time (D-995, D-997).
 *
 * The Lager record carries no name: what the thing is called stays with the thing (D-996).
 */
final class LagerChanges
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * $thing is a record id, or «@ref» of a thing created in the same change list (D-1071: a new material comes with its Lager
     * record). An empty value clears the field: that is how the modeller's set_values forgets a value.
     *
     * @return list<array<string, mixed>>
     */
    public function save(int|string $thing, ?int $lagerRecord, LagerInput $input, string $ref = 'lager'): array
    {
        $s      = $this->structure;
        $values = [
            (string) $s->field('lager_thing')  => (string) $thing,
            (string) $s->field('lager_single') => $input->single ? '1' : '0',
            (string) $s->field('lager_min')    => $input->minimum === null ? '' : (string) $input->minimum,
        ];

        foreach (['lager_use' => $input->use, 'lager_location' => $input->location, 'lager_unit' => $input->unit, 'lager_prefix' => $input->prefix] as $key => $value) {
            if ($s->hasField($key)) {
                $values[(string) $s->field($key)] = $value === null ? '' : (string) $value;
            }
        }

        // D-1360: the Standard-Lagerorte go as one ordered list, the first is the proposal. ⚠️ Always as a list: the modeller refuses
        // a plain value on a field that already holds several.
        if ($s->hasField('lager_location') && $input->location !== null) {
            $values[(string) $s->field('lager_location')] = ['values' => array_map('strval', [$input->location, ...$input->moreLocations])];
        }

        [$parts, $removed] = $this->sources($input);

        if ($lagerRecord !== null) {
            $values += $parts === [] ? [] : [(string) $s->field('lager_sources') => $parts];

            return [
                ['op' => 'set_values', 'record' => $lagerRecord, 'values' => $values],
                ...array_map(static fn (int $part): array => ['op' => 'remove_record', 'record' => $part], $removed),
            ];
        }

        $values = array_filter($values, static fn (string|array $v): bool => $v !== '');

        return [
            ['op' => 'create_record', 'ref' => $ref, 'node' => $s->node('lager')],
            ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values + ($parts === [] ? [] : [(string) $s->field('lager_sources') => $parts])],
        ];
    }

    /**
     * C1 (D-1320): only the «Bezugsquellen» lines of a thing's Lager record — a record made for them when there is none.
     *
     * @param array<string, array<string, string>> $parts part id (or «new<i>») → raw values by relation id
     * @param list<int> $removed
     * @return list<array<string, mixed>>
     */
    public function sourceLines(int $thing, ?int $lagerRecord, array $parts, array $removed): array
    {
        if ($parts === [] && $removed === []) {
            throw StockError::nothingToBook();
        }

        $s       = $this->structure;
        $changes = $lagerRecord === null
            ? [['op' => 'create_record', 'ref' => 'lager', 'node' => $s->node('lager')], ['op' => 'set_values', 'record' => '@lager', 'values' => [(string) $s->field('lager_thing') => (string) $thing]]]
            : [];
        $target  = $lagerRecord === null ? '@lager' : $lagerRecord;

        if ($parts !== []) {
            $changes[] = ['op' => 'set_values', 'record' => $target, 'values' => [(string) $s->field('lager_sources') => $parts]];
        }

        foreach ($removed as $part) {
            $changes[] = ['op' => 'remove_record', 'record' => $part];
        }

        return $changes;
    }

    /**
     * The lines as the modeller's parts: an existing part by its record id, a new one under a key of its own; an emptied line
     * removes its part.
     *
     * @return array{0: array<string, array<string, string>>, 1: list<int>}
     */
    private function sources(LagerInput $input): array
    {
        $s = $this->structure;

        if ($input->sources === null || ! $s->hasField('lager_sources')) {
            return [[], []];
        }

        $parts   = [];
        $removed = [];

        foreach ($input->sources as $i => $row) {
            if ($row->isEmpty()) {
                if ($row->part !== null) {
                    $removed[] = $row->part;
                }

                continue;
            }

            $parts[$row->part === null ? 'new' . $i : (string) $row->part] = [
                (string) $s->field('source_firm')   => $row->firm === null ? '' : (string) $row->firm,
                (string) $s->field('source_number') => $row->number,
            ] + ($s->hasField('source_content') ? [(string) $s->field('source_content') => $row->content] : []);
        }

        return [$parts, $removed];
    }
}
