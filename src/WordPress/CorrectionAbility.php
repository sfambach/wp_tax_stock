<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

/**
 * D-1382: a stock correction as an ability, so a booking asked for over MCP runs through the Lager's own inventory booking —
 * a Vorgang with its lines, never a lot's place set by hand (D-1233). All or nothing; the note is required.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1382
 */
final class CorrectionAbility
{
    public const NAME = 'taxmod-stock/book-correction';

    public function register(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability(self::NAME, [
            'label'               => __('Book a stock correction', 'taxmod-stock'),
            'description'         => __('Books one Vorgang «Inventur/Korrektur», as the inventory does. Each row either corrects an existing lot (lot, place, quantity signed: +1 adds, -2 takes) or brings in a new lot (thing, place, quantity positive, optional purchase as origin and value per piece). note is required: it titles the Vorgang and stands on each new lot. Every row is checked first; on an error nothing is booked and the error names the row. Answers the Vorgang and the new lots.', 'taxmod-stock'),
            'category'            => 'taxmod',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'note' => ['type' => 'string', 'minLength' => 1],
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today'],
                    'rows' => ['type' => 'array', 'minItems' => 1, 'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'lot'      => ['type' => 'integer', 'minimum' => 1],
                            'thing'    => ['type' => 'integer', 'minimum' => 1],
                            'purchase' => ['type' => 'integer', 'minimum' => 1],
                            'place'    => ['type' => 'integer', 'minimum' => 1],
                            'quantity' => ['type' => 'string'],
                            'value'    => ['type' => 'string'],
                        ],
                        'required'             => ['place', 'quantity'],
                        'additionalProperties' => false,
                    ]],
                ],
                'required'             => ['note', 'rows'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->book(is_array($input) ? $input : []),
            // The same right as a booking in the Lager's screens.
            'permission_callback' => static fn (): bool => current_user_can(\Taxmod\WordPress\Rights::STOCK_BOOK),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function book(array $input): array|\WP_Error
    {
        $date = (string) ($input['date'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : current_time('Y-m-d');
        $rows = array_values(array_filter(is_array($input['rows'] ?? null) ? $input['rows'] : [], 'is_array'));

        try {
            $result = (new StockService())->bookCorrection($rows, (string) ($input['note'] ?? ''), $date);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('taxmod_stock_refused', $e->getMessage());
        }

        return ['booked' => true, 'refs' => $result['refs'] ?? [], 'result' => $result];
    }
}
