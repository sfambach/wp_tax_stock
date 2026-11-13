<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1337: the short mark proposed for a place — along its path, each place of a kind with a Kürzel gives that Kürzel and
 * its number among its siblings of the same kind: «R1-F1-B8». A place without a kind (a room) gives nothing; a place of a
 * kind without a Kürzel means no proposal at all — nothing is guessed from a name.
 *
 * @see docs/NewConcept/90-decision-log.md D-1337
 */
final class ShortMarks
{
    /**
     * @param array<int, int>    $kinds  place → its kind, 0 for none
     * @param array<int, string> $shorts kind → its Kürzel
     */
    public static function proposed(int $place, LocationTree $tree, array $kinds, array $shorts): string
    {
        $parts = [];

        foreach ($tree->lineOf($place) as $at) {
            $kind = $kinds[$at] ?? 0;

            if ($kind === 0) {
                continue;
            }

            $short = trim($shorts[$kind] ?? '');

            if ($short === '') {
                return '';
            }

            // By the title, not the name: the name carries the short mark itself, which would shift the order.
            $same    = array_values(array_filter($tree->childrenOf($tree->parentOf($at)), static fn (int $sibling): bool => ($kinds[$sibling] ?? 0) === $kind));
            usort($same, static fn (int $a, int $b): int => strnatcasecmp($tree->titleOf($a), $tree->titleOf($b)));
            $parts[] = $short . (array_search($at, $same, true) + 1);
        }

        return implode('-', $parts);
    }
}
