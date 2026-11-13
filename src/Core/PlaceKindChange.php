<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Where the places inside an occupied place go when its kind changes (D-1315): the way chosen in the dialog pairs each old
 * place with a new one or with the place itself.
 *
 * ```mermaid
 * flowchart LR
 *   old[old places inside, by name] -->|way| pair{paired by position?}
 *   pair -->|yes| new[new place, the same rule one level down]
 *   pair -->|no| up[the nearest paired place above, at the top the place itself]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1315
 */
final class PlaceKindChange
{
    /** Everything onto the place itself. */
    public const WAY_PARENT = 'vater';

    /** By position, as far as the new places reach; the rest onto the place itself — with equal counts this is 1:1. */
    public const WAY_FIT = 'passt';

    /** Each old place onto a new one, round the new ones again when there are more old ones. */
    public const WAY_SPREAD = 'verteilen';

    /**
     * The ways the dialog offers; none is preselected (D-1315). Nothing inside asks nothing. With equal counts «passt» is
     * 1:1 and «verteilen» the same; with no new places every way puts everything onto the place itself.
     *
     * @return list<string>
     */
    public static function ways(int $oldCount): array
    {
        return $oldCount === 0 ? [] : [self::WAY_PARENT, self::WAY_FIT, self::WAY_SPREAD];
    }

    /**
     * Every old place inside → where what lies there goes: '' is the place itself, «teilN» the new place
     * {@see LocationChanges::changeKind()} creates under that ref (numbered in the same order).
     *
     * @param list<array{id: int, below: list<array<string, mixed>>}> $old the places inside, nested, in tree order
     * @param list<array{type: int, name: string, below: list<array<string, mixed>>}> $new what the new kind brings along
     * @return array<int, string>
     */
    public static function targets(array $old, array $new, string $way): array
    {
        if ($old === []) {
            return [];
        }

        if (! in_array($way, self::ways(count($old)), true)) {
            throw StockError::noWayChosen();
        }

        $count    = 0;
        $numbered = self::numbered($new, $count);
        $out      = [];
        self::pair($old, $way === self::WAY_PARENT ? [] : $numbered, '', $way === self::WAY_SPREAD, $out);

        return $out;
    }

    /**
     * @param list<array{type: int, name: string, below: list<array<string, mixed>>}> $places
     * @return list<array{ref: string, below: list<array<string, mixed>>}>
     */
    private static function numbered(array $places, int &$count): array
    {
        $out = [];

        foreach ($places as $place) {
            $ref   = 'teil' . ++$count;
            $out[] = ['ref' => $ref, 'below' => self::numbered($place['below'], $count)];
        }

        return $out;
    }

    /**
     * @param list<array{id: int, below: list<array<string, mixed>>}> $old
     * @param list<array{ref: string, below: list<array<string, mixed>>}> $new
     * @param array<int, string> $out
     */
    private static function pair(array $old, array $new, string $above, bool $spread, array &$out): void
    {
        $n = count($new);

        foreach (array_values($old) as $i => $place) {
            $match = $n === 0 ? null : ($spread ? $new[$i % $n] : ($new[$i] ?? null));

            if ($match === null) {
                self::all($place, $above, $out);
                continue;
            }

            $out[$place['id']] = $match['ref'];
            self::pair($place['below'], $match['below'], $match['ref'], false, $out);
        }
    }

    /**
     * @param array{id: int, below: list<array<string, mixed>>} $place
     * @param array<int, string> $out
     */
    private static function all(array $place, string $to, array &$out): void
    {
        $out[$place['id']] = $to;

        foreach ($place['below'] as $below) {
            self::all($below, $to, $out);
        }
    }
}
