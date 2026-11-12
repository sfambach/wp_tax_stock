<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\StockAnswer;

/**
 * The stock as an ability, for a reader over MCP: what lies at a place and inside it, how much there is of a material — the
 * ledger's answer, not every lot and transaction (D-1209).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1209
 */
final class StockAbility
{
    public const NAME = 'taxmod-stock/stock';

    public function register(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        $ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]];

        wp_register_ability(self::NAME, [
            'label'               => __('Read the stock', 'taxmod-stock'),
            'description'         => __('What lies in stock: with place, the lots at that storage place and every place inside it (below false: only the place itself); with materials, only these material records. Each lot row gives lot, material, place path and quantity; materials gives the total quantity and lot count per material over all rows; both lists are paged by the same limit and offset, total_lots and total_materials count them all. Without place and materials: the whole stock. limit 1–1000 (default 100), offset.', 'taxmod-stock'),
            'category'            => 'taxmod',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'place'     => ['type' => 'integer', 'minimum' => 1],
                    'below'     => ['type' => 'boolean', 'default' => true],
                    'materials' => $ids,
                    'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => StockAnswer::MAX_LIMIT, 'default' => StockAnswer::DEFAULT_LIMIT],
                    'offset'    => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->answer(is_array($input) ? $input : []),
            'permission_callback' => static fn (): bool => current_user_can('manage_options'),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function answer(array $input): array|\WP_Error
    {
        $materials = array_values(array_filter(array_map('intval', is_array($input['materials'] ?? null) ? $input['materials'] : []), static fn (int $id): bool => $id > 0));

        try {
            $stock = new StockService();

            return StockAnswer::of(
                $stock->ledger(),
                $stock->locationTree(),
                $stock->things(),
                max(0, (int) ($input['place'] ?? 0)),
                ($input['below'] ?? true) !== false,
                $materials,
                (int) ($input['limit'] ?? StockAnswer::DEFAULT_LIMIT),
                max(0, (int) ($input['offset'] ?? 0)),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('taxmod_stock_refused', $e->getMessage());
        }
    }
}
