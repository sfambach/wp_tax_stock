<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

/**
 * An Umbuchung as an ability (Prüftor 2026-11-20, for Stefan's THT box): a lot's place asked for over MCP changes only through
 * the Lager's own Vorgang «Umbuchung» (D-1233), never by writing the lot. All rows in one act, all or nothing; the note is required.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1233
 */
final class TransferAbility
{
    public const NAME = 'taxmod-stock/book-transfer';

    public function register(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability(self::NAME, [
            'label'               => __('Book an Umbuchung', 'taxmod-stock'),
            'description'         => __('Books one Vorgang «Umbuchung», as «Umbuchen» in the Lager does. Each row moves a lot from the place it lies at (from) to another (to); quantity is how much, empty moves all that lies there. note is required: it titles the Vorgang. Every row is checked first; on an error nothing is booked and the error names the row. «Stornieren» on the Vorgang undoes it.', 'taxmod-stock'),
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
                            'from'     => ['type' => 'integer', 'minimum' => 1],
                            'to'       => ['type' => 'integer', 'minimum' => 1],
                            'quantity' => ['type' => 'string'],
                        ],
                        'required'             => ['lot', 'from', 'to'],
                        'additionalProperties' => false,
                    ]],
                ],
                'required'             => ['note', 'rows'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->book(is_array($input) ? $input : []),
            // The same right as book-correction and a booking in the Lager's screens.
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
            $result = (new StockService())->bookTransfers($rows, (string) ($input['note'] ?? ''), $date);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('taxmod_stock_refused', $e instanceof \TaxmodStock\Core\StockError ? Admin\Messages::of($e) : $e->getMessage());
        }

        return ['booked' => true, 'refs' => $result['refs'] ?? [], 'result' => $result];
    }
}
