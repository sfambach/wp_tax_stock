<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A label record created, changed or removed as one change list (D-1239): its name, size, roll, copies and layout, and the rolls
 * that allow it — so a label is changed as data, without a release.
 *
 * ⚠️ *His word 2026-11-08: «die Schnittstelle für Etiketten optimieren … direkt Etiketten auch noch anlegen könnten, löschen, und
 * ändern. Ich meine, das ist ja alles in JSON abgelegt.»*
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1239
 */
final class LabelChanges
{
    /** What the caller names ⇒ the structure key of its field. */
    public const FIELDS = [
        'name'   => 'label',
        'key'    => 'template_key',
        'width'  => 'template_width',
        'height' => 'template_height',
        'roll'   => 'template_roll',
        'copies' => 'template_copies',
        'layout' => 'template_layout',
    ];

    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * The values a caller gave, by field id; a key the model has no field for is left out, a size must be a whole number above 0.
     *
     * @param array<string, mixed> $given name, key, width, height, roll, copies, layout
     * @return array<string, string> field id ⇒ text
     */
    public function values(array $given): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $key) {
            if (! array_key_exists($name, $given) || ! $this->structure->hasField($key)) {
                continue;
            }
            $value = is_scalar($given[$name]) ? trim((string) $given[$name]) : '';
            if (in_array($name, ['width', 'height', 'roll', 'copies'], true) && $value !== '' && (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1)) {
                throw StockError::notALabelSize($name . '=' . $value);
            }
            if ($name === 'name' && $value === '') {
                throw StockError::labelWithoutName();
            }
            $out[(string) $this->structure->field($key)] = $value;
        }

        return $out;
    }

    /**
     * A new label in a category of «Etiketten» (or «Etiketten» itself); it answers the ref «etikett».
     *
     * @param array<string, mixed> $given
     * @param list<int> $rolls the rolls that allow it from now on
     * @return list<array<string, mixed>>
     */
    public function create(int $node, array $given, array $rolls = [], ?Table $rollTable = null): array
    {
        if (trim((string) ($given['name'] ?? '')) === '') {
            throw StockError::labelWithoutName();
        }

        return array_merge(
            [
                ['op' => 'create_record', 'ref' => 'etikett', 'node' => (string) $node],
                ['op' => 'set_values', 'record' => '@etikett', 'values' => $this->values($given)],
            ],
            $this->allowOn('@etikett', $rolls, $rollTable),
        );
    }

    /**
     * @param array<string, mixed> $given only what changes
     * @param list<int> $rolls rolls that allow it from now on, besides those that already do
     * @return list<array<string, mixed>>
     */
    public function update(int $label, array $given, array $rolls = [], ?Table $rollTable = null): array
    {
        $values  = $this->values($given);
        $changes = $values === [] ? [] : [['op' => 'set_values', 'record' => (string) $label, 'values' => $values]];

        return array_merge($changes, $this->allowOn((string) $label, $rolls, $rollTable));
    }

    /**
     * The label out of every roll that allows it, then the record itself. A place type that prints with it keeps it.
     *
     * @return list<array<string, mixed>>
     */
    public function remove(int $label, ?Table $rollTable = null, PlaceTypes $types = new PlaceTypes()): array
    {
        $users = [];
        foreach (array_keys($types->names()) as $type) {
            if ($types->labelTemplateOf($type) === $label) {
                $users[] = $types->nameOf($type);
            }
        }
        if ($users !== []) {
            throw StockError::labelInUse(implode(', ', $users));
        }

        $changes = [];
        foreach ($this->rollsAllowing($label, $rollTable) as $roll) {
            $kept      = array_values(array_filter($this->allowed($roll, $rollTable), static fn (string $id): bool => $id !== (string) $label));
            $changes[] = ['op' => 'set_values', 'record' => (string) $roll, 'values' => [(string) $this->structure->field('roll_labels') => ['values' => $kept]]];
        }
        $changes[] = ['op' => 'remove_record', 'record' => (string) $label];

        return $changes;
    }

    /** @return list<int> the rolls whose list of labels names this one */
    public function rollsAllowing(int $label, ?Table $rollTable): array
    {
        if ($rollTable === null || ! $this->structure->hasField('roll_labels')) {
            return [];
        }

        return array_values(array_filter($rollTable->ids(), fn (int $roll): bool => in_array((string) $label, $this->allowed($roll, $rollTable), true)));
    }

    /**
     * @param list<int> $rolls
     * @return list<array<string, mixed>>
     */
    private function allowOn(string $label, array $rolls, ?Table $rollTable): array
    {
        if ($rolls === [] || $rollTable === null || ! $this->structure->hasField('roll_labels')) {
            return [];
        }
        $changes = [];
        foreach (array_unique($rolls) as $roll) {
            if (! $rollTable->has($roll)) {
                throw StockError::notARoll((string) $roll);
            }
            $allowed = $this->allowed($roll, $rollTable);
            if (in_array($label, $allowed, true)) {
                continue;
            }
            $changes[] = ['op' => 'set_values', 'record' => (string) $roll, 'values' => [(string) $this->structure->field('roll_labels') => ['values' => array_merge($allowed, [$label])]]];
        }

        return $changes;
    }

    /** @return list<string> */
    private function allowed(int $roll, Table $rollTable): array
    {
        return array_values(array_map('strval', $rollTable->all($roll, $this->structure->field('roll_labels'))));
    }
}
