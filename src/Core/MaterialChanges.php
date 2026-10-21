<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Creating or editing one material as one change list (tab «Material»).
 *
 * D-967: with «Bezug Ding» the material has no name of its own — an edit that sets a reference empties it.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-967
 */
final class MaterialChanges
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return list<array<string, mixed>> */
    public function create(MaterialInput $input): array
    {
        $values = array_filter($this->values($input), static fn (string $v): bool => $v !== '');

        return [
            ['op' => 'create_record', 'ref' => 'm', 'node' => $this->structure->node('material')],
            ['op' => 'set_values', 'record' => '@m', 'values' => $values],
        ];
    }

    /**
     * An empty value clears the field: that is how the modeller's set_values forgets a value.
     *
     * @return list<array<string, mixed>>
     */
    public function update(int $material, MaterialInput $input): array
    {
        return [['op' => 'set_values', 'record' => $material, 'values' => $this->values($input)]];
    }

    /** @return array<string, string> */
    private function values(MaterialInput $input): array
    {
        $s = $this->structure;

        if ($input->thing === null && trim($input->name) === '') {
            throw StockError::materialWithoutName();
        }

        if ($input->thing !== null && ! $s->hasField('material_ref_record')) {
            throw StockError::notConfigured('fields.material_ref_record');
        }

        $values = [
            (string) $s->field('label')           => $input->thing === null ? trim($input->name) : '',
            (string) $s->field('material_single') => $input->single ? '1' : '0',
        ];

        $optional = [
            'material_ref_record' => $input->thing,
            'material_use'        => $input->use,
            'material_location'   => $input->location,
            'material_min'        => $input->minimum,
        ];

        foreach ($optional as $key => $value) {
            if ($s->hasField($key)) {
                $values[(string) $s->field($key)] = $value === null ? '' : (string) $value;
            }
        }

        return $values;
    }
}
