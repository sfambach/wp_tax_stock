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
 *   PA["Platzart"] -- "Etikett" --> E["Etikett: Druckformat, Breite, Höhe, Rolle, Anzahl, Layout, Druckoptionen"]
 *   E -- "Drucker (D-1190)" --> D["Drucker: Warteschlange, Etikett drehen"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1159
 */
final class LabelTemplates
{
    /**
     * @param array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}> $templates
     */
    public function __construct(private readonly array $templates = [])
    {
    }

    /**
     * @param array<int, string> $categoryOf record id → the name of the category (sub-node of «Etiketten») it stands in
     * @param array<int, int>    $nodeOf     record id → the node it stands in
     * @param Table|null         $printers   D-1190: the records of «Drucker», read once beside the labels
     */
    public static function from(Structure $s, Table $labels, array $categoryOf = [], array $nodeOf = [], ?Table $printers = null): self
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
                'node'     => $nodeOf[$id] ?? 0,
            ] + self::printerOf($s, $printers, $int($id, 'template_printer'), $text($id, 'template_options'));
        }

        return new self($out);
    }

    /**
     * D-1190: the printer a label names, as the modeller's label dialog takes it; nothing where it names none or the printer has
     * no queue — the print service then chooses by roll width as before.
     *
     * @return array{printer?: array{queue: string, options: string, turn: bool, name: string, offset: int, line: int}}
     */
    private static function printerOf(Structure $s, ?Table $printers, int $printer, string $options): array
    {
        if ($printers === null || $printer <= 0 || ! $s->hasField('printer_queue') || ! $printers->has($printer)) {
            return [];
        }
        $queue = trim((string) $printers->first($printer, $s->field('printer_queue')));
        if ($queue === '') {
            return [];
        }

        return ['printer' => [
            'queue'   => $queue,
            'options' => $options,
            'turn'    => $s->hasField('printer_turns') && $printers->first($printer, $s->field('printer_turns')) === '1',
            'name'    => trim((string) ($printers->first($printer, $s->field('label')) ?? '')),
            'offset'  => $s->hasField('printer_offset') ? (int) $printers->first($printer, $s->field('printer_offset')) : 0,
            'line'    => $s->hasField('printer_line') ? (int) $printers->first($printer, $s->field('printer_line')) : 0,
        ]];
    }

    /**
     * What the modeller's label button takes of a label (D-1153, D-1190): name, size, layout and, where it names one, its printer.
     *
     * @param array{name: string, format: array{key: string, w: int, h: int, roll: int}, layout: string, printer?: array<string, mixed>} $template
     * @param array<string, mixed> $more record, values …
     * @return array<string, mixed>
     */
    public static function forButton(array $template, array $more = []): array
    {
        return ['name' => $template['name'], 'format' => $template['format'], 'layout' => $template['layout']]
            + (isset($template['printer']) ? ['printer' => $template['printer']] : []) + $more;
    }

    /** D-1160: the first label (by name) of a category; 0 where it holds none that can print. */
    public function firstIn(int $node): int
    {
        $names = [];
        foreach ($this->templates as $id => $template) {
            if ($node > 0 && ($template['node'] ?? 0) === $node) {
                $names[$id] = $template['name'];
            }
        }
        natcasesort($names);

        return (int) (array_key_first($names) ?? 0);
    }

    public function has(int $id): bool
    {
        return isset($this->templates[$id]);
    }

    /** @return array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}|null */
    public function get(int $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    /**
     * The label a place of this type gets and how many: the type's own number, else its template's, else 2 for a box and 1
     * otherwise (D-1152). Without a template the modeller's fixed layout and format choice stand.
     *
     * @return array{0: array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}|null, 1: int}
     */
    public function forPlace(PlaceTypes $types, int $type, bool $movable): array
    {
        $template = $type > 0 ? $this->get($types->labelTemplateOf($type)) : null;
        $copies   = $type > 0 ? $types->labelCopiesOf($type) : 0;

        return [$template, $copies > 0 ? $copies : ($template['copies'] ?? ($movable ? 2 : 1))];
    }

    /** @return array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}> */
    public function all(): array
    {
        return $this->templates;
    }
}
