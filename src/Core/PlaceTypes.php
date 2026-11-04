<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The kinds of storage place (D-1123): a cabinet «Alex» with nine drawers «Schublade Alex», a box of one make — what a
 * place of that kind is (movable or not) and which places it brings along when it is created.
 *
 * ```mermaid
 * flowchart LR
 *   A["Platzart IKEA Alex"] -- "Teile: 9 ×" --> S["Platzart Schublade Alex"]
 *   P["Lagerort Alex 1"] -- "Platzart" --> A
 *   D["Lagerort A1-S1 … S9"] -- "Platzart" --> S
 * ```
 */
final class PlaceTypes
{
    /** A part list deeper than this is a loop in the model, not a cabinet. */
    private const DEPTH = 4;

    /**
     * @param array<int, string>                       $names   type id → its name
     * @param array<int, bool>                         $movable type id → whether a place of it moves (a box)
     * @param array<int, list<array{0: int, 1: int, 2?: string}>> $parts type id → [part type, count, unit name]
     */
    public function __construct(
        private readonly array $names = [],
        private readonly array $movable = [],
        private readonly array $parts = [],
    ) {
    }

    public static function from(Structure $s, Table $types, Table $parts): self
    {
        $names = $movable = $lists = [];

        foreach ($types->ids() as $id) {
            $names[$id]   = (string) ($types->first($id, $s->field('label')) ?? '#' . $id);
            $movable[$id] = $s->hasField('type_movable') && $types->first($id, $s->field('type_movable')) === '1';
            $lists[$id]   = [];

            if (! $s->hasField('type_parts') || ! $s->hasField('part_type')) {
                continue;
            }

            foreach ($types->all($id, $s->field('type_parts')) as $part) {
                $type  = (int) $parts->first((int) $part, $s->field('part_type'));
                $count = $s->hasField('part_count') ? (int) $parts->first((int) $part, $s->field('part_count')) : 1;

                // D-1151: an optional unit name for the places this line brings; empty, they take the part type's name.
                $unit = $s->hasField('part_unit_name') ? trim((string) $parts->first((int) $part, $s->field('part_unit_name'))) : '';

                if ($type > 0 && $count > 0) {
                    $lists[$id][] = $unit === '' ? [$type, $count] : [$type, $count, $unit];
                }
            }
        }

        return new self($names, $movable, $lists);
    }

    public function has(int $type): bool
    {
        return isset($this->names[$type]);
    }

    /** @return array<int, string> type id → name, sorted by name */
    public function names(): array
    {
        $out = $this->names;
        natcasesort($out);

        return $out;
    }

    /** null where the type is unknown, so the place's own mark stands */
    public function isMovable(int $type): ?bool
    {
        return $this->movable[$type] ?? null;
    }

    /** @return list<array{0: int, 1: int, 2?: string}> [part type, count, unit name where one is set] */
    public function partsOf(int $type): array
    {
        return $this->parts[$type] ?? [];
    }

    /**
     * Every place a new place of this type brings along, nested: «Schublade Alex 1» … «Schublade Alex 9».
     *
     * @return list<array{type: int, name: string, below: list<array<string, mixed>>}>
     */
    public function brought(int $type, int $depth = 0): array
    {
        if ($depth >= self::DEPTH) {
            throw StockError::placeTypeLoop($type);
        }

        $out = [];

        foreach ($this->partsOf($type) as $line) {
            [$part, $count] = $line;
            // D-1151, his word 2026-11-04: «Schublade Alex 1 … und das Regal heißt schon Alex 1 … Deswegen möchte ich da in der Zeile
            // optional gerne einen Einheitsnamen … Wenn der nicht gesetzt wird, wird einfach die Platzart genommen.»
            $unit = $line[2] ?? ($this->names[$part] ?? '#' . $part);

            for ($n = 1; $n <= $count; $n++) {
                $out[] = ['type' => $part, 'name' => $unit . ' ' . $n, 'below' => $this->brought($part, $depth + 1)];
            }
        }

        return $out;
    }

    public function nameOf(int $type): string
    {
        return $this->names[$type] ?? '#' . $type;
    }
}
