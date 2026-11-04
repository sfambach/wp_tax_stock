<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The labels kept as records (D-1153, D-1157, D-1159): each with its size, its roll, how many to print and its layout as JSON —
 * read once, handed to the modeller's label dialog as it is.
 *
 * ⚠️ *His word 2026-11-04: «Eigentlich hätte ich erwartet, dass wir einfach nur Etiketten haben» — one record per label; the
 * separate format records are gone (D-1159).*
 *
 * ```mermaid
 * flowchart LR
 *   PA["Platzart"] -- "Etikett" --> E["Etikett: Druckformat, Breite, Höhe, Rolle, Anzahl, Layout"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1159
 */
final class LabelTemplates
{
    /**
     * @param array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string}> $templates
     */
    public function __construct(private readonly array $templates = [])
    {
    }

    /** @param array<int, string> $categoryOf record id → the name of the category (sub-node of «Etiketten») it stands in */
    public static function from(Structure $s, Table $labels, array $categoryOf = []): self
    {
        $int  = static fn (int $id, string $field): int => $s->hasField($field) ? (int) $labels->first($id, $s->field($field)) : 0;
        $text = static fn (int $id, string $field): string => $s->hasField($field) ? trim((string) $labels->first($id, $s->field($field))) : '';
        $out  = [];

        foreach ($labels->ids() as $id) {
            $key    = $text($id, 'template_key');
            $layout = $text($id, 'template_layout');

            // A label without a size or a layout cannot print; it stays out rather than drawing an empty one.
            if ($key === '' || $layout === '' || $int($id, 'template_width') <= 0 || $int($id, 'template_height') <= 0) {
                continue;
            }

            $out[$id] = [
                'name'   => (string) ($labels->first($id, $s->field('label')) ?? '#' . $id),
                'format' => ['key' => $key, 'w' => $int($id, 'template_width'), 'h' => $int($id, 'template_height'), 'roll' => $int($id, 'template_roll')],
                'copies' => max(1, $int($id, 'template_copies')),
                'layout' => $layout,
                'category' => $categoryOf[$id] ?? '',
            ];
        }

        return new self($out);
    }

    public function has(int $id): bool
    {
        return isset($this->templates[$id]);
    }

    /** @return array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string}|null */
    public function get(int $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    /**
     * The label a place of this type gets and how many: the type's own number, else its template's, else 2 for a box and 1
     * otherwise (D-1152). Without a template the modeller's fixed layout and format choice stand.
     *
     * @return array{0: array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string}|null, 1: int}
     */
    public function forPlace(PlaceTypes $types, int $type, bool $movable): array
    {
        $template = $type > 0 ? $this->get($types->labelTemplateOf($type)) : null;
        $copies   = $type > 0 ? $types->labelCopiesOf($type) : 0;

        return [$template, $copies > 0 ? $copies : ($template['copies'] ?? ($movable ? 2 : 1))];
    }

    /** @return array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string}> */
    public function all(): array
    {
        return $this->templates;
    }
}
