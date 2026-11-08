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
 *   E -. "Rolle (D-1191)" .-> D["Drucker: Warteschlange, Rolle, Drehen, Optionen, Seitengrößen, Versatz"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1159
 */
final class LabelTemplates
{
    /**
     * @param array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}> $templates
     */
    /**
     * @param array<int, array<string, mixed>> $printers D-1192: printer id ⇒ what the label dialog takes, with `allows` (label ids)
     */
    public function __construct(private readonly array $templates = [], private readonly array $printers = [])
    {
    }

    /**
     * @param array<int, string> $categoryOf record id → the name of the category (sub-node of «Etiketten») it stands in
     * @param array<int, int>    $nodeOf     record id → the node it stands in
     * @param Table|null         $printers   D-1191: the records of «Drucker», read once beside the labels
     * @param Table|null         $rolls      D-1192: the records of «Rollen» — a printer holds one, a roll allows its labels
     */
    public static function from(Structure $s, Table $labels, array $categoryOf = [], array $nodeOf = [], ?Table $printers = null, ?Table $rolls = null): self
    {
        $int  = static fn (int $id, string $field): int => $s->hasField($field) ? (int) $labels->first($id, $s->field($field)) : 0;
        $text = static fn (int $id, string $field): string => $s->hasField($field) ? trim((string) $labels->first($id, $s->field($field))) : '';
        $out  = [];
        $all    = self::printers($s, $printers, $rolls);

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
            ];
            // D-1192: the first printer (by name) whose loaded roll allows this label is the one the dialog starts with.
            foreach ($all as $printer) {
                if (in_array($id, $printer['allows'], true)) {
                    $out[$id]['printer'] = array_diff_key($printer, ['allows' => 1, 'roll_name' => 1]);
                    break;
                }
            }
        }

        return new self($out, $all);
    }

    /**
     * D-1191, D-1192: every printer with a queue and a loaded roll, by name — its settings as the modeller's label dialog takes
     * them, the width of the roll it holds, and the labels that roll allows.
     *
     * @return array<int, array{queue: string, roll: int, turn: bool, options: string, turn_options: string, sizes: string, offset: int, line: int, retry: bool, name: string, roll_name: string, allows: list<int>}>
     */
    private static function printers(Structure $s, ?Table $printers, ?Table $rolls): array
    {
        if ($printers === null || $rolls === null || ! $s->hasField('printer_queue') || ! $s->hasField('printer_loaded_roll') || ! $s->hasField('roll_labels')) {
            return [];
        }
        $text = static fn (Table $t, int $id, string $field): string => $s->hasField($field) ? trim((string) $t->first($id, $s->field($field))) : '';
        $out  = [];
        foreach ($printers->ids() as $id) {
            $queue = $text($printers, $id, 'printer_queue');
            $roll  = (int) $text($printers, $id, 'printer_loaded_roll');
            // D-1231: his wish 2026-11-08 «Drucker deaktivieren» — a printer switched off is not offered.
            if ($queue === '' || $roll <= 0 || ! $rolls->has($roll) || $text($printers, $id, 'printer_active') === '0') {
                continue;
            }
            $out[$id] = [
                'queue'        => $queue,
                'roll'         => (int) $text($rolls, $roll, 'roll_width'),
                'turn'         => $text($printers, $id, 'printer_turns') === '1',
                'options'      => $text($printers, $id, 'printer_options'),
                'turn_options' => $text($printers, $id, 'printer_turn_options'),
                'sizes'        => $text($printers, $id, 'printer_sizes'),
                'offset'       => (int) $text($printers, $id, 'printer_offset'),
                'line'         => (int) $text($printers, $id, 'printer_line'),
                'retry'        => $text($printers, $id, 'printer_retry') === '1',
                'name'         => trim((string) ($printers->first($id, $s->field('label')) ?? '')),
                'roll_name'    => trim((string) ($rolls->first($roll, $s->field('label')) ?? '')),
                'allows'       => array_values(array_map('intval', $rolls->all($roll, $s->field('roll_labels')))),
            ];
        }
        uasort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * D-1192: the label dialog for a label of this category — the chosen label first, the others of its category to choose from,
     * and every printer with the labels its loaded roll allows; `$more` (record, values) goes with each label.
     *
     * D-1199: `$contents` holds the names of what lies on the place; each label takes the ones its choice asks for.
     *
     * @param array<string, mixed> $more
     * @param array{categories?: list<string>, materials?: list<string>, words?: array<string, string>} $contents
     * @return array<string, mixed>
     */
    public function dialog(int $chosen, array $more = [], array $contents = []): array
    {
        $template = $this->templates[$chosen] ?? null;
        if ($template === null) {
            return [];
        }
        $choices = [];
        foreach ($this->inNode((int) ($template['node'] ?? 0)) ?: [$chosen] as $id) {
            $choices[$id] = self::forButton(array_diff_key($this->templates[$id], ['printer' => 1]), self::withContents($this->templates[$id], $more, $contents));
        }

        return self::forButton($template, self::withContents($template, $more, $contents)) + ['choices' => $choices, 'chosen' => $chosen, 'printers' => $this->printers];
    }

    /**
     * D-1199, his choice «Auswahl im Druckdialog»: a label whose layout names `inhalt` offers what to print there — nothing, the
     * categories or the materials — each shortened to what its layout holds; the dialog starts on the categories.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $more
     * @param array{categories?: list<string>, materials?: list<string>, words?: array<string, string>} $contents
     * @return array<string, mixed>
     */
    private static function withContents(array $template, array $more, array $contents): array
    {
        $layout = (string) ($template['layout'] ?? '');
        if (! isset($contents['categories']) || ! LabelContents::named($layout)) {
            return $more;
        }
        $max   = LabelContents::maxOf($layout);
        $words = $contents['words'] ?? [];
        $more2 = (string) ($words['more'] ?? '+%d');

        return $more + ['variants' => [
            'field'   => LabelContents::FIELD,
            'label'   => (string) ($words['label'] ?? LabelContents::FIELD),
            'chosen'  => LabelContents::CATEGORIES,
            'options' => [
                LabelContents::NOTHING    => ['name' => (string) ($words[LabelContents::NOTHING] ?? '—'), 'text' => ''],
                LabelContents::CATEGORIES => ['name' => (string) ($words[LabelContents::CATEGORIES] ?? LabelContents::CATEGORIES), 'text' => LabelContents::line($contents['categories'] ?? [], $max, $more2)],
                LabelContents::MATERIALS  => ['name' => (string) ($words[LabelContents::MATERIALS] ?? LabelContents::MATERIALS), 'text' => LabelContents::line($contents['materials'] ?? [], $max, $more2)],
            ],
        ]];
    }

    /** @return list<int> the labels of one category, by name; none for 0 */
    private function inNode(int $node): array
    {
        $names = [];
        foreach ($this->templates as $id => $template) {
            if ($node > 0 && ($template['node'] ?? 0) === $node) {
                $names[$id] = $template['name'];
            }
        }
        natcasesort($names);

        return array_keys($names);
    }

    /** @return array<int, array<string, mixed>> D-1192: the printers, for the list under Druck › Drucker */
    public function printerList(): array
    {
        return $this->printers;
    }

    /**
     * What the modeller's label button takes of a label (D-1153, D-1191): name, size, layout and, where one holds its roll, its printer.
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

    /**
     * D-1160: the first label (by name) of a category; 0 where it holds none that can print. D-1192: a label some printer's loaded
     * roll allows goes before one none does.
     */
    public function firstIn(int $node): int
    {
        $ids = $this->inNode($node);
        foreach ($ids as $id) {
            if (isset($this->templates[$id]['printer'])) {
                return $id;
            }
        }

        return $ids[0] ?? 0;
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
