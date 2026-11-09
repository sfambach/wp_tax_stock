<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The storage places as a tree of any depth: each place may name the place it sits in (D-1030).
 *
 * A box or carton is a movable place; moving it means changing its parent, its content goes with it. A place may belong
 * to a project — the box of a project — and so does everything inside it.
 *
 * ```mermaid
 * flowchart TD
 *   K["Keller"] --> B["Bauteile"]
 *   B --> R["Regal 2"]
 *   O["Obergeschoss"] --> X["Box BT2PS2 (beweglich, Projekt)"]
 * ```
 */
final class LocationTree
{
    /**
     * @param array<int, string> $names    place id → its own name
     * @param array<int, int>    $parents  place id → the place it sits in, 0 at the top
     * @param array<int, string> $projects place id → the name of its project, only where one is set
     * @param array<int, true>   $movable  places that are boxes, cartons, pallets
     * @param array<int, int>    $projectIds place id → the record id of its project, only where one is set
     * @param array<int, string> $titles   place id → its Bezeichnung alone, where a short mark stands before it in `$names`
     * @param array<int, string> $shorts   place id → its short mark (Kurzzeichen), only where one is set
     */
    public function __construct(
        private readonly array $names,
        private readonly array $parents,
        private readonly array $projects = [],
        private readonly array $movable = [],
        private readonly array $projectIds = [],
        private readonly array $titles = [],
        private readonly array $shorts = [],
    ) {
    }

    /** @var (\Closure(int): string)|null D-1267: how a place no longer there is named — given at the boundary, the core has no words */
    private ?\Closure $unknown = null;

    /** D-1267: the same tree, naming a deleted place in one place for every page that shows a place. */
    public function withUnknownName(\Closure $name): self
    {
        $copy          = clone $this;
        $copy->unknown = $name;

        return $copy;
    }

    private function nameOf(int $place): string
    {
        return $this->names[$place] ?? ($this->unknown === null ? '#' . $place : ($this->unknown)($place));
    }

    /** @param array<int, string> $projectNames project record id → name */
    public static function from(Structure $s, Table $places, array $projectNames, PlaceTypes $types = new PlaceTypes()): self
    {
        $names = $parents = $projects = $movable = $projectIds = $titles = $shorts = [];

        foreach ($places->ids() as $id) {
            $names[$id]   = (string) ($places->first($id, $s->field('label')) ?? '#' . $id);
            // D-1150, his word 2026-11-04: «der name im menü sollte sich aus kurzzeichen + bezeichnung zusammen setzen».
            $short = $s->hasField('location_short') ? trim((string) $places->first($id, $s->field('location_short'))) : '';

            if ($short !== '') {
                $shorts[$id] = $short;
                $titles[$id] = $names[$id];
                $names[$id]  = $short . ' ' . $names[$id];
            }

            $parents[$id] = $s->hasField('location_parent') ? (int) $places->first($id, $s->field('location_parent')) : 0;

            if ($s->hasField('location_project') && (int) $places->first($id, $s->field('location_project')) > 0) {
                $project       = (int) $places->first($id, $s->field('location_project'));
                $projects[$id] = $projectNames[$project] ?? '#' . $project;
                $projectIds[$id] = $project;
            }

            // D-1123: a place of a known kind moves as its kind says; without one its own mark stands.
            $kind  = $s->hasField('location_place_type') ? $types->isMovable((int) $places->first($id, $s->field('location_place_type'))) : null;
            $moves = $kind ?? ($s->hasField('location_movable') && $places->first($id, $s->field('location_movable')) === '1');

            if ($moves) {
                $movable[$id] = true;
            }
        }

        return new self($names, $parents, $projects, $movable, $projectIds, $titles, $shorts);
    }

