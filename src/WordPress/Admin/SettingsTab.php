<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\WordPress\StockService;

/**
 * The module's tab on Taxonomy Modeller › Configuration (D-945): which nodes and fields hold the Stock tables.
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
            'title'  => __('Lager', 'taxmod-stock'),
            'render' => $tab->render(...),
            'save'   => $tab->save(...),
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

        $html .= '<table class="widefat striped" style="max-width:40em"><thead><tr><th>' . esc_html__('Schlüssel', 'taxmod-stock') . '</th><th>Id</th></tr></thead><tbody>';

        foreach (is_array($raw) ? $raw : [] as $group => $ids) {
            foreach (is_array($ids) ? $ids : [] as $key => $id) {
                $html .= '<tr><td><code>' . esc_html($group . '.' . $key) . '</code></td><td>' . esc_html((string) $id) . '</td></tr>';
            }
        }

        return $html . '</tbody></table>';
    }

    /** @param array<string, mixed> $post already unslashed by the modeller */
    public function save(array $post): void
    {
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
