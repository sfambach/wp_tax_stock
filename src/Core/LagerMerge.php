<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The stock's answer when the modeller merges a doubled material (D-1480): the Lager record of `from` goes to the trash with it,
 * unless it holds a Mindestbestand, Einheit or Lagerort-Vorgabe that the Lager record of `into` holds differently — then a veto
 * naming them. Without a Lager record of its own, `into` takes over the one of `from` through the repointed reference.
 *
 * @see docs/pakete/varianten/plan.md
 */
final class LagerMerge
{
    private const COMPARED = ['lager_min' => 'Mindestbestand', 'lager_unit' => 'Einheit', 'lager_location' => 'Lagerort-Vorgabe'];

    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return array{veto?: string, trash?: list<int>} */
    public function answer(int $from, int $into, Table $lager): array
    {
        $s    = $this->structure;
        $mine = static fn (int $thing): array => array_values(array_filter(
            $lager->ids(),
            static fn (int $record): bool => (int) $lager->first($record, $s->field('lager_thing')) === $thing
        ));
        $fromLager = $mine($from);
        $intoLager = $mine($into);

        if ($fromLager === [] || $intoLager === []) {
            return [];
        }

        $differs = [];

        foreach (self::COMPARED as $key => $word) {
            if (! $s->hasField($key)) {
                continue;
            }

            $kept = $lager->all($intoLager[0], $s->field($key));

            foreach ($fromLager as $record) {
                $held = $lager->all($record, $s->field($key));

                if ($held !== [] && $held !== $kept) {
                    $differs[$word] = true;
                }
            }
        }

        return $differs === []
            ? ['trash' => $fromLager]
            : ['veto' => sprintf('Lager-Stammsatz #%d und #%d: %s weichen ab.', $fromLager[0], $intoLager[0], implode(', ', array_keys($differs)))];
    }
}