    /** @return list<int> the record ids of the projects some place names */
    public static function projectIds(Structure $s, Table $places): array
    {
        if (! $s->hasField('location_project')) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn (int $id): int => (int) $places->first($id, $s->field('location_project')), $places->ids()))));
    }

    /** The place it sits in; 0 at the top, and also where a parent is unknown or would close a loop. */
    public function parentOf(int $id): int
    {
        $parent = $this->parents[$id] ?? 0;

        return isset($this->names[$parent]) && ! in_array($id, $this->ancestorsOf($parent), true) && $parent !== $id ? $parent : 0;
    }

    /** @return list<int> from the top down to the place itself */
    public function lineOf(int $id): array
    {
        return [...array_reverse($this->ancestorsOf($id)), $id];
    }

    /** D-1150: the place's Bezeichnung without its short mark — the title on its label. */
    public function titleOf(int $id): string
    {
        return $this->titles[$id] ?? $this->nameOf($id);
    }

    /** D-1150: the place's short mark (Kurzzeichen), '' where none is set. */
    public function shortOf(int $id): string
    {
        return $this->shorts[$id] ?? '';
    }

    /** «Keller › Bauteile › Regal 2» */
    public function pathOf(int $id): string
    {
        return implode(' › ', array_map($this->nameOf(...), $this->lineOf($id)));
    }

    /** The project of the place or of the nearest place it sits in. */
    public function projectOf(int $id): ?string
    {
        foreach (array_reverse($this->lineOf($id)) as $place) {
            if (isset($this->projects[$place])) {
                return $this->projects[$place];
            }
        }

        return null;
    }

    /** The record id of the project of the place or of the nearest place it sits in; 0 when there is none. */
    public function projectIdOf(int $id): int
    {
        foreach (array_reverse($this->lineOf($id)) as $place) {
            if (isset($this->projectIds[$place])) {
                return $this->projectIds[$place];
            }
        }

        return 0;
    }

    public function isMovable(int $id): bool
    {
        return isset($this->movable[$id]);
    }

    /** @return array<int, string> every place by its path, with its project, in tree order — for choosers and lists */
    public function labels(): array
    {
        $out = [];

        foreach ($this->ordered() as [$id]) {
            $project  = $this->projectOf($id);
            $out[$id] = $this->pathOf($id) . ($project === null ? '' : ' · ' . $project);
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int}> place id and depth, parents before children, siblings by name */
    public function ordered(): array
    {
        $children = [];

        foreach (array_keys($this->names) as $id) {
            $children[$this->parentOf($id)][] = $id;
        }

        $byName = fn (int $a, int $b): int => strnatcasecmp($this->names[$a], $this->names[$b]);
        $out    = [];
        $stack  = [];
        $top    = $children[0] ?? [];
        usort($top, $byName);

        foreach (array_reverse($top) as $id) {
            $stack[] = [$id, 0];
        }

        while ($stack !== []) {
            [$id, $depth] = array_pop($stack);
            $out[]        = [$id, $depth];
            $below        = $children[$id] ?? [];
            usort($below, $byName);

            foreach (array_reverse($below) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $out;
    }

    /** @return list<int> the places right inside it, by name; 0 gives the top level */
    public function childrenOf(int $id): array
    {
        $children = array_values(array_filter(array_keys($this->names), fn (int $place): bool => $place !== $id && $this->parentOf($place) === $id));
        usort($children, fn (int $a, int $b): int => strnatcasecmp($this->names[$a], $this->names[$b]));

        return $children;
    }

    /** @return list<int> the place and everything inside it */
    public function withAllBelow(int $id): array
    {
        return array_values(array_filter(array_keys($this->names), fn (int $place): bool => in_array($id, $this->lineOf($place), true)));
    }

    /** @return list<int> the places it sits in, nearest first; stops at a loop */
    private function ancestorsOf(int $id): array
    {
        $seen = [];
        $at   = $this->parents[$id] ?? 0;

        while ($at !== 0 && isset($this->names[$at]) && $at !== $id && ! in_array($at, $seen, true)) {
            $seen[] = $at;
            $at     = $this->parents[$at] ?? 0;
        }

        return $seen;
    }
}
