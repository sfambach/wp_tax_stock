<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Whether a place of one kind fits into a place of another (D-1158): the outer size of the one against the inner size of
 * the other, each a Dimension of length, width and height. The kind it goes into chooses how that is calculated and how
 * loudly a misfit is said (D-1169).
 *
 * ```mermaid
 * flowchart LR
 *   B["Box: Außenabmessung"] --> R{"Passberechnung der Platzart darüber"}
 *   P["Platz darüber: Innenabmessung"] --> R
 *   R -- "passt nicht" --> M["Passmeldung: Info · Warnung · Fehler"]
 * ```
 *
 * ⚠️ *INFERRED: the settings of the kind it goes into count; none set is «Volumen» and «Warnung». Sizes in different
 * units, or a side left empty, give no verdict rather than a guess.*
 */
final class PlaceFit
{
    /**
     * @param array<int, array{0: list<float>, 1: string}> $outer  type id → [three sides, unit]
     * @param array<int, array{0: list<float>, 1: string}> $inner  type id → [three sides, unit]
     * @param array<int, FitRule|null>                     $rules  type id → its calculation; null: «Keine Prüfung»
     * @param array<int, FitLevel>                         $levels type id → its message
     */
    public function __construct(
        private readonly array $outer = [],
        private readonly array $inner = [],
        private readonly array $rules = [],
        private readonly array $levels = [],
    ) {
    }

    public static function from(Structure $s, Table $types, Table $dimensions, Table $amounts): self
    {
        $outer = $inner = $rules = $levels = [];
        $value = static fn (string $key): int => $s->hasValue($key) ? $s->value($key) : -1;

        foreach ($types->ids() as $type) {
            foreach (['type_outer' => &$outer, 'type_inner' => &$inner] as $field => &$into) {
                $size = $s->hasField($field) ? self::size($s, (int) $types->first($type, $s->field($field)), $dimensions, $amounts) : null;

                if ($size !== null) {
                    $into[$type] = $size;
                }
            }
            unset($into);

            $rule = $s->hasField('type_fit_rule') ? (int) $types->first($type, $s->field('type_fit_rule')) : 0;
            $rules[$type] = match ($rule) {
                $value('fit_none')  => null,
                $value('fit_sides') => new SidesFit(),
                default             => new VolumeFit(),
            };

            $level = $s->hasField('type_fit_level') ? (int) $types->first($type, $s->field('type_fit_level')) : 0;
            $levels[$type] = match ($level) {
                $value('level_info')  => FitLevel::Info,
                $value('level_error') => FitLevel::Error,
                default               => FitLevel::Warning,
            };
        }

        return new self($outer, $inner, $rules, $levels);
    }

    /** null where either size is missing, they are in different units, or the kind it goes into checks nothing. */
    public function fits(int $type, int $into): ?bool
    {
        $box   = $this->outer[$type] ?? null;
        $space = $this->inner[$into] ?? null;
        $rule  = array_key_exists($into, $this->rules) ? $this->rules[$into] : new VolumeFit();

        if ($rule === null || $box === null || $space === null || $box[1] !== $space[1]) {
            return null;
        }

        return $rule->fits($box[0], $space[0]);
    }

    public function levelOf(int $into): FitLevel
    {
        return $this->levels[$into] ?? FitLevel::Warning;
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
