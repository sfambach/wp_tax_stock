<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\LocationSettings;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\WordPress\StockService;

/**
 * The module's tab on Taxonomy Modeller › Configuration (D-945): which nodes and fields hold the Stock tables,
 * and which branch of `Model` the goods receipt puts where (D-972).
 *
 * The modeller draws the tab, checks capability and nonce, and calls {@see save()}; the ids are pasted as JSON
 * (`structure-live.json` holds those of fambach.net) rather than guessed from node names.
 */
final class SettingsTab
{
    public const SLUG   = 'stock';
    private const ERROR = 'taxmod_stock_settings_error';

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public static function register(array $tabs): array
    {
        $tab = new self();

        return $tabs + [self::SLUG => [
            'title'   => __('Lager', 'taxmod-stock'),
            'render'  => $tab->render(...),
            'save'    => $tab->save(...),
            // D-968: the modeller shows each module's version under the tab bar.
            'version' => defined('TAXMOD_STOCK_VERSION') ? (string) TAXMOD_STOCK_VERSION : '',
        ]];
    }

    public function render(): string
    {
        $html  = '';
        $error = get_transient(self::ERROR . get_current_user_id());

        if (is_string($error) && $error !== '') {
            delete_transient(self::ERROR . get_current_user_id());
            $html .= '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $raw = get_option(StockService::OPTION, []);

        $html .= '<h2>' . esc_html__('Lager-Struktur', 'taxmod-stock') . '</h2>'
            . '<p>' . esc_html__('Die Ids der Knoten, Felder und Werte, in denen das Lager seine Sätze schreibt.', 'taxmod-stock') . '</p>'
            . '<p><label for="taxmod-stock-structure"><strong>' . esc_html__('Struktur als JSON übernehmen', 'taxmod-stock') . '</strong></label><br>'
            . '<textarea id="taxmod-stock-structure" name="taxmod_stock_structure" rows="12" class="large-text code"></textarea></p>';

        if (is_array($raw) && $raw !== [] && empty($raw['fields']['purchase_model'])) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('Der Wareneingang braucht fields.purchase_model (das Feld «Modell» der Käufe). Bitte die Struktur aus structure-live.json neu einfügen.', 'taxmod-stock') . '</p></div>';
        }

        $html .= '<table class="widefat striped" style="max-width:40em"><thead><tr><th>' . esc_html__('Schlüssel', 'taxmod-stock') . '</th><th>Id</th></tr></thead><tbody>';

        foreach (is_array($raw) ? $raw : [] as $group => $ids) {
            foreach (is_array($ids) ? $ids : [] as $key => $id) {
                $html .= '<tr><td><code>' . esc_html($group . '.' . $key) . '</code></td><td>' . esc_html((string) $id) . '</td></tr>';
            }
        }

        return $html . '</tbody></table>' . $this->placesSection();
    }

    /** D-972: branch under `Model` → place; the nearest listed branch wins. Empty rows add a branch, an emptied one drops it. */
    private function placesSection(): string
    {
        try {
            $stock    = new StockService();
            $places   = $stock->labels('location');
            $branches = $stock->branches();
        } catch (StockError $e) {
            return '<h2>' . esc_html__('Einlagerung nach Typ', 'taxmod-stock') . '</h2><p>' . esc_html(Messages::of($e)) . '</p>';
        }

        $settings = $stock->locationSettings();
        $select   = static function (string $name, ?int $chosen) use ($places): string {
            $html = '<select name="' . esc_attr($name) . '"><option value="">—</option>';

            foreach ($places as $id => $label) {
                $html .= '<option value="' . esc_attr((string) $id) . '"' . selected($chosen, $id, false) . '>' . esc_html($label) . '</option>';
            }

            return $html . '</select>';
        };

        $html = '<h2>' . esc_html__('Einlagerung nach Typ', 'taxmod-stock') . '</h2>'
            . '<p>' . esc_html__('Der Wareneingang schlägt den Standard-Lagerort des Materials vor, sonst den Ort des Zweigs unter Model, in dem das Ding des Kaufs liegt (der nächste eingetragene Zweig gewinnt), sonst den Standard-Ort der Struktur.', 'taxmod-stock') . '</p>'
            . '<table class="widefat striped" style="max-width:48em"><thead><tr><th>' . esc_html__('Zweig (Knoten-Id)', 'taxmod-stock') . '</th><th>' . esc_html__('Name', 'taxmod-stock') . '</th><th>' . esc_html__('Lagerort', 'taxmod-stock') . '</th></tr></thead><tbody>';
        $row  = 0;

        $rows = array_map(null, array_keys($settings->branches), array_values($settings->branches));

        foreach ([...$rows, [null, null], [null, null], [null, null]] as [$node, $location]) {
            $html .= '<tr><td><input type="number" min="1" name="taxmod_stock_branch[' . $row . '][node]" value="' . esc_attr((string) ($node ?? '')) . '" style="width:12em"></td>'
                . '<td>' . esc_html($node === null ? '' : ($branches[$node]['name'] ?? '')) . '</td>'
                . '<td>' . $select('taxmod_stock_branch[' . $row . '][location]', $location) . '</td></tr>';
            ++$row;
        }

        return $html . '<tr><td colspan="2"><strong>' . esc_html__('ohne Bezug (Verbrauchsmaterial)', 'taxmod-stock') . '</strong></td><td>'
            . $select('taxmod_stock_unreferenced', $settings->unreferenced) . '</td></tr></tbody></table>';
    }

    /** @param array<string, mixed> $post already unslashed by the modeller */
    public function save(array $post): void
    {
        if (isset($post['taxmod_stock_branch']) && is_array($post['taxmod_stock_branch'])) {
            $branches = [];

            foreach ($post['taxmod_stock_branch'] as $row) {
                $node     = absint(is_array($row) ? ($row['node'] ?? 0) : 0);
                $location = absint(is_array($row) ? ($row['location'] ?? 0) : 0);

                if ($node > 0 && $location > 0) {
                    $branches[$node] = $location;
                }
            }

            $settings = LocationSettings::fromArray(['branches' => $branches, 'unreferenced' => absint($post['taxmod_stock_unreferenced'] ?? 0)]);
            update_option(StockService::LOCATIONS, $settings->toArray(), false);
        }

        $json = trim((string) ($post['taxmod_stock_structure'] ?? ''));

        if ($json === '') {
            return;
        }

        $decoded = json_decode($json, true);

        try {
            if (! is_array($decoded)) {
                throw StockError::notConfigured('JSON');
            }

            update_option(StockService::OPTION, Structure::fromArray($decoded)->toArray(), false);
        } catch (StockError $e) {
            set_transient(self::ERROR . get_current_user_id(), Messages::of($e), 60);
        }
    }
}
