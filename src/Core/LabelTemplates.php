<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The label templates kept as records (D-1153): each with its format's size, how many to print, and its layout as JSON —
 * read once, handed to the modeller's label dialog as it is.
 *
 * ```mermaid
 * flowchart LR
 *   PA["Platzart"] -- "Etikettenvorlage" --> V["Etikettenvorlage: Anzahl, Layout"]
 *   V -- "Format" --> F["Etikettenformat: Schlüssel, Breite, Höhe, Rolle"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1153
 */
final class LabelTemplates
{
    /**
     * @param array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string}> $templates
     */
    public function __construct(private readonly array $templates = [])
    {
    }

    public static function from(Structure $s, Table $formats, Table $templates): self
    {
        $int = static fn (Table $t, int $id, string $field): int => $s->hasField($field) ? (int) $t->first($id, $s->field($field)) : 0;
        $out = [];

        foreach ($templates->ids() as $id) {
            $format = $int($templates, $id, 'template_format');
            $key    = $s->hasField('format_key') ? trim((string) $formats->first($format, $s->field('format_key'))) : '';
            $layout = $s->hasField('template_layout') ? trim((string) $templates->first($id, $s->field('template_layout'))) : '';

            // A template without a size or a layout cannot print; it stays out rather than drawing an empty label.
            if (! $formats->has($format) || $key === '' || $layout === '' || $int($formats, $format, 'format_width') <= 0 || $int($formats, $format, 'format_height') <= 0) {
                continue;
            }

            $out[$id] = [
                'name'   => (string) ($templates->first($id, $s->field('label')) ?? '#' . $id),
                'format' => [
                    'key'  => $key,
                    'w'    => $int($formats, $format, 'format_width'),
                    'h'    => $int($formats, $format, 'format_height'),
                    'roll' => $int($formats, $format, 'format_roll'),
                ],
                'copies' => max(1, $int($templates, $id, 'template_copies')),
                'layout' => $layout,
            ];
        }

        return new self($out);
    }

    public function has(int $id): bool
    {
        return isset($this->templates[$id]);
    }

    /** @return array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string}|null */
    public function get(int $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    /**
     * The label a place of this type gets and how many: the type's own number, else its template's, else 2 for a box and 1
     * otherwise (D-1152). Without a template the modeller's fixed layout and format choice stand.
     *
     * @return array{0: array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string}|null, 1: int}
     */
    public function forPlace(PlaceTypes $types, int $type, bool $movable): array
    {
        $template = $type > 0 ? $this->get($types->labelTemplateOf($type)) : null;
        $copies   = $type > 0 ? $types->labelCopiesOf($type) : 0;

        return [$template, $copies > 0 ? $copies : ($template['copies'] ?? ($movable ? 2 : 1))];
    }

    /** @return array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string}> */
    public function all(): array
    {
        return $this->templates;
    }
}
