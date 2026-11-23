<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodStock\Core\LocationSettings;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestSettings;
use TaxmodStock\Core\ViewSettings;
use TaxmodStock\WordPress\StockService;

/**
 * Logistik › Einstellungen › Grundeinstellungen (D-1085): the business settings of the stock, inside the stock interface —
 * branches without material, the place for purchases without material (D-972), the test place (D-1111); the place and the test of a group are group properties (D-1259)
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

    /**
     * D-1502: a module adds its own lines to Benutzereinstellungen —
     * `apply_filters(USER_SETTINGS_FILTER, [])` → list<array{label: string, control: string, save: callable(): void}>.
     * `control` is the module's escaped HTML inside this form; `save` reads its own fields after the stock checked the nonce.
     */
    public const USER_SETTINGS_FILTER = 'taxmod_user_settings';

    public static function url(): string
    {
        return add_query_arg(['page' => self::PAGE], admin_url('admin.php'));
    }

    public function render(): string
    {
        // D-1585: the business settings are configuration; whoever may not change them sees their own settings here.
        if (sanitize_key((string) ($_GET['tab'] ?? '')) === self::USER_TAB || ! current_user_can(\Taxmod\WordPress\Rights::CONFIGURE)) {
            return $this->userSettings();
        }

        // D-1373: the page in the modeller's template.
        return ListPage::page(__('Grundeinstellungen', 'taxmod-stock'), [], [['html' => Notice::take()
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            // D-1308: «Speichern» in the toolbar above the form.
            . ListPage::toolbar([[StockPage::saveButton()]])
            . $this->materialInSection() . $this->placesSection() . $this->testsSection() . $this->viewsSection()
            . '</form>']], (string) TAXMOD_STOCK_VERSION);
    }

    /** admin-post: capability → nonce → validate and sanitize per field (in {@see save()}) → keep → back to the page. */
    public function handle(): void
    {
        if (! current_user_can(\Taxmod\WordPress\Rights::CONFIGURE)) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

        $post = wp_unslash($_POST);
        $this->save(is_array($post) ? $post : []);
        Notice::remember('success', __('Gespeichert.', 'taxmod-stock'));
        wp_safe_redirect(self::url());
        exit;
    }

    /**
     * D-1232: what each person sets for themselves — stored with the user, never as a site option, so one person's choice
     * changes nothing for another.
     */
    private function userSettings(): string
    {
        $own = self::ownSingleHit();

        // Stefan 20.11. 18:16: «Benutzereinstellungen für S-Fambach … dann kann dieser kleine Text da wegbleiben».
        return ListPage::page(sprintf(/* translators: %s: the user's display name */ __('Benutzereinstellungen für %s', 'taxmod-stock'), wp_get_current_user()->display_name), [], [['html' => Notice::take()
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::USER_ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::USER_ACTION) . '">'
            . ListPage::toolbar([[StockPage::saveButton()]])
            . '<table class="form-table" role="presentation"><tbody>'
            . '<tr><th scope="row">' . esc_html__('Vollbild', 'taxmod-stock') . '</th><td><label>' . ListPage::field(InputKind::Tick, 'fullscreen', '1', ['checked' => (string) (LogisticsShell::isFullscreen()) === '1']) . ' '
            . esc_html__('Logistik ohne WordPress-Leisten zeigen', 'taxmod-stock') . '</label></td></tr>'
            . '<tr><th scope="row">' . esc_html__('Spalten der Listen', 'taxmod-stock') . '</th><td><label>' . ListPage::field(InputKind::Tick, 'reset_columns', '1') . ' '
                . esc_html__('Eigene Spaltenwahl aller Listen zurücksetzen', 'taxmod-stock') . '</label></td></tr>'
            // His wish 2026-11-09: the trees on the left open as he left them; this forgets it.
            . '<tr><th scope="row">' . esc_html__('Bäume links', 'taxmod-stock') . '</th><td><label>' . ListPage::field(InputKind::Tick, 'reset_trees', '1') . ' '
                . esc_html__('Gemerkten Ast und offene Äste aller Bäume vergessen', 'taxmod-stock') . '</label></td></tr>'
            // D-1468: the person's own choice wins over the Grundeinstellung.
            . '<tr><th scope="row"><label for="taxmod-stock-single">' . esc_html__('Bei genau einem Treffer', 'taxmod-stock') . '</label></th><td><select id="taxmod-stock-single" name="single_hit">'
            . self::singleOption('', __('Wie in den Grundeinstellungen', 'taxmod-stock'), $own)
            . self::singleOption('0', __('In der Liste bleiben', 'taxmod-stock'), $own)
            . self::singleOption('1', __('Gleich öffnen', 'taxmod-stock'), $own) . '</select>'
            . '<p class="description">' . esc_html__('Gilt für die Suche nach Material.', 'taxmod-stock') . '</p></td></tr>'
            . implode('', array_map(static fn (array $line): string => '<tr><th scope="row">' . esc_html($line['label']) . '</th><td>' . $line['control'] . '</td></tr>', self::moduleSettings()))
            . '</tbody></table>' . '</form>']], (string) TAXMOD_STOCK_VERSION);
    }

    /**
     * D-1502: the modules' own lines, each checked for its shape; a line that lacks one part stays out.
     *
     * @return list<array{label: string, control: string, save: callable}>
     */
    public static function moduleSettings(): array
    {
        $lines = [];
        foreach ((array) apply_filters(self::USER_SETTINGS_FILTER, []) as $line) {
            if (is_array($line) && is_string($line['label'] ?? null) && is_string($line['control'] ?? null) && is_callable($line['save'] ?? null)) {
                $lines[] = ['label' => $line['label'], 'control' => $line['control'], 'save' => $line['save']];
            }
        }

        return $lines;
    }

    /** The user meta of D-1468: '' follows the site, '0' stays in the list, '1' opens the one hit. */
    public const SINGLE_HIT_META = 'taxmod_stock_single_hit';

    /** D-1468: the person's own choice for a single hit; null follows the Grundeinstellung. */
    public static function ownSingleHit(): ?bool
    {
        $own = (string) get_user_meta(get_current_user_id(), self::SINGLE_HIT_META, true);

        return $own === '' ? null : $own === '1';
    }

    private static function singleOption(string $value, string $label, ?bool $own): string
    {
        $current = $own === null ? '' : ($own ? '1' : '0');

        return '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
    }

    /** admin-post for Benutzereinstellungen: logged in with access to the stock → nonce → keep for this user → back. */
    public function handleUser(): void
    {
        if (! current_user_can(\Taxmod\WordPress\Rights::VIEW)) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::USER_ACTION);
        $this->saveUser(! empty($_POST['fullscreen']), ! empty($_POST['reset_columns']), ! empty($_POST['reset_trees']));
        $single = sanitize_key((string) wp_unslash($_POST['single_hit'] ?? ''));
        update_user_meta(get_current_user_id(), self::SINGLE_HIT_META, in_array($single, ['0', '1'], true) ? $single : '');
        foreach (self::moduleSettings() as $line) {
            ($line['save'])();
        }
        Notice::remember('success', __('Gespeichert.', 'taxmod-stock'));
        wp_safe_redirect(add_query_arg('tab', self::USER_TAB, self::url()));
        exit;
    }

    public function saveUser(bool $fullscreen, bool $resetColumns, bool $resetTrees = false): void
    {
        LogisticsShell::setFullscreen($fullscreen);

        if ($resetTrees) {
            ListPage::resetTreeStates();
        }

        if ($resetColumns) {
            ListPage::resetColumns();
        }
    }

    /** One section per tab of the stock page; «Gleich aufgeklappt» where its rows can be opened. */
    private function viewsSection(): string
    {
        $views  = (new StockService())->viewSettings();
        $titles = [
            'material'     => [__('Material', 'taxmod-stock'), __('Unter jedem Material stehen seine Bestandsposten (Datum, Rest, Preis, Lagerort) gleich offen da.', 'taxmod-stock')],
        ];
        $rows   = [];

        foreach ($titles as $tab => [$title, $explain]) {
            $rows[] = ['label' => $title, 'control' => '<label>' . ListPage::field(InputKind::Tick, 'taxmod_stock_views[' . $tab . ']', '1', ['checked' => (string) ($views->expanded($tab)) === '1']) . ' ' . esc_html__('Gleich aufgeklappt', 'taxmod-stock') . '</label>', 'hint' => $explain];
            // D-1468: off unless set — the list shows its one row and the person opens it.
            $rows[] = ['label' => '', 'control' => '<label>' . ListPage::field(InputKind::Tick, 'taxmod_stock_single[' . $tab . ']', '1', ['checked' => (string) ($views->opensSingleHit($tab)) === '1']) . ' '
                . esc_html__('Bei genau einem Treffer gleich öffnen', 'taxmod-stock') . '</label>', 'hint' => __('Sonst bleibt die Liste mit der einen Zeile stehen. Jede Person kann das in den Benutzereinstellungen für sich anders wählen.', 'taxmod-stock')];
        }

        $rows[] = ['label' => __('Inventur, Verbrauch', 'taxmod-stock'), 'control' => '', 'hint' => __('Diese Reiter haben keine Zeilen zum Aufklappen.', 'taxmod-stock')];

        // D-1521: name | field | explanation in three columns.
        return '<h2>' . esc_html__('Ansicht der Reiter', 'taxmod-stock') . '</h2><input type="hidden" name="taxmod_stock_views_sent" value="1">' . ListPage::settings($rows);
    }

    /** D-972: the place for a purchase without material; the place of a group is set in its Lager view (D-1259). */
    private function placesSection(): string
    {
        $stock = new StockService();

        return '<h2>' . esc_html__('Einlagerung', 'taxmod-stock') . '</h2><input type="hidden" name="taxmod_stock_places_sent" value="1">'
            . ListPage::settings([['label' => __('Lagerort für Käufe ohne Material', 'taxmod-stock'), 'control' => $stock->placeChooser('taxmod_stock_unreferenced', (int) $stock->locationSettings()->unreferenced),
                'hint' => __('Der Wareneingang schlägt den Lagerort des Materials vor, sonst den Standard-Lagerort seiner Gruppe (gesetzt in der Lager-Ansicht der Gruppe, vererbt nach unten), sonst den Standard-Ort der Struktur, sonst diesen.', 'taxmod-stock')],
                // His word 2026-11-22: «mach sie schaltbar und nimm sie standardmäßig raus».
                ['label' => __('Umbuchen zwischen reservierten Bereichen sperren', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Tick, 'taxmod_stock_area_lock', '1', ['checked' => $stock->locksBetweenAreas()]),
                'hint' => __('Angehakt: Ware aus einem reservierten Bereich (z. B. einem Projektgebinde) geht nicht direkt in einen anderen, nur über einen freien Platz. Reservierte Posten bleiben immer gesperrt.', 'taxmod-stock')]]);
    }

    /**
     * D-1522, his word 2026-11-20 17:52: «Material steht in» — the branches under `Model` that hold material, each chosen in the
     * modeller's node chooser, «+» for one more, the bin for one less; every other branch is left out of the material search,
     * the tree and the groups. No node id is typed.
     */
    private function materialInSection(): string
    {
        $stock = new StockService();
        $in    = $stock->materialIn() ?? [];
        $rows  = [];
        $hint  = __('Nur diese Zweige unter Model (mit allem darunter) enthalten Material. Materialsuche, Baum und Materialgruppen lassen alle anderen aus, etwa das Quellenverzeichnis.', 'taxmod-stock')
            . ($stock->materialIn() === null && $stock->notMaterial() !== [] ? ' ' . __('Noch nicht gesetzt: es gilt die ältere Liste «Kein Material».', 'taxmod-stock') : '');

        foreach ($in as $i => $node) {
            $rows[] = ['label' => $i === 0 ? __('Material steht in', 'taxmod-stock') : '', 'hint' => $i === 0 ? $hint : '',
                'control' => $stock->branchChooser('taxmod_stock_material_in[' . $i . ']', $node) . ' ' . ListPage::iconButton('trash', __('Entfernen', 'taxmod-stock'), '', 'taxmod_stock_material_in_remove', (string) $i)];
        }

        $rows[] = ['label' => $in === [] ? __('Material steht in', 'taxmod-stock') : '', 'hint' => $in === [] ? $hint : '',
            'control' => $stock->branchChooser('taxmod_stock_material_in[' . count($in) . ']') . ' ' . ListPage::iconButton('plus', __('Hinzufügen', 'taxmod-stock'), '', 'taxmod_stock_material_in_add', '1')];

        return '<h2>' . esc_html__('Material', 'taxmod-stock') . '</h2><input type="hidden" name="taxmod_stock_material_in_sent" value="1">' . ListPage::settings($rows);
    }

    /** D-1002, D-1111: the goods receipt's test place and its archive; which groups are tested is a group property (D-1259). */
    private function testsSection(): string
    {
        $stock    = new StockService();
        $settings = $stock->testSettings();

        // D-1521: name | field | explanation in three columns.
        return '<h2>' . esc_html__('Test nach dem Wareneingang', 'taxmod-stock') . '</h2><input type="hidden" name="taxmod_stock_tests_sent" value="1">'
            // D-1541: what needs a test is said by the test rules; until the first rule, by the group property (D-1259).
            . '<p>' . esc_html__('Gebrauchte Bestandsposten, für die eine Testregel passt (Einstellungen › Testregeln), stehen nach dem Wareneingang unter Logistik › Wareneingang › Test; Neuware nicht. Solange es keine Regel gibt, gilt noch «Nach Wareneingang testen» an der Gruppe.', 'taxmod-stock') . '</p>'
            . ListPage::settings([
                ['label' => __('Wareneingang ab', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Moment, 'taxmod_stock_inbound_since', (string) $stock->inboundSince(), ['note' => 'date']),
                    'hint' => __('Ältere Bestellungen gelten als Kaufhistorie und stehen gleich unter «Archiviert».', 'taxmod-stock')],
                ['label' => __('Archivieren nach', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Number, 'taxmod_stock_inbound_archive_days', (string) $stock->inboundArchiveDays(), ['min' => 1]) . ' ' . esc_html__('Tagen', 'taxmod-stock'),
                    'hint' => __('Eingebuchte Bestellungen wandern so viele Tage nach «Eingegangen am» unter «Archiviert».', 'taxmod-stock')],
                ['label' => __('Beendeten Bedarf archivieren nach', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Number, 'taxmod_stock_demand_archive_days', (string) $stock->demandArchiveDays(), ['min' => 1]) . ' ' . esc_html__('Tagen', 'taxmod-stock'),
                    'hint' => __('Ein Bedarf, der ganz in Bestellungen übergegangen ist, steht unter «Beendet» und so viele Tage nach seiner letzten Bestellung unter «Archiviert».', 'taxmod-stock')],
                // D-1375, his wish 2026-11-15: «prozentsatz in den einstellungen festlegbar ist default 20%».
                ['label' => __('Preiswarnung ab', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Number, 'taxmod_stock_price_warn_percent', (string) $stock->priceWarnPercent(), ['min' => 1]) . ' ' . esc_html__('% über dem letzten Kauf', 'taxmod-stock'),
                    'hint' => __('Der Wareneingang warnt, wenn der Stückpreis so viel über dem letzten Kauf desselben Materials liegt.', 'taxmod-stock')],
                // D-1111: an ordinary place the setting names, never a name in code.
                ['label' => __('Lagerort für Test', 'taxmod-stock'), 'control' => $stock->placeChooser('taxmod_stock_test_place', (int) $settings->place),
                    'hint' => __('Der Wareneingang schlägt diesen Ort für Gebrauchtware aus diesen Gruppen vor (nicht für Neuware); leer: der übliche Vorschlag.', 'taxmod-stock')],
                // D-1440: where «Einlagern» puts a purchase without a place.
                ['label' => __('Lagerort Wareneingang', 'taxmod-stock'), 'control' => $stock->placeChooser('taxmod_stock_receipt_inbound', $stock->receiptInbound()),
                    'hint' => __('Hierhin legt «Einlagern» einen Kauf ohne Lagerort; von dort geht er per Umbuchen an seinen Platz. Leer: «Einlagern» braucht einen Lagerort in der Zeile.', 'taxmod-stock')],
                // D-1303: where finished boards come in, an ordinary place the setting names.
                ['label' => __('Lagerort Wareneingang Produktion', 'taxmod-stock'), 'control' => $stock->placeChooser('taxmod_stock_production_inbound', $stock->productionInbound()),
                    'hint' => __('Hier kommen fertige Platinen aus der Fertigung an.', 'taxmod-stock')],
            ]);
    }

    /** @param array<string, mixed> $post already unslashed */
    public function save(array $post): void
    {
        if (! empty($post['taxmod_stock_views_sent'])) {
            $chosen = is_array($post['taxmod_stock_views'] ?? null) ? $post['taxmod_stock_views'] : [];
            $single = is_array($post['taxmod_stock_single'] ?? null) ? $post['taxmod_stock_single'] : [];
            $views  = [];

            foreach (array_keys(ViewSettings::DEFAULTS) as $tab) {
                $views[$tab] = ['expand' => ! empty($chosen[$tab]), 'open_single' => ! empty($single[$tab])];
            }

            update_option(StockService::VIEWS, ViewSettings::fromArray($views)->toArray(), false);
        }

        if (! empty($post['taxmod_stock_places_sent'])) {
            $settings = LocationSettings::fromArray(['unreferenced' => absint($post['taxmod_stock_unreferenced'] ?? 0)]);
            update_option(StockService::LOCATIONS, ['unreferenced' => $settings->unreferenced], false);
            update_option(StockService::AREA_LOCK, empty($post['taxmod_stock_area_lock']) ? '0' : '1', false);
        }

        if (! empty($post['taxmod_stock_material_in_sent'])) {
            $nodes  = is_array($post['taxmod_stock_material_in'] ?? null) ? $post['taxmod_stock_material_in'] : [];
            $remove = isset($post['taxmod_stock_material_in_remove']) ? (string) $post['taxmod_stock_material_in_remove'] : null;

            if ($remove !== null) {
                unset($nodes[$remove]);
            }

            update_option(StockService::MATERIAL_IN, array_values(array_unique(array_filter(array_map('absint', $nodes)))), false);
        }

        if (! empty($post['taxmod_stock_tests_sent'])) {
            $place = absint($post['taxmod_stock_test_place'] ?? 0);

            try {
                $place = isset((new StockService())->places()[$place]) ? $place : 0;
            } catch (StockError) {
                $place = 0;
            }

            update_option(StockService::TESTS, ['place' => TestSettings::fromArray(['place' => $place])->place], false);
            $inbound = absint($post['taxmod_stock_production_inbound'] ?? 0);

            try {
                $inbound = isset((new StockService())->places()[$inbound]) ? $inbound : 0;
            } catch (StockError) {
                $inbound = 0;
            }

            update_option(StockService::PRODUCTION_INBOUND, $inbound, false);
            $arrival = absint($post['taxmod_stock_receipt_inbound'] ?? 0);

            try {
                $arrival = isset((new StockService())->places()[$arrival]) ? $arrival : 0;
            } catch (StockError) {
                $arrival = 0;
            }

            update_option(StockService::RECEIPT_INBOUND, $arrival, false);
            $since = sanitize_text_field((string) ($post['taxmod_stock_inbound_since'] ?? ''));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1) {
                update_option(StockService::INBOUND_SINCE, $since, false);
            }

            if (absint($post['taxmod_stock_inbound_archive_days'] ?? 0) > 0) {
                update_option(StockService::INBOUND_ARCHIVE_DAYS, absint($post['taxmod_stock_inbound_archive_days']), false);
            }

            if (absint($post['taxmod_stock_demand_archive_days'] ?? 0) > 0) {
                update_option(StockService::DEMAND_ARCHIVE_DAYS, absint($post['taxmod_stock_demand_archive_days']), false);
            }

            if (absint($post['taxmod_stock_price_warn_percent'] ?? 0) > 0) {
                update_option(StockService::PRICE_WARN_PERCENT, absint($post['taxmod_stock_price_warn_percent']), false);
            }
        }
    }

}
