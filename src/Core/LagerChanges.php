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
     * An empty value clears the field: that is how the modeller's set_values forgets a value.
     *
     * @return list<array<string, mixed>>
     */
    public function save(int $thing, ?int $lagerRecord, LagerInput $input): array
    {
        $s      = $this->structure;
        $values = [
            (string) $s->field('lager_thing')  => (string) $thing,
            (string) $s->field('lager_single') => $input->single ? '1' : '0',
            (string) $s->field('lager_min')    => $input->minimum === null ? '' : (string) $input->minimum,
        ];

        foreach (['lager_use' => $input->use, 'lager_location' => $input->location] as $key => $value) {
            if ($s->hasField($key)) {
                $values[(string) $s->field($key)] = $value === null ? '' : (string) $value;
            }
        }

        if ($lagerRecord !== null) {
            return [['op' => 'set_values', 'record' => $lagerRecord, 'values' => $values]];
        }

        return [
            ['op' => 'create_record', 'ref' => 'lager', 'node' => $s->node('lager')],
            ['op' => 'set_values', 'record' => '@lager', 'values' => array_filter($values, static fn (string $v): bool => $v !== '')],
        ];
    }
}
