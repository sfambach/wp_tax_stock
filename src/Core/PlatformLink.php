<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1249: a link to an order or a product, built from the shop's template with `{nr}` when it is shown — never stored.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md
 */
final class PlatformLink
{
    public const NUMBER = '{nr}';

    /** The template with the number put in; null without a template, without the placeholder or without a number. */
    public static function filled(string $template, string $number): ?string
    {
        $template = trim($template);
        $number   = trim($number);

        if ($template === '' || $number === '' || ! str_contains($template, self::NUMBER)) {
            return null;
        }

        return str_replace(self::NUMBER, rawurlencode($number), $template);
    }
}
