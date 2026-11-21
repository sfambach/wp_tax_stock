<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Stammdaten › Materialgruppen (D-1574): the modeller's node page, scoped to one branch «Material steht in», drawn in
 * the stock template with its navigation (Live-Check 15). Its links and acts come back here.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1574
 */
final class MaterialGroupsPage
{
    public const PAGE = Pages::MATERIAL_GROUPS;

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /** The page for one material branch; the first one when `$root` is none of them. */
    public static function url(int $root = 0): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE, 'tab' => $root > 0 ? (string) $root : '']), admin_url('admin.php'));
    }

    public function render(): string
    {
        $page = static fn (string $body): string => ListPage::page(__('Materialgruppen', 'taxmod-stock'), [], [['html' => $body]], (string) TAXMOD_STOCK_VERSION);

        try {
            $roots = $this->stock->materialRoots();
        } catch (StockError $e) {
            return $page('<div class="notice notice-warning inline"><p>' . esc_html($e->getMessage()) . '</p></div>');
        }

        if ($roots === []) {
            return $page('<div class="notice notice-warning inline"><p>' . esc_html__('Es ist noch kein Material-Ast eingerichtet («Material steht in»).', 'taxmod-stock') . '</p></div>');
        }

        // The modeller's links carry the scope; the menu names the branch as its tab. Only a branch «Material steht in» is taken.
        $asked = absint(wp_unslash((string) ($_GET['taxmod_scope'] ?? $_GET['tab'] ?? '0')));
        $root  = in_array($asked, $roots, true) ? $asked : $roots[0];
        $html  = (string) apply_filters('taxmod_node_screen', '', $root, self::PAGE);

        return $html !== ''
            ? $html
            : $page('<div class="notice notice-warning inline"><p>' . esc_html__('Der Taxonomy Modeller ist zu alt für die Materialgruppen (ab 0.2.231).', 'taxmod-stock') . '</p></div>');
    }
}
