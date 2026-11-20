<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1535 (P4): the round-contact strip (Präzisionsleiste) that stands in for a plain one — same kind after D-1328 (node,
 * rows, pitch, orientation, Bauform), «Kontaktart» Rundkontakt where the plain one has none (D-1390). The same number of
 * poles first, else the shortest longer one, which is cut (D-1398). A strip without a kind gets no counterpart.
 *
 * ```mermaid
 * flowchart LR
 *   E["eckig 1×40, 2,54, gerade"] --> K{"gleiche Art, Kontaktart Rundkontakt?"}
 *   R["rund 1×40, 2,54, gerade"] --> K
 *   K -- "gleiche Pole, sonst kürzeste längere" --> G["Gegenstück"]
 * ```
 */
final class RoundContact
{
    /**
     * @param list<int>               $wanted  the plain strips asked for
     * @param array<int, string|null> $kinds   thing → kind key (CutSources::kindOf), null without one
     * @param array<int, int>         $poles   thing → poles
     * @param array<int, string|null> $contact thing → its «Kontaktart», null for none
     * @return array<int, int> plain strip → its round counterpart, only where there is one
     */
    public static function counterparts(array $wanted, array $kinds, array $poles, array $contact, string $round): array
    {
        $out = [];

        foreach ($wanted as $thing) {
            $kind = $kinds[$thing] ?? null;
            $need = $poles[$thing] ?? 0;

            if ($kind === null || $need < 1 || ($contact[$thing] ?? null) === $round) {
                continue;
            }

            $best = null;

            foreach ($kinds as $other => $otherKind) {
                $have = $poles[$other] ?? 0;

                if ($other === $thing || $otherKind !== $kind || ($contact[$other] ?? null) !== $round || $have < $need) {
                    continue;
                }

                // Equal poles first, then the shortest longer; among equals the lower id.
                $key = [$have === $need ? 0 : 1, $have, $other];

                if ($best === null || $key < $best[1]) {
                    $best = [$other, $key];
                }
            }

            if ($best !== null) {
                $out[$thing] = $best[0];
            }
        }

        return $out;
    }
}
