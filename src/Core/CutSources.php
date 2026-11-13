<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1328 (D9): which longer strips in stock a missing strip can be cut from — same kind, more poles.
 *
 * «Same kind» is the same node (Stiftleiste or Buchsenleiste), the same rows (none = 1), the same pitch, the same
 * orientation and the same Bauform (so a plain strip never stands in for a stacking one). A strip without a pitch or an
 * orientation is never matched: better no proposal than a wrong cut.
 *
 * ```mermaid
 * flowchart LR
 *   W["fehlt: 1×8, 2,54, gerade"] --> K{"gleiche Art?"}
 *   L["Bestand: 1×40, 2,54, gerade"] --> K
 *   K -- "mehr Pole" --> V["Vorschlag: aus 1×40 ablängen"]
 * ```
 */
final class CutSources
{
    /**
     * @param array<int, string|null> $kinds thing → its kind key, null where it lacks pitch or orientation
     * @param array<int, int>         $poles thing → its number of poles
     */
    public function __construct(private readonly array $kinds, private readonly array $poles)
    {
    }

    /**
     * The kind key of a strip: node, rows, pitch, orientation, Bauform; null without pitch or orientation.
     *
     * @param string|null $rows  the rows' unit value as a canonical string, null for none
     * @param string|null $pitch the pitch's unit value as a canonical string
     */
    public static function kindOf(int $node, ?string $rows, ?string $pitch, ?string $orientation, ?string $form): ?string
    {
        if ($pitch === null || $pitch === '' || $orientation === null || $orientation === '') {
            return null;
        }

        return implode('|', [$node, $rows === null || $rows === '' ? '1' : $rows, $pitch, $orientation, $form ?? '']);
    }

    /**
     * @param array<int, float>                     $wanted thing → missing pieces
     * @param array<int, array<int, array<int, float>>> $stock  thing → lot → place → what lies there
     * @return array<int, list<array{thing: int, pins: int, lot: int, place: int, left: float}>>
     */
    public function for(array $wanted, array $stock, int $except = 0): array
    {
        $out = [];

        foreach ($wanted as $thing => $pieces) {
            $kind = $this->kinds[$thing] ?? null;
            $need = $this->poles[$thing] ?? 0;

            if ($kind === null || $need < 1 || $pieces <= 0) {
                continue;
            }

            foreach ($stock as $source => $lots) {
                if ($source === $thing || ($this->kinds[$source] ?? null) !== $kind || ($this->poles[$source] ?? 0) <= $need) {
                    continue;
                }

                foreach ($lots as $lot => $places) {
                    foreach ($places as $place => $left) {
                        if ($left > 1e-9 && ($except === 0 || $place !== $except)) {
                            $out[$thing][] = ['thing' => $source, 'pins' => $this->poles[$source], 'lot' => $lot, 'place' => $place, 'left' => $left];
                        }
                    }
                }
            }

            if (isset($out[$thing])) {
                // The shortest strip that is long enough first: least waste.
                usort($out[$thing], static fn (array $a, array $b): int => [$a['pins'], -$a['left']] <=> [$b['pins'], -$b['left']]);
            }
        }

        return $out;
    }
}
