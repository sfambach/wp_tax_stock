<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Whether a place of one kind fits into a place of another (D-1158): the outer size of the one against the inner size of
 * the other, each a Dimension of length, width and height.
 *
 * ```mermaid
 * flowchart LR
 *   B["Box: Außenabmessung"] -- "≤ in every direction" --> P["Platz darüber: Innenabmessung"]
 * ```
 *
 * ⚠️ *INFERRED: a box may be turned, so both sizes are compared sorted, largest against largest. Sizes in different
 * units, or a side left empty, give no verdict rather than a guess.*
 */
final class PlaceFit
{
    /**
     * @param array<int, array{0: list<float>, 1: string}> $outer type id → [three sides, unit]
     * @param array<int, array{0: list<float>, 1: string}> $inner type id → [three sides, unit]
     */
    public function __construct(
        private readonly array $outer = [],
        private readonly array $inner = [],
    ) {
    }

    public static function from(Structure $s, Table $types, Table $dimensions, Table $amounts): self
    {
        $outer = $inner = [];

        foreach ($types->ids() as $type) {
            foreach (['type_outer' => &$outer, 'type_inner' => &$inner] as $field => &$into) {
                $size = $s->hasField($field) ? self::size($s, (int) $types->first($type, $s->field($field)), $dimensions, $amounts) : null;

                if ($size !== null) {
                    $into[$type] = $size;
                }
            }
            unset($into);
        }

        return new self($outer, $inner);
    }

    /** null where either size is missing or they are given in different units. */
    public function fits(int $type, int $into): ?bool
    {
        $box   = $this->outer[$type] ?? null;
        $space = $this->inner[$into] ?? null;

        if ($box === null || $space === null || $box[1] !== $space[1]) {
            return null;
        }

        [$a, $b] = [$box[0], $space[0]];
        rsort($a);
        rsort($b);

        foreach ($a as $i => $side) {
            if ($side > $b[$i]) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: list<float>, 1: string}|null */
    private static function size(Structure $s, int $dimension, Table $dimensions, Table $amounts): ?array
    {
        if ($dimension <= 0 || ! $s->hasField('dimension_length') || ! $s->hasField('dimension_width') || ! $s->hasField('dimension_height')) {
            return null;
        }

        $sides = [];
        $units = [];

        foreach (['dimension_length', 'dimension_width', 'dimension_height'] as $field) {
            $part   = (int) $dimensions->first($dimension, $s->field($field));
            $amount = $part > 0 ? $amounts->first($part, $s->field('money_amount')) : null;

            if ($amount === null || trim($amount) === '' || ! is_numeric(str_replace(',', '.', trim($amount)))) {
                return null;
            }

            $sides[] = (float) str_replace(',', '.', trim($amount));
            $units[] = (string) $amounts->first($part, $s->field('money_unit'));
        }

        return count(array_unique($units)) === 1 ? [$sides, $units[0]] : null;
    }
}
