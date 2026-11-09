<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A purchase's picture into its material (D-1264): the same media reference, no file copied — automatically while the
 * material has none, on request appended behind its pictures, never twice.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md
 */
final class PurchasePictures
{
    private const SCHEME = 'media:';

    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * The material of each purchase that has no picture yet gets the purchase's title picture as its first (D-1205).
     *
     * @param array<int, string>                                        $chosen    purchase → thing id, or the ref of a new one
     * @param array<int, array{title: string|null, pictures: list<string>}> $purchases purchase → its picture fields
     * @param array<int, array{title: string|null, pictures: list<string>}> $things    thing → its picture fields; a new thing has none
     * @return list<array<string, mixed>>
     */
    public function firstPictures(array $chosen, array $purchases, array $things): array
    {
        if (! $this->structure->hasField('images')) {
            return [];
        }

        $changes = [];
        $filled  = [];

        foreach ($chosen as $purchase => $thing) {
            $picture = self::titleOf($purchases[$purchase] ?? null);
            $known   = ctype_digit($thing) ? ($things[(int) $thing] ?? null) : null;

            if ($picture === 0 || isset($filled[$thing]) || self::titleOf($known) > 0) {
                continue;
            }

            $filled[$thing] = true;
            $changes[]      = ['op' => 'set_values', 'record' => $thing, 'values' => [(string) $this->structure->field('images') => ['values' => [self::SCHEME . $picture]]]];
        }

        return $changes;
    }

    /**
     * «Bild übernehmen»: the purchase's title picture behind the material's pictures; nothing when it is there already.
     *
     * @param array{title: string|null, pictures: list<string>} $purchase
     * @param array{title: string|null, pictures: list<string>} $thing
     * @return list<array<string, mixed>>
     */
    public function append(int $thingId, array $purchase, array $thing): array
    {
        $picture = self::titleOf($purchase);

        if ($picture === 0 || ! $this->structure->hasField('images') || self::holds($thing, $picture)) {
            return [];
        }

        return [['op' => 'set_values', 'record' => (string) $thingId, 'values' => [(string) $this->structure->field('images') => ['values' => [...$thing['pictures'], self::SCHEME . $picture]]]]];
    }

    /** @param array{title: string|null, pictures: list<string>} $thing */
    public static function holds(array $thing, int $picture): bool
    {
        return $picture > 0 && in_array(self::SCHEME . $picture, [(string) $thing['title'], ...$thing['pictures']], true);
    }

    /** @param array{title: string|null, pictures: list<string>}|null $record */
    public static function titleOf(?array $record): int
    {
        return $record === null ? 0 : TitlePicture::of($record['title'], $record['pictures']);
    }
}
