<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What a material is called: its number, and the name of the thing «Bezug» points at (D-967).
 *
 * Only a material without a reference has a name of its own. The names of the referenced things are read
 * beforehand, one read per node (CD-7), and handed in.
 *
 * ```mermaid
 * flowchart LR
 *   M["Material #43124"] -->|Bezug| D["Ding 43101: BC640"]
 *   M --> A["#43124 · BC640"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-967
 */
final class MaterialNames
{
    /** @param array<int, string> $thingLabels record id of a referenced thing → its label */
    public function __construct(
        private readonly Structure $structure,
        private readonly Table $materials,
        private readonly array $thingLabels,
    ) {
    }

    /**
     * The things the materials refer to, to read their labels in one go.
     *
     * @return list<int>
     */
    public static function referencedThings(Structure $structure, Table $materials): array
    {
        if (! $structure->hasField('material_ref_record')) {
            return [];
        }

        $things = [];

        foreach ($materials->ids() as $material) {
            $thing = (int) $materials->first($material, $structure->field('material_ref_record'));

            if ($thing > 0) {
                $things[$thing] = $thing;
            }
        }

        return array_values($things);
    }

    public function has(int $material): bool
    {
        return $this->materials->has($material);
    }

    public function thingOf(int $material): ?int
    {
        if (! $this->structure->hasField('material_ref_record')) {
            return null;
        }

        $thing = (int) $this->materials->first($material, $this->structure->field('material_ref_record'));

        return $thing > 0 ? $thing : null;
    }

    /** The name without the number: the referenced thing's label, else the material's own, else empty. */
    public function word(int $material): string
    {
        $thing = $this->thingOf($material);

        if ($thing !== null && ($this->thingLabels[$thing] ?? '') !== '') {
            return $this->thingLabels[$thing];
        }

        return (string) $this->materials->first($material, $this->structure->field('label'));
    }

    public function name(int $material): string
    {
        $word = $this->word($material);

        return '#' . $material . ($word === '' ? '' : ' · ' . $word);
    }

    /** @return array<int, string> material id → «#id · name», sorted by name */
    public function all(): array
    {
        $out = [];

        foreach ($this->materials->ids() as $material) {
            $out[$material] = $this->name($material);
        }

        uksort($out, fn (int $a, int $b): int => [mb_strtolower($this->word($a)), $a] <=> [mb_strtolower($this->word($b)), $b]);

        return $out;
    }

    /** The material that already stands for this thing — the oldest if several do. */
    public function forThing(int $thing): ?int
    {
        $found = array_filter($this->materials->ids(), fn (int $m): bool => $this->thingOf($m) === $thing);

        return $found === [] ? null : min($found);
    }
}
