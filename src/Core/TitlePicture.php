<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which picture is a record's title picture (D-1205): the first of its ordered picture list; the old single field
 * «Titelbild» wins while it is still filled, so the switch works before and after the migration.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/umbau-bildliste.md
 */
final class TitlePicture
{
    private const SCHEME = 'media:';

    /** @param list<string> $pictures the values of «Bilder», in their stored order */
    public static function of(?string $titleField, array $pictures): int
    {
        foreach ([(string) $titleField, ...$pictures] as $value) {
            $id = str_starts_with($value, self::SCHEME) ? (int) substr($value, strlen(self::SCHEME)) : 0;

            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }
}
