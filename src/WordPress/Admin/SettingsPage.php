<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\LocationSettings;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestSettings;
use TaxmodStock\Core\ViewSettings;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Einstellungen › Grundeinstellungen (D-1085): the business settings of the stock, inside the stock interface —
 * branches without material, branch → place proposals (D-972), tests after the goods receipt (D-1002) with their place
 * (D-1111) and the view of the tabs. The technical structure ids stay on the modeller's Configuration tab «Lager» ({@see SettingsTab}).
 *
 * The options are the ones the Configuration tab wrote before, so nothing set there is lost.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1085
 */
final class SettingsPage
{
    public const PAGE   = Pages::SETTINGS;
    public const ACTION = 'taxmod_stock_basics';
    public const USER_TAB = 'benutzer';
    public const USER_ACTION = 'taxmod_stock_user_settings';

    public static function url(): string
    {
        return add_query_arg(['page' => self::PAGE], admin_url('admin.php'));
    }

    public function render(): string
    {
        if (sanitize_key((string) ($_GET['tab'] ?? '')) === self::USER_TAB) {
            return $this->userSettings();
        }

        return '<div class="wrap">' . ListPage::heading(__('Grundeinstellungen', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice()
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . $this->notMaterialSection() . $this->placesSection() . $this->testsSection() . $this->viewsSection()
            . get_submit_button(__('Speichern', 'taxmod-stock')) . '</form></div>';
    }

    /** admin-post: capability → nonce → validate and sanitize per field (in {@see save()}) → keep → back to the page. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

        $post = wp_unslash($_POST);
        $this->save(is_array($post) ? $post : []);
        set_transient(StockPage::NOTICE . get_current_user_id(), ['type' => 'success', 'text' => __('Gespeichert.', 'taxmod-stock')], 60);
        wp_safe_redirect(self::url());
        exit;
    }

    /**
     * D-1232: what each person sets for themselves — stored with the user, never as a site option, so one person's choice
     * changes nothing for another.
     */
    private function userSettings(): string
    {
        return '<div class="wrap">' . ListPage::heading(__('Benutzereinstellungen', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice()
            . '<p>' . esc_html(sprintf(/* translators: %s: the user's display name */ __('Gilt nur für %s.', 'taxmod-stock'), wp_get_current_user()->display_name)) . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::USER_ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::USER_ACTION) . '">'
            . '<table class="form-table" role="presentation"><tbody>'
            . '<tr><th scope="row">' . esc_html__('Vollbild', 'taxmod-stock') . '</th><td><label><input type="checkbox" name="fullscreen" value="1"' . checked(LogisticsShell::isFullscreen(), true, false) . '> '
            . esc_html__('Logistik ohne WordPress-Leisten zeigen', 'taxmod-stock') . '</label></td></tr>'
            . (method_exists(ListPage::class, 'resetColumns') ? '<tr><th scope="row">' . esc_html__('Spalten der Listen', 'taxmod-stock') . '</th><td><label><input type="checkbox" name="reset_columns" value="1"> '
                . esc_html__('Eigene Spaltenwahl aller Listen zurücksetzen', 'taxmod-stock') . '</label></td></tr>' : '')
            // His wish 2026-11-09: the trees on the left open as he left them; this forgets it.
            . (method_exists(ListPage::class, 'resetTreeStates') ? '<tr><th scope="row">' . esc_html__('Bäume links', 'taxmod-stock') . '</th><td><label><input type="checkbox" name="reset_trees" value="1"> '
                . esc_html__('Gemerkten Ast und offene Äste aller Bäume vergessen', 'taxmod-stock') . '</label></td></tr>' : '')
            . '</tbody></table>' . get_submit_button(__('Speichern', 'taxmod-stock')) . '</form></div>';
    }

    /** admin-post for Benutzereinstellungen: logged in with access to the stock → nonce → keep for this user → back. */
    public function handleUser(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::USER_ACTION);
        $this->saveUser(! empty($_POST['fullscreen']), ! empty($_POST['reset_columns']), ! empty($_POST['reset_trees']));
        set_transient(StockPage::NOTICE . get_current_user_id(), ['type' => 'success', 'text' => __('Gespeichert.', 'taxmod-stock')], 60);
        wp_safe_redirect(add_query_arg('tab', self::USER_TAB, self::url()));
        exit;
    }

    public function saveUser(bool $fullscreen, bool $resetColumns, bool $resetTrees = false): void
    {
        LogisticsShell::setFullscreen($fullscreen);

        if ($resetTrees && method_exists(ListPage::class, 'resetTreeStates')) {
            ListPage::resetTreeStates();
        }

        if ($resetColumns && method_exists(ListPage::class, 'resetColumns')) {
            ListPage::resetColumns();
        }
    }

    /** One section per tab of the stock page; «Gleich aufgeklappt» where its rows can be opened. */
    private function viewsSection(): string
    {
        $views  = (new StockService())->viewSettings();
        $titles = [
            'wareneingang' => [__('Wareneingang', 'taxmod-stock'), __('Unter jeder Bestellung stehen ihre Käufe gleich offen da, ohne Klick. Sie laden, sobald sie ins Bild scrollen.', 'taxmod-stock')],
            'material'     => [__('Material', 'taxmod-stock'), __('Unter jedem Material stehen seine Bestandsposten (Datum, Rest, Preis, Lagerort) gleich offen da.', 'taxmod-stock')],
        ];
        $html   = '<h2>' . esc_html__('Ansicht der Reiter', 'taxmod-stock') . '</h2>'
            . '<input type="hidden" name="taxmod_stock_views_sent" value="1"><table class="form-table" role="presentation"><tbody>';

        foreach ($titles as $tab => [$title, $explain]) {
            $html .= '<tr><th scope="row">' . esc_html($title) . '</th><td><label><input type="checkbox" name="taxmod_stock_views[' . esc_attr($tab) . ']" value="1"'
                . checked($views->expanded($tab), true, false) . '> ' . esc_html__('Gleich aufgeklappt', 'taxmod-stock') . '</label>'
                . '<p class="description">' . esc_html($explain) . '</p></td></tr>';
        }

        return $html . '<tr><th scope="row">' . esc_html__('Inventur, Verbrauch', 'taxmod-stock') . '</th><td><p class="description">'
            . esc_html__('Diese Reiter haben keine Zeilen zum Aufklappen.', 'taxmod-stock') . '</p></td></tr></tbody></table>';
    }

    /** D-972: branch under `Model` → place; the nearest listed branch wins. Empty rows add a branch, an emptied one drops it. */
    private function placesSection(): string
    {
        try {
            $stock    = new StockService();
            $branches = $stock->branches();
        } catch (StockError $e) {
            return '<h2>' . esc_html__('Einlagerung nach Typ', 'taxmod-stock') . '</h2><p>' . esc_html(Messages::of($e)) . '</p>';
        }

        $settings = $stock->locationSettings();
        $select   = static fn (string $name, ?int $chosen): string => $stock->placeChooser($name, (int) $chosen);

        $html = '<h2>' . esc_html__('Einlagerung nach Typ', 'taxmod-stock') . '</h2>'
            . '<p>' . esc_html__('Der Wareneingang schlägt den Standard-Lagerort des Materials vor, sonst den Ort des Zweigs unter Model, in dem das Material des Kaufs liegt (der nächste eingetragene Zweig gewinnt), sonst den Standard-Ort der Struktur.', 'taxmod-stock') . '</p>'
            . '<table class="widefat striped" style="max-width:48em"><thead><tr><th>' . esc_html__('Zweig (Knoten-Id)', 'taxmod-stock') . '</th><th>' . esc_html__('Name', 'taxmod-stock') . '</th><th>' . esc_html__('Lagerort', 'taxmod-stock') . '</th></tr></thead><tbody>';
        $row  = 0;

        $rows = array_map(null, array_keys($settings->branches), array_values($settings->branches));

        foreach ([...$rows, [null, null], [null, null], [null, null]] as [$node, $location]) {
            $html .= '<tr><td><input type="number" min="1" name="taxmod_stock_branch[' . $row . '][node]" value="' . esc_attr((string) ($node ?? '')) . '" style="width:12em"></td>'
                . '<td>' . esc_html($node === null ? '' : ($branches[$node]['name'] ?? '')) . '</td>'
                . '<td>' . $select('taxmod_stock_branch[' . $row . '][location]', $location) . '</td></tr>';
            ++$row;
        }

        return $html . '<tr><td colspan="2"><strong>' . esc_html__('ohne Material', 'taxmod-stock') . '</strong></td><td>'
            . $select('taxmod_stock_unreferenced', $settings->unreferenced) . '</td></tr></tbody></table>';
    }

    /** The branches under `Model` that hold no material: left out of the material search, the tree and the groups. */
    private function notMaterialSection(): string
    {
        $stock = new StockService();
        $rows  = $stock->notMaterial();

        try {
            $names = $stock->modelNames();
        } catch (StockError) {
            $names = [];
        }

        $html = '<h2>' . esc_html__('Kein Material', 'taxmod-stock') . '</h2>'
            . '<p>' . esc_html__('Diese Zweige unter Model (mit allem darunter) enthalten kein Material, etwa das Quellenverzeichnis. Materialsuche, Baum und Materialgruppen lassen sie aus. Eine leere Zeile fügt einen Zweig hinzu, eine geleerte entfernt ihn.', 'taxmod-stock') . '</p>'
            . '<input type="hidden" name="taxmod_stock_not_material_sent" value="1">'
            . '<table class="widefat striped" style="max-width:40em"><thead><tr><th>' . esc_html__('Zweig (Knoten-Id)', 'taxmod-stock') . '</th><th>' . esc_html__('Name', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ([...$rows, null, null, null] as $i => $node) {
            $html .= '<tr><td><input type="number" min="1" name="taxmod_stock_not_material[' . $i . ']" value="' . esc_attr((string) ($node ?? '')) . '" style="width:12em"></td>'
                . '<td>' . esc_html($node === null ? '' : ($names[$node] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** D-1002: the branches under `Model` whose used goods are tested after the goods receipt (Wareneingang › Zu testen). */
    private function testsSection(): string
    {
        $stock    = new StockService();
        $settings = $stock->testSettings();
        $rows     = $settings->branches;

        try {
            $tree = $stock->tree();
        } catch (StockError) {
            $tree = [];
        }

        $html = '<h2>' . esc_html__('Test nach dem Wareneingang', 'taxmod-stock') . '</h2>'
            . '<p>' . esc_html__('Bestandsposten aus diesen Zweigen (mit allem darunter) stehen nach dem Wareneingang unter Logistik › Wareneingang › Zu testen, egal von welcher Plattform; Neuware nicht. Eine leere Zeile fügt einen Zweig hinzu, eine geleerte entfernt ihn.', 'taxmod-stock') . '</p>'
            . '<p><label>' . esc_html__('Wareneingang ab', 'taxmod-stock') . ' <input type="date" name="taxmod_stock_inbound_since" value="' . esc_attr($stock->inboundSince()) . '"></label> '
            . '<span class="description">' . esc_html__('Ältere Bestellungen gelten als Kaufhistorie und stehen gleich unter «Archiviert».', 'taxmod-stock') . '</span></p>'
            . '<p><label>' . esc_html__('Archivieren nach', 'taxmod-stock') . ' <input type="number" min="1" name="taxmod_stock_inbound_archive_days" value="' . esc_attr((string) $stock->inboundArchiveDays()) . '" style="width:6em"> ' . esc_html__('Tagen', 'taxmod-stock') . '</label> '
            . '<span class="description">' . esc_html__('Eingebuchte Bestellungen wandern so viele Tage nach «Eingegangen am» unter «Archiviert».', 'taxmod-stock') . '</span></p>'
            // D-1111: an ordinary place the setting names, never a name in code.
            . '<p><label>' . esc_html__('Lagerort für Test', 'taxmod-stock') . ' ' . $stock->placeChooser('taxmod_stock_test_place', (int) $settings->place) . '</label> '
            . '<span class="description">' . esc_html__('Der Wareneingang schlägt diesen Ort für Gebrauchtware aus den Zweigen unten vor (nicht für Neuware); leer: der übliche Vorschlag.', 'taxmod-stock') . '</span></p>'
            . '<input type="hidden" name="taxmod_stock_tests_sent" value="1">'
            . '<table class="widefat striped" style="max-width:40em"><thead><tr><th>' . esc_html__('Zweig (Knoten-Id)', 'taxmod-stock') . '</th><th>' . esc_html__('Name', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ([...$rows, null, null, null] as $i => $node) {
            $html .= '<tr><td><input type="number" min="1" name="taxmod_stock_tests[' . $i . ']" value="' . esc_attr((string) ($node ?? '')) . '" style="width:12em"></td>'
                . '<td>' . esc_html($node === null ? '' : ($tree[$node]['name'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<string, mixed> $post already unslashed */
    public function save(array $post): void
    {
        if (! empty($post['taxmod_stock_views_sent'])) {
            $chosen = is_array($post['taxmod_stock_views'] ?? null) ? $post['taxmod_stock_views'] : [];
            $views  = [];

            foreach (array_keys(ViewSettings::DEFAULTS) as $tab) {
                $views[$tab] = ['expand' => ! empty($chosen[$tab])];
            }

            update_option(StockService::VIEWS, ViewSettings::fromArray($views)->toArray(), false);
        }

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

        if (! empty($post['taxmod_stock_not_material_sent'])) {
            $nodes = array_map('absint', is_array($post['taxmod_stock_not_material'] ?? null) ? $post['taxmod_stock_not_material'] : []);
            update_option(StockService::NOT_MATERIAL, array_values(array_unique(array_filter($nodes))), false);
        }

        if (! empty($post['taxmod_stock_tests_sent'])) {
            $nodes = array_map('absint', is_array($post['taxmod_stock_tests'] ?? null) ? $post['taxmod_stock_tests'] : []);
            $place = absint($post['taxmod_stock_test_place'] ?? 0);

            try {
                $place = isset((new StockService())->places()[$place]) ? $place : 0;
            } catch (StockError) {
                $place = 0;
            }

            update_option(StockService::TESTS, TestSettings::fromArray(['branches' => array_values($nodes), 'place' => $place])->toArray(), false);
            $since = sanitize_text_field((string) ($post['taxmod_stock_inbound_since'] ?? ''));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1) {
                update_option(StockService::INBOUND_SINCE, $since, false);
            }

            if (absint($post['taxmod_stock_inbound_archive_days'] ?? 0) > 0) {
                update_option(StockService::INBOUND_ARCHIVE_DAYS, absint($post['taxmod_stock_inbound_archive_days']), false);
            }
        }
    }

    private function notice(): string
    {
        $notice = get_transient(StockPage::NOTICE . get_current_user_id());

        if (! is_array($notice)) {
            return '';
        }

        delete_transient(StockPage::NOTICE . get_current_user_id());

        return '<div class="notice notice-' . esc_attr((string) $notice['type']) . ' is-dismissible"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }
}
