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
    public function create(string $name, int $parent, bool $movable, int $project, LocationTree $tree, Table $places, bool $reserved = false, int $type = 0, PlaceTypes $types = new PlaceTypes()): array
    {
        $s    = $this->structure;
        $name = trim($name);

        if ($name === '') {
            throw StockError::placeWithoutName();
        }

        if ($parent > 0 && ! $places->has($parent)) {
            throw StockError::notALocation($parent);
        }

        // D-1123: a known kind decides whether the place moves and which places it brings along (Alex: nine drawers).
        $type   = $types->has($type) && $s->hasField('location_place_type') ? $type : 0;
        $values = [(string) $s->field('label') => $name] + $this->treeValues($parent, $type > 0 ? (bool) $types->isMovable($type) : $movable, $project);

        if ($type > 0) {
            $values[(string) $s->field('location_place_type')] = (string) $type;
        }

        if ($reserved) {
            $values[(string) $this->reservedField()] = '1';
        }

        $changes = [
            ['op' => 'create_record', 'ref' => 'ort', 'node' => (string) $s->node('location')],
            ['op' => 'set_values', 'record' => '@ort', 'values' => $values],
        ];

        if ($type > 0) {
            $this->needTree();
            $count = 0;
            $this->bring($types->brought($type), 'ort', $types, $changes, $count);
        }

        return $changes;
    }

    /**
     * @param list<array{type: int, name: string, below: list<array<string, mixed>>}> $places
     * @param list<array<string, mixed>> $changes
     */
    private function bring(array $places, string $parentRef, PlaceTypes $types, array &$changes, int &$count): void
    {
        $s = $this->structure;

        foreach ($places as $place) {
            $ref     = 'teil' . ++$count;
            $values  = [
                (string) $s->field('label')               => $place['name'],
                (string) $s->field('location_parent')     => '@' . $parentRef,
                (string) $s->field('location_place_type') => (string) $place['type'],
            ];

            if ($s->hasField('location_movable')) {
                $values[(string) $s->field('location_movable')] = $types->isMovable($place['type']) ? '1' : '0';
            }

            if ($s->hasField('location_reserved')) {
                $values[(string) $s->field('location_reserved')] = '0';
            }

            $changes[] = ['op' => 'create_record', 'ref' => $ref, 'node' => (string) $s->node('location')];
            $changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values];
            $this->bring($place['below'], $ref, $types, $changes, $count);
        }
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
     * D-1130: «Kopieren» — the place beside itself under a name of its own, with all its fields; with «Unterorte» each place
     * inside it too, hung into its copy. What lies there stays: a lot names its place, a place names no lot.
     *
     * @return list<array<string, mixed>> the copy of $place answers the ref «kopie0»
     */
    public function copy(int $place, string $name, bool $withBelow, LocationTree $tree, Table $places): array
    {
        $name = trim($name);

        if ($name === '') {
            throw StockError::placeWithoutName();
        }

        if (! $places->has($place)) {
            throw StockError::notALocation($place);
        }

        $s       = $this->structure;
        $changes = [
            ['op' => 'duplicate_record', 'ref' => 'kopie0', 'record' => (string) $place],
            ['op' => 'set_values', 'record' => '@kopie0', 'values' => [(string) $s->field('label') => $name]],
        ];

        if (! $withBelow) {
            return $changes;
        }

        $this->needTree();
        $refs = [$place => 'kopie0'];

        // *Parents before children, so each copy can name the copy of its parent.*
        foreach ($tree->ordered() as [$below]) {
            if ($below === $place || ! in_array($place, $tree->lineOf($below), true)) {
                continue;
            }

            $refs[$below] = 'kopie' . count($refs);
            $changes[]    = ['op' => 'duplicate_record', 'ref' => $refs[$below], 'record' => (string) $below];
            $changes[]    = ['op' => 'set_values', 'record' => '@' . $refs[$below], 'values' => [(string) $s->field('location_parent') => '@' . $refs[$tree->parentOf($below)]]];
        }

        return $changes;
    }

    /**
     * D-1130: «Kopieren» of a kind of place — all its fields and its own copy of each part line (a part is owned, D-1012);
     * the places of that kind stay with the original.
     *
     * @return list<array<string, mixed>> the copy answers the ref «kopie»
     */
    public function copyType(int $type, string $name, PlaceTypes $types): array
    {
        $name = trim($name);

        if ($name === '') {
            throw StockError::placeWithoutName();
        }

        if (! $types->has($type)) {
            throw StockError::notALocation($type);
        }

        return [
            ['op' => 'duplicate_record', 'ref' => 'kopie', 'record' => (string) $type],
            ['op' => 'set_values', 'record' => '@kopie', 'values' => [(string) $this->structure->field('label') => $name]],
        ];
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
