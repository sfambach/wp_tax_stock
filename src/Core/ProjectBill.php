<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What a project needs by its parts list, set against what lies in its box (D-1139).
 *
 * The parts list is the project's own: each board of the project, its newest revision (largest id, INFERRED), and that
 * revision's positions, one piece of each part per «Anzahl». What lies is everything on the box and on every place in it.
 *
 * ```mermaid
 * flowchart LR
 *   P["Projekt"] --> B["Platinen"] --> R["newest Revision"] --> L["Positionen: Part × Anzahl"]
 *   L --> N["needed per thing"]
 *   O["box and places inside"] --> S["lots there per thing"]
 *   N --> C["needed · there · missing · surplus"]
 *   S --> C
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1139
 */
final class ProjectBill
{
    /** The keys a parts list needs in the structure; without them there is none. */
    public const KEYS = ['project_boards', 'board_revisions', 'revision_positions', 'position_part', 'position_count'];

    /**
     * @param array<int, float> $needed    thing → pieces the parts list asks for
     * @param list<int>         $revisions the revisions the list was read from
     */
    public function __construct(public readonly array $needed, public readonly array $revisions)
    {
    }

    public static function isModelled(Structure $s): bool
    {
        foreach (self::KEYS as $key) {
            if (! $s->hasField($key)) {
                return false;
            }
        }

        return true;
    }

    public static function of(Structure $s, Table $projects, Table $boards, Table $revisions, Table $positions, int $project): self
    {
        $needed = [];
        $read   = [];

        foreach ($projects->all($project, $s->field('project_boards')) as $board) {
            $ids = array_map('intval', $boards->all((int) $board, $s->field('board_revisions')));

            if ($ids === []) {
                continue;
            }

            $revision = max($ids);
            $read[]   = $revision;

            foreach ($revisions->all($revision, $s->field('revision_positions')) as $position) {
                $part = (int) $positions->first((int) $position, $s->field('position_part'));

                if ($part > 0) {
                    $needed[$part] = ($needed[$part] ?? 0.0) + (float) ($positions->first((int) $position, $s->field('position_count')) ?? 1);
                }
            }
        }

        return new self($needed, $read);
    }

    /**
     * @param list<int> $places the box and every place in it
     * @return array<int, float> thing → pieces lying there
     */
    public static function present(Ledger $ledger, array $places): array
    {
        $inside = array_flip($places);
        $out    = [];

        foreach ($ledger->stockByPlace() as $lot => $at) {
            $thing = $ledger->thingOf($lot);

            foreach (array_intersect_key($at, $inside) as $quantity) {
                if ($thing !== null) {
                    $out[$thing] = ($out[$thing] ?? 0.0) + $quantity;
                }
            }
        }

        return $out;
    }

    /**
     * The parts list first in its order, then what lies there without being on it.
     *
     * @param array<int, float> $present thing → pieces lying there
     * @return list<array{thing: int, needed: float, present: float, missing: float, surplus: float}>
     */
    public function compare(array $present): array
    {
        $rows = [];

        foreach ($this->needed + array_map(static fn (): float => 0.0, $present) as $thing => $needed) {
            $there  = $present[$thing] ?? 0.0;
            $rows[] = ['thing' => $thing, 'needed' => $needed, 'present' => $there, 'missing' => max(0.0, $needed - $there), 'surplus' => max(0.0, $there - $needed)];
        }

        return $rows;
    }

    /** @param array<int, float> $present */
    public function missingParts(array $present): int
    {
        return count(array_filter($this->compare($present), static fn (array $row): bool => $row['missing'] > 0.0));
    }
}
