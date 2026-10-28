<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The result of testing a lot after the goods receipt (D-1002); «Teilweise OK» and «Wartung nötig» need a reason.
 *
 * Each case names the key of its value in the structure: the constants are choices of the lot's «Zustand» in the model
 * («funktioniert», «teilweise OK», «Wartung nötig», «defekt»), not names in code.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC7
 */
enum TestResult: string
{
    case Ok          = 'test_ok';
    case Partial     = 'test_partial';
    case Maintenance = 'test_maintenance';
    case Defect      = 'test_defect';

    public function needsReason(): bool
    {
        return $this === self::Partial || $this === self::Maintenance;
    }

    /** The result a stored value stands for, or null for none or one not configured. */
    public static function ofValue(Structure $s, ?string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($s->hasValue($case->value) && (string) $s->value($case->value) === (string) $value) {
                return $case;
            }
        }

        return null;
    }
}
