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
            'label', 'description', 'lager_thing', 'lager_single', 'lager_min',
            'lot_thing', 'lot_purchase', 'lot_location', 'lot_value', 'lot_date',
            'transaction_kind', 'transaction_date', 'transaction_order', 'transaction_project', 'transaction_source', 'transaction_lines',
            'line_lot', 'line_quantity', 'line_counted',
            'order_status', 'order_lines',
            'purchase_quantity', 'purchase_undetermined', 'purchase_landed_price', 'purchase_unit_price',
            'money_amount', 'money_unit',
            'purchase_model', 'lager_use', 'lager_location',
            'lager_sources', 'source_firm', 'source_number', 'firm_name', 'lager_unit', 'lager_prefix', 'source_content',
            'purchase_shipping_share', 'purchase_fees_share',
            'order_merchant', 'order_number', 'purchase_number', 'purchase_demand',
        ] as $i => $key) {
            $fields[$key] = 100 + $i;
        }

        return Structure::fromArray([
            'nodes'  => ['lager' => 1, 'lot' => 2, 'transaction' => 3, 'line' => 4, 'location' => 5, 'order' => 6, 'purchase' => 7, 'money' => 8, 'lager_use' => 9, 'source' => 10, 'firm' => 11],
            'fields' => $fields,
            'values' => [
                'kind_receipt' => 201, 'kind_consumption' => 202, 'kind_count' => 203, 'source_manual' => 204,
                'status_partial' => 205, 'status_received' => 206, 'location_default' => 207, 'currency_default' => 208,
            ],
        ]);
    }
}
