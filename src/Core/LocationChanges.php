<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Creating a place in the tree and hanging it elsewhere, as change lists (D-1030).
 *
 * Moving a box only changes its parent: everything in it goes with it, no lot is rebooked (design 3.2).
 */
final class LocationChanges
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return list<array<string, mixed>> */
    public function create(string $name, int $parent, bool $movable, int $project, LocationTree $tree, Table $places, bool $reserved = false): array
    {
        $s    = $this->structure;
        $name = trim($name);

        if ($name === '') {
            throw StockError::placeWithoutName();
        }

        if ($parent > 0 && ! $places->has($parent)) {
            throw StockError::notALocation($parent);
        }

        $values = [(string) $s->field('label') => $name] + $this->treeValues($parent, $movable, $project);

        if ($reserved) {
            $values[(string) $this->reservedField()] = '1';
        }

        return [
            ['op' => 'create_record', 'ref' => 'ort', 'node' => (string) $s->node('location')],
            ['op' => 'set_values', 'record' => '@ort', 'values' => $values],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function move(int $place, int $parent, LocationTree $tree, Table $places): array
    {
        if (! $places->has($place) || ($parent > 0 && ! $places->has($parent))) {
            throw StockError::notALocation($parent > 0 && ! $places->has($parent) ? $parent : $place);
        }

        if ($parent > 0 && in_array($parent, $tree->withAllBelow($place), true)) {
            throw StockError::placeIntoItself($place);
        }

        $this->needTree();

        return [['op' => 'set_values', 'record' => (string) $place, 'values' => [(string) $this->structure->field('location_parent') => $parent > 0 ? (string) $parent : '']]];
    }

    /**
     * D-1082: the flag «reserviert für Produktion» set or taken off. Checks on what lies there are the caller's
     * ({@see ReservedPlaces::checkFlag()}); taking it off frees the area — the boundary asks first.
     *
     * @return list<array<string, mixed>>
     */
    public function reserve(int $place, bool $reserved, Table $places): array
    {
        if (! $places->has($place)) {
            throw StockError::notALocation($place);
        }

        return [['op' => 'set_values', 'record' => (string) $place, 'values' => [(string) $this->reservedField() => $reserved ? '1' : '0']]];
    }

    private function reservedField(): int
    {
        return $this->structure->field('location_reserved');
    }

    /** @return array<string, string> */
    private function treeValues(int $parent, bool $movable, int $project): array
    {
        $s   = $this->structure;
        $out = [];

        if ($parent > 0) {
            $this->needTree();
            $out[(string) $s->field('location_parent')] = (string) $parent;
        }

        if ($s->hasField('location_movable')) {
            $out[(string) $s->field('location_movable')] = $movable ? '1' : '0';
        }

        if ($project > 0 && $s->hasField('location_project')) {
            $out[(string) $s->field('location_project')] = (string) $project;
        }

        return $out;
    }

    private function needTree(): void
    {
        if (! $this->structure->hasField('location_parent')) {
            throw StockError::notConfigured('fields.location_parent');
        }
    }
}
