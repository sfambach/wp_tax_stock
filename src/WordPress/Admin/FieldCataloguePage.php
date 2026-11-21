<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Einstellungen › Feldkatalog (D-1555): the modeller's branch «Data Types» and the composite data types, shown and
 * never copied — each entry with its role and how many fields use it, opened in the modeller with the eye.
 *
 * ```mermaid
 * flowchart LR
 *   M["Modeller: Ast «Data Types» + zusammengesetzte Datentypen"] -->|"ModelDoor::catalogue"| L["Liste nach Baum"]
 *   L -->|"Auge"| K["Knoten im Modeller"]
 * ```
 *
 * ⚠️ *Creating an element happens in the modeller, where similar ones are shown first (D-1555, U3); this page only reads.*
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1553, D-1555
 */
final class FieldCataloguePage
{
    public const PAGE = Pages::FIELD_CATALOGUE;

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    public function render(): string
    {
        $entries = $this->stock->fieldCatalogue();
        $explain = __('Der Feldkatalog: jedes Datenelement liegt unter seinem Grundtyp und trägt alle Einstellungen (Einheiten, Grenzen, Darstellung, Hilfe). Ein Feld an einem Knoten zeigt auf ein Datenelement, nie direkt auf einen Grundtyp. Neu angelegt wird im Modeller; dort werden ähnliche zuerst gezeigt.', 'taxmod-stock');
        $base    = $entries === [] ? 0 : min(array_column($entries, 'depth'));

        return ListPage::page(__('Feldkatalog', 'taxmod-stock'), [], [['html' => ListPage::listHead(__('Feldkatalog', 'taxmod-stock'), count($entries), $explain)
            . ListPage::table([[__('Name', 'taxmod-stock'), ''], [__('Rolle', 'taxmod-stock'), '12em'], [__('Verwendungen', 'taxmod-stock'), '8em']], $this->rows($entries, $base))]], (string) TAXMOD_STOCK_VERSION);
    }

    /** @param list<array{id: int, name: string, parent: int, depth: int, role: string, uses: int}> $entries */
    private function rows(array $entries, int $base): string
    {
        if ($entries === []) {
            return '<tr class="no-items"><td class="colspanchange" colspan="5">' . esc_html__('Der Modeller kennt noch keinen Feldkatalog.', 'taxmod-stock') . '</td></tr>';
        }

        $body = '';

        foreach ($entries as $entry) {
            $url   = add_query_arg(['page' => 'taxmod', 'taxmod_node' => $entry['id']], admin_url('admin.php'));
            $tiefe = max(0, $entry['depth'] - $base);
            $body .= '<tr><th scope="row" class="check-column"></th>'
                . '<td data-taxmod-sort="' . esc_attr((string) $entry['id']) . '">' . esc_html(str_repeat('— ', $tiefe)) . ($entry['role'] === 'base_type' ? '<strong>' . esc_html($entry['name']) . '</strong>' : esc_html($entry['name'])) . '</td>'
                . '<td>' . esc_html($this->roleWord($entry['role'])) . '</td>'
                . '<td>' . esc_html((string) $entry['uses']) . '</td>'
                . '<td style="text-align:right">' . ListPage::openButton($url) . '</td></tr>';
        }

        return $body;
    }

    private function roleWord(string $role): string
    {
        return match ($role) {
            'base_type'    => __('Grundtyp', 'taxmod-stock'),
            'data_element' => __('Datenelement', 'taxmod-stock'),
            'composite'    => __('Zusammengesetzter Datentyp', 'taxmod-stock'),
            default        => __('Gruppe', 'taxmod-stock'),
        };
    }
}
