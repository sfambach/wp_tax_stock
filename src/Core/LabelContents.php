<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1199: what a place label can print about what lies there — nothing, the categories of the materials, or the materials
 * themselves, shortened to what the label has room for; chosen in the print dialog.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1199
 */
final class LabelContents
{
    public const FIELD = 'inhalt';

    public const NOTHING    = '';
    public const CATEGORIES = 'categories';
    public const MATERIALS  = 'materials';

    /** How many names a layout element takes when it does not say: one per line it allows, else six. */
    private const DEFAULT_MAX = 6;

    /**
     * @param list<string> $names
     * @param string       $more  the remainder, `%d` the number left out — the boundary's words (AR-2)
     */
    public static function line(array $names, int $max, string $more = '+%d'): string
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), static fn (string $name): bool => $name !== '')));
        natcasesort($names);
        $names = array_values($names);
        $max   = max(1, $max);

        if (count($names) <= $max) {
            return implode(' · ', $names);
        }

        // ⚠️ *The last place goes to the remainder, so the label never claims to show everything when it does not.*
        $shown = array_slice($names, 0, $max - 1);

        return implode(' · ', $shown) . ' · ' . sprintf($more, count($names) - count($shown));
    }

    /** Whether the layout has a place for the content at all. */
    public static function named(string $layout): bool
    {
        return self::element($layout) !== null;
    }

    /** The most names the layout's «inhalt» element takes: its `max`, else its `lines`, else six. */
    public static function maxOf(string $layout): int
    {
        $element = self::element($layout);

        return max(1, (int) ($element['max'] ?? $element['lines'] ?? self::DEFAULT_MAX));
    }

    /** @return array<string, mixed>|null the layout's «inhalt» element */
    private static function element(string $layout): ?array
    {
        try {
            $elements = json_decode($layout, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        foreach (is_array($elements) ? $elements : [] as $element) {
            if (is_array($element) && ($element['field'] ?? '') === self::FIELD) {
                return $element;
            }
        }

        return null;
    }
}
