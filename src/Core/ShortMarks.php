<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1337, D-1368: the short mark proposed for a place — the short mark of the nearest place above it that has a kind
 * (typed, else proposed), then the Kürzel of its own kind and the next free number among its siblings of the same kind:
 * «R1-F1-B12» after a typed «R1-F1-B11». A number already typed is never reused; the places still without one follow
 * by their title. A place without a kind gets no proposal; nor does one whose kind, or a kind above it, has no Kürzel —
 * nothing is guessed from a name. His report 2026-11-14: «das r1 wird nicht erzeugt … und er setzt es auf b6».
 *
 * @see docs/NewConcept/90-decision-log.md D-1337, D-1368
 */
final class ShortMarks
{
    /**
     * @param array<int, int>    $kinds  place → its kind, 0 for none
     * @param array<int, string> $shorts kind → its Kürzel
     */
    public static function proposed(int $place, LocationTree $tree, array $kinds, array $shorts): string
    {
        $kind  = $kinds[$place] ?? 0;
        $short = trim($shorts[$kind] ?? '');

        if ($kind === 0 || $short === '') {
            return '';
        }

        $prefix = '';

        for ($above = $tree->parentOf($place), $seen = []; $above > 0 && ! isset($seen[$above]); $above = $tree->parentOf($above)) {
            $seen[$above] = true;

            if (($kinds[$above] ?? 0) === 0) {
                continue;
            }

            $prefix = $tree->shortOf($above) !== '' ? $tree->shortOf($above) : self::proposed($above, $tree, $kinds, $shorts);

            if ($prefix === '') {
                return '';
            }

            break;
        }

        $same    = array_values(array_filter($tree->childrenOf($tree->parentOf($place)), static fn (int $sibling): bool => ($kinds[$sibling] ?? 0) === $kind));
        $highest = 0;
        $open    = [];

        foreach ($same as $sibling) {
            if ($tree->shortOf($sibling) === '') {
                $open[] = $sibling;
            } elseif (preg_match('/(?:^|-)' . preg_quote($short, '/') . '(\d+)$/u', $tree->shortOf($sibling), $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        // By the title, not the name: the name carries the short mark itself, which would shift the order.
        usort($open, static fn (int $a, int $b): int => strnatcasecmp($tree->titleOf($a), $tree->titleOf($b)) ?: $a <=> $b);
        $number = $highest + (int) array_search($place, $open, true) + 1;

        return ($prefix === '' ? '' : $prefix . '-') . $short . $number;
    }
}
