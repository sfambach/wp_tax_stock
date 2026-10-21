<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use TaxmodStock\Core\Structure;

/** A structure with small ids, so a test reads like the table it builds. */
final class Fixture
{
    public static function structure(): Structure
    {
        $fields = [];

        foreach ([
            'label', 'description', 'material_ref_record', 'material_single', 'material_min',
            'lot_material', 'lot_purchase', 'lot_location', 'lot_value', 'lot_date',
            'transaction_kind', 'transaction_date', 'transaction_order', 'transaction_project', 'transaction_source', 'transaction_lines',
            'line_lot', 'line_quantity', 'line_counted',
            'order_status', 'order_lines',
            'purchase_material', 'purchase_quantity', 'purchase_undetermined', 'purchase_landed_price', 'purchase_unit_price',
            'money_amount', 'money_unit',
        ] as $i => $key) {
            $fields[$key] = 100 + $i;
        }

        return Structure::fromArray([
            'nodes'  => ['material' => 1, 'lot' => 2, 'transaction' => 3, 'line' => 4, 'location' => 5, 'order' => 6, 'purchase' => 7, 'money' => 8],
            'fields' => $fields,
            'values' => [
                'kind_receipt' => 201, 'kind_consumption' => 202, 'kind_count' => 203, 'source_manual' => 204,
                'status_partial' => 205, 'status_received' => 206, 'location_default' => 207, 'currency_default' => 208,
            ],
        ]);
    }
}
