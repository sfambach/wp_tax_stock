<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The things the stock knows — those with a lot or a Lager record — with their names, groups and Lager records.
 *
 * D-995: the thing in the tree is the material; its number is its record id, its name its own «Bezeichnung».
 * Labels and nodes are read beforehand, one read per node (CD-7), and handed in.
 *
 * ```mermaid
 * flowchart LR
 *   P["Bestandsposten"] -->|Ding| D["Ding 43101: BC640"]
 *   L["Lager 43124"] -->|Ding| D
 *   D --> A["BC640 · Nr. 43101"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-995, D-997
 */
final class Things
{
    /** @var array<int, int> thing → its Lager record, the oldest if several name it */
    private array $lager = [];

    /**
     * @param array<int, string> $labels thing → its label
     * @param array<int, int>    $nodes  thing → the node it lives in
     */
    public function __construct(
        Structure $structure,
        Table $lagerRecords,
        private readonly array $labels,
        private readonly array $nodes,
    ) {
        $ids = $lagerRecords->ids();
        sort($ids);

        foreach ($ids as $record) {
            $thing = (int) $lagerRecords->first($record, $structure->field('lager_thing'));

            if ($thing > 0) {
                $this->lager[$thing] ??= $record;
            }
        }
    }

    /**
     * The things to read labels for: every one a Lager record or a lot names.
     *
     * @return list<int>
     */
    public static function known(Structure $structure, Table $lagerRecords, Ledger $ledger): array
    {
        $things = $ledger->things();

        foreach ($lagerRecords->ids() as $record) {
            $things[] = (int) $lagerRecords->first($record, $structure->field('lager_thing'));
        }

        return array_values(array_unique(array_filter($things)));
    }

    public function has(int $thing): bool
    {
        return isset($this->labels[$thing]);
    }

    public function word(int $thing): string
    {
        return $this->labels[$thing] ?? '';
    }

    /** The name with its number after it, where a choice list has no column for the number (D-988). */
    public function name(int $thing): string
    {
        $word = $this->word($thing);

        return ($word === '' ? '' : $word . ' ') . '(Nr. ' . $thing . ')';
    }

    public function nodeOf(int $thing): ?int
    {
        return $this->nodes[$thing] ?? null;
    }

    public function lagerOf(int $thing): ?int
    {
        return $this->lager[$thing] ?? null;
    }

    /** @return array<int, string> thing → «name (Nr. id)», sorted by name */
    public function all(): array
    {
        $out = [];

        foreach (array_keys($this->labels) as $thing) {
            $out[$thing] = $this->name($thing);
        }

        uksort($out, fn (int $a, int $b): int => [mb_strtolower($this->word($a)), $a] <=> [mb_strtolower($this->word($b)), $b]);

        return $out;
    }
}
