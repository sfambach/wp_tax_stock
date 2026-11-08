<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use Taxmod\Core\Printing\FilledLabel;
use Taxmod\Core\Printing\LabelLayout;
use TaxmodStock\Core\LabelChanges;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\Admin\Messages;

/**
 * The labels as abilities (D-1239): read them with their layout, create or change one, remove one, and see the PDF it draws —
 * without wp-admin, without a release and without printing.
 *
 * ⚠️ *His word 2026-11-08: «jedes Mal braucht es so lange … direkt Etiketten auch noch anlegen könnten, löschen, und ändern».*
 *
 * ```mermaid
 * flowchart LR
 *   R["taxmod-stock/labels"] --> E["Etikett-Sätze (Layout = JSON)"]
 *   S["taxmod-stock/label-save (dry_run, pdf)"] -- "eine Änderungsliste" --> E
 *   D["taxmod-stock/label-remove"] -- "aus den Rollen, dann der Satz" --> E
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1239
 */
final class LabelAbilities
{
    public const READ   = 'taxmod-stock/labels';
    public const SAVE   = 'taxmod-stock/label-save';
    public const REMOVE = 'taxmod-stock/label-remove';

    public function register(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        $ids     = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]];
        $allowed = static fn (): bool => current_user_can('manage_options');

        wp_register_ability(self::READ, [
            'label'               => __('Read the labels', 'taxmod-stock'),
            'description'         => __('Every label record: id, name, category, size key, width, height, roll, copies, complete (prints), the rolls that allow it, the printers holding such a roll, the place types printing with it. ids: only these, with their layout. layout: true adds the layout JSON to every label. Also the categories (node ids for label-save), the rolls and the printers.', 'taxmod-stock'),
            'category'            => 'taxmod',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['ids' => $ids, 'layout' => ['type' => 'boolean', 'default' => false]],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->guarded(fn (): array => $this->read(is_array($input) ? $input : [])),
            'permission_callback' => $allowed,
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        wp_register_ability(self::SAVE, [
            'label'               => __('Create or change a label', 'taxmod-stock'),
            'description'         => __('With id: changes only the fields given. Without id: a new label in category (node id from taxmod-stock/labels), name needed. Fields: name, key (size key, e.g. 40x12), width, height (mm), roll (mm), copies, layout (JSON list of elements, as string or array; checked against the size before anything is written). rolls: roll ids that allow the label from now on. dry_run: checks and answers, writes nothing. pdf: true answers the label drawn with the examples of its layout as base64 PDF (as seen on paper). Answers the label as read back.', 'taxmod-stock'),
            'category'            => 'taxmod',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id'       => ['type' => 'integer', 'minimum' => 1],
                    'category' => ['type' => 'integer', 'minimum' => 1],
                    'name'     => ['type' => 'string'],
                    'key'      => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_\-]+$'],
                    'width'    => ['type' => 'integer', 'minimum' => 1],
                    'height'   => ['type' => 'integer', 'minimum' => 1],
                    'roll'     => ['type' => 'integer', 'minimum' => 1],
                    'copies'   => ['type' => 'integer', 'minimum' => 1],
                    'layout'   => ['type' => ['string', 'array']],
                    'rolls'    => $ids,
                    'dry_run'  => ['type' => 'boolean', 'default' => false],
                    'pdf'      => ['type' => 'boolean', 'default' => false],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->guarded(fn (): array => $this->save(is_array($input) ? $input : [])),
            'permission_callback' => $allowed,
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);

        wp_register_ability(self::REMOVE, [
            'label'               => __('Remove a label', 'taxmod-stock'),
            'description'         => __('Removes the label record and takes it out of every roll that allowed it; refused while a place type prints with it. Answers the label as it was (layout included), so label-save can make it again. dry_run: answers, removes nothing.', 'taxmod-stock'),
            'category'            => 'taxmod',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], 'dry_run' => ['type' => 'boolean', 'default' => false]],
                'required'   => ['id'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->guarded(fn (): array => $this->remove(is_array($input) ? $input : [])),
            'permission_callback' => $allowed,
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function read(array $input): array
    {
        $stock = new StockService();
        $s     = $stock->structure();
        if (! $s->hasNode('label_template')) {
            throw StockError::notConfigured('nodes.label_template');
        }
        $only   = array_values(array_filter(array_map('intval', (array) ($input['ids'] ?? [])), static fn (int $id): bool => $id > 0));
        $layout = $only !== [] || ! empty($input['layout']);
        $all    = $this->labels($stock, $layout);
        $labels = $only === [] ? array_values($all) : array_values(array_intersect_key($all, array_flip($only)));

        ['categories' => $categories, 'root' => $root] = $stock->labelRecords();
        $rolls    = [];
        $rollRows = $stock->rollTable();
        foreach ($stock->rolls() as $id => $name) {
            $rolls[] = ['id' => $id, 'name' => $name, 'width' => $rollRows !== null && $s->hasField('roll_width') ? (int) $rollRows->first($id, $s->field('roll_width')) : 0];
        }
        $printers = [];
        foreach ($stock->labelTemplates()->printerList() as $id => $printer) {
            $printers[] = ['id' => $id, 'name' => $printer['name'], 'queue' => $printer['queue'], 'roll' => $printer['roll_name']];
        }

        return [
            'labels'     => $labels,
            'categories' => array_merge([['node' => $root, 'name' => '']], array_map(static fn (int $node, string $name): array => ['node' => $node, 'name' => $name], array_keys($categories), $categories)),
            'rolls'      => $rolls,
            'printers'   => $printers,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $stock = new StockService();
        $id    = absint($input['id'] ?? 0);
        $given = array_intersect_key($input, LabelChanges::FIELDS);
        if (is_array($given['layout'] ?? null)) {
            $given['layout'] = (string) wp_json_encode($given['layout'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        $rolls = array_values(array_filter(array_map('intval', (array) ($input['rolls'] ?? [])), static fn (int $r): bool => $r > 0));
        (new LabelChanges($stock->structure()))->values($given);

        $before = $id > 0 ? ($this->labels($stock, true)[$id] ?? throw StockError::notALabel('#' . $id)) : [];
        $after  = array_merge(['name' => '', 'key' => '', 'width' => 0, 'height' => 0, 'roll' => 0, 'copies' => 1, 'layout' => ''], $before, $given);
        $filled = $this->filled($after);
        $pdf    = ! empty($input['pdf']) && $filled !== null ? ['pdf' => base64_encode($filled->render(1, true))] : [];

        if (! empty($input['dry_run'])) {
            return ['id' => $id, 'written' => false, 'label' => array_diff_key($after, ['rolls' => 1, 'printers' => 1, 'place_types' => 1]) + ['complete' => $filled !== null], 'before' => $before] + $pdf;
        }

        $saved = $stock->saveLabel($id, absint($input['category'] ?? 0), $given, $rolls);

        return ['id' => $saved, 'written' => true, 'label' => $this->labels(new StockService(), true)[$saved] ?? [], 'before' => $before] + $pdf;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function remove(array $input): array
    {
        $stock = new StockService();
        $id    = absint($input['id'] ?? 0);
        $label = $this->labels($stock, true)[$id] ?? throw StockError::notALabel('#' . $id);
        if ($label['place_types'] !== []) {
            throw StockError::labelInUse(implode(', ', $label['place_types']));
        }
        if (empty($input['dry_run'])) {
            $stock->removeLabel($id);
        }

        return ['id' => $id, 'removed' => empty($input['dry_run']), 'label' => $label];
    }

    /**
     * Every label record, complete or not, as the abilities answer it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function labels(StockService $stock, bool $withLayout): array
    {
        $s = $stock->structure();
        ['table' => $table, 'categories' => $categories, 'node_of' => $nodeOf] = $stock->labelRecords();
        $text      = static fn (int $id, string $key): string => $s->hasField($key) ? trim((string) $table->first($id, $s->field($key))) : '';
        $templates = $stock->labelTemplates();
        $changes   = new LabelChanges($s);
        $rolls     = $stock->rollTable();
        $types     = $stock->placeTypes();
        $out       = [];

        foreach ($table->ids() as $id) {
            $usedBy = [];
            foreach (array_keys($types->names()) as $type) {
                if ($types->labelTemplateOf($type) === $id) {
                    $usedBy[] = $types->nameOf($type);
                }
            }
            $printers = [];
            foreach ($templates->printerList() as $printer) {
                if (in_array($id, $printer['allows'], true)) {
                    $printers[] = $printer['name'];
                }
            }
            $out[$id] = [
                'id'          => $id,
                'name'        => $text($id, 'label'),
                'category'    => $categories[$nodeOf[$id] ?? 0] ?? '',
                'node'        => $nodeOf[$id] ?? 0,
                'key'         => $text($id, 'template_key'),
                'width'       => (int) $text($id, 'template_width'),
                'height'      => (int) $text($id, 'template_height'),
                'roll'        => (int) $text($id, 'template_roll'),
                'copies'      => max(1, (int) $text($id, 'template_copies')),
                'complete'    => $templates->has($id),
                'rolls'       => $changes->rollsAllowing($id, $rolls),
                'printers'    => $printers,
                'place_types' => $usedBy,
            ] + ($withLayout ? ['layout' => $text($id, 'template_layout')] : []);
        }
        ksort($out);

        return $out;
    }

    /**
     * The label drawn with the examples its layout carries, or null while it lacks a size or a layout; a layout that cannot be
     * drawn on this size is refused.
     *
     * @param array<string, mixed> $label
     */
    private function filled(array $label): ?FilledLabel
    {
        if (! class_exists(FilledLabel::class) || trim((string) $label['layout']) === '' || (int) $label['width'] <= 0 || (int) $label['height'] <= 0 || trim((string) $label['key']) === '') {
            return null;
        }
        try {
            $values = LabelLayout::fromJson((string) $label['layout'])->examples() + ['code' => 'M-12345'];

            return FilledLabel::fromJson((string) wp_json_encode([
                'format'   => ['key' => (string) $label['key'], 'w' => (int) $label['width'], 'h' => (int) $label['height'], 'roll' => (int) $label['roll']],
                'layout'   => json_decode((string) $label['layout'], true),
                'values'   => $values,
                'template' => (string) $label['name'],
            ]));
        } catch (\InvalidArgumentException $e) {
            throw StockError::notALabel($e->getMessage());
        }
    }

    /**
     * @param \Closure(): array<string, mixed> $act
     * @return array<string, mixed>|\WP_Error
     */
    private function guarded(\Closure $act): array|\WP_Error
    {
        try {
            return $act();
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e), ['status' => 400]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('taxmod_stock_refused', $e->getMessage(), ['status' => 400]);
        }
    }
}
