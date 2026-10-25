<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod Stock
 * Description:       Stock keeping for the Taxonomy Modeller: stock count, goods receipt, consumption, stock list.
 * Version: 0.1.9
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Stefan Fambach
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxmod-stock
 * GitHub Plugin URI: sfambach/wp_tax_stock
 * Primary Branch:    main
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TAXMOD_STOCK_VERSION', '0.1.9');

$taxmodStockAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmodStockAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Stock: vendor/autoload.php fehlt — bitte das Release-Paket installieren.', 'taxmod-stock') . '</p></div>';
    });

    return;
}

require $taxmodStockAutoloader;

(new TaxmodStock\WordPress\Plugin())->boot();
