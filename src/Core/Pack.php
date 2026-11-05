<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1178: a material counted in small pieces but handled in packs — pins of a pin header, sold and cut as strips of 40.
 * The stock stays in pieces; a pack only changes how it is shown and how a goods receipt counts.
 *
 * ```mermaid
 * flowchart LR
 *   R["Wareneingang: 3 Leisten"] -- "× 40" --> S["Bestand: 120 Pins"]
 *   S -- "Anzeige" --> A["3 × Leiste + 0"]
 *   B["Bestücken: 2 × 20 Pins"] -- "+ 1 Verlust je Schnitt" --> S
 * ```
 */
final class Pack
{
    public function __construct(public readonly string $name, public readonly int $content, public readonly int $lossPerCut = 0)
    {
    }

    /** null where the Lager view names no content larger than one piece: such a material is not handled in packs. */
    public static function of(Structure $s, Table $lager, ?int $record): ?self
    {
        if ($record === null || ! $s->hasField('lager_pack_content')) {
            return null;
        }

        $content = (int) $lager->first($record, $s->field('lager_pack_content'));

        if ($content < 2) {
            return null;
        }

        $name = $s->hasField('lager_pack_name') ? trim((string) $lager->first($record, $s->field('lager_pack_name'))) : '';
        $loss = $s->hasField('lager_cut_loss') ? max(0, (int) $lager->first($record, $s->field('lager_cut_loss'))) : 0;

        return new self($name, $content, $loss);
    }

    /** @return array{0: int, 1: float} whole packs and the rest in pieces */
    public function split(float $pieces): array
    {
        $packs = (int) floor($pieces / $this->content + 1e-9);

        return [$packs, round($pieces - $packs * $this->content, 6)];
    }
}
