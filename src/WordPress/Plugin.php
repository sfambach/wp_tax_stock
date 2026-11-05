<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestSettings;
use TaxmodStock\WordPress\Admin\CockpitPage;
use TaxmodStock\WordPress\Admin\ContactsPage;
use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\Messages;
use TaxmodStock\WordPress\Admin\ModulePage;
use TaxmodStock\WordPress\Admin\PurchasePage;
use TaxmodStock\WordPress\Admin\SettingsPage;
use TaxmodStock\WordPress\Admin\SettingsTab;
use TaxmodStock\WordPress\Admin\StockPage;

/**
 * Wires the module into WordPress: a tab on the modeller's Configuration, and the goods receipt cockpit, the page «Lager» and
 * the page «Einkauf» under the modeller's menu (D-1028: one Taxmod menu, not two; D-1027).
 */
final class Plugin
{
    /** The slug of the goods receipt cockpit; kept from the former menu «Logistik» so bookmarks and links still work (D-1028). */
    public const MENU = 'taxmod-logistik';

    /** The modeller's menu, which every Taxmod module hangs its pages under (D-1028). */
    public const PARENT = 'taxmod';

    /** D-1146: the filter the manufacturing module books a build's consumption through. */
    public const BOOK_BUILD = 'taxmod_stock_book_build';

    /** D-1146: the pick list reads where the things lie free, outside the order's box. */
    public const PLACES_OF = 'taxmod_stock_places_of';

    /** D-1146: the ticked lines of a pick list go into the box. */
    public const BOOK_PICK = 'taxmod_stock_book_pick';

    /** D-1143: the finished things of a build order go into stock. */
    public const BOOK_OUTPUT = 'taxmod_stock_book_output';

    /** D-1143: a new material for a finished board, in a group of the things' tree. */
    public const CREATE_THING = 'taxmod_stock_create_thing';

    /** D-1168: the lots a resend can take its goods from, lot → «Material · Datum · Rest». */
    public const LOSS_LOTS = 'taxmod_stock_loss_lots';

    /** D-1168: the goods sent a second time leave as a loss — a Vorgang «Verbrauch» naming the sale. */
    public const BOOK_LOSS = 'taxmod_stock_book_loss';

    /** D-1168: taking the resend back cancels that loss (D-1036). */
    public const CANCEL_LOSS = 'taxmod_stock_cancel_loss';

    public function boot(): void
    {
        // Checked at runtime rather than with «Requires Plugins»: WordPress matches that header against the folder name, and the
        // modeller lives in a versioned folder on fambach.net, so activation was refused although it was active.
        add_action('plugins_loaded', [$this, 'bootWhenModellerIsThere']);
    }

    public function bootWhenModellerIsThere(): void
    {
        // *The shared tiles (D-1149) and the period view's blocks (D-1167) came with the modeller 0.1.165; an older one is
        // named rather than failing on a page.*
        if (! class_exists(\Taxmod\WordPress\Plugin::class) || ! class_exists(\Taxmod\Core\Tile\Period::class)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Stock braucht den aktiven Taxonomy Modeller ab 0.1.165.', 'taxmod-stock') . '</p></div>';
            });

            return;
        }

        add_filter('taxmod_config_tabs', [SettingsTab::class, 'register']);
        add_action('init', [$this, 'registerSettings']);
        // After the modeller (priority 10) has created its menu.
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        // Last, when every module has hung its page under the Taxmod menu, and before admin_head takes the entries out.
        add_action('admin_menu', [ModulePage::class, 'capture'], PHP_INT_MAX);
        add_action('admin_post_' . StockPage::ACTION, static fn () => self::serially(static fn () => (new StockPage())->handle()));
        add_action('admin_post_' . StockPage::POSITIONS, static fn () => (new StockPage())->positions());
        add_action('admin_post_' . StockPage::THING_SEARCH, static fn () => (new StockPage())->thingSearch());
        add_action('admin_post_' . StockPage::THING_NODES, static fn () => (new StockPage())->thingNodes());
        add_action('admin_post_' . StockPage::THING_BRANCH, static fn () => (new StockPage())->thingBranch());
        add_action('admin_post_' . InboundPage::ACTION, static fn () => self::serially(static fn () => (new InboundPage())->handle()));
        add_action('admin_post_' . PurchasePage::ACTION, static fn () => self::serially(static fn () => (new PurchasePage())->handle()));
        add_action('admin_post_' . ContactsPage::ACTION, static fn () => (new ContactsPage())->handle());
        add_action('admin_post_' . SettingsPage::ACTION, static fn () => (new SettingsPage())->handle());
        add_action('admin_post_' . LogisticsShell::FULLSCREEN_ACTION, [LogisticsShell::class, 'switchFullscreen']);
        add_filter(self::BOOK_BUILD, [self::class, 'bookBuild'], 10, 2);
        add_filter(self::PLACES_OF, [self::class, 'placesOf'], 10, 2);
        add_filter(self::BOOK_PICK, [self::class, 'bookPick'], 10, 2);
        add_filter(self::BOOK_OUTPUT, [self::class, 'bookOutput'], 10, 2);
        add_filter(self::CREATE_THING, [self::class, 'createThing'], 10, 2);
        add_filter(self::LOSS_LOTS, [self::class, 'lossLots']);
        add_filter(self::BOOK_LOSS, [self::class, 'bookLoss'], 10, 2);
        add_filter(self::CANCEL_LOSS, [self::class, 'cancelLoss'], 10, 2);
        // D-1147: the rows of the material, purchase and inbound lists draw their details when opened.
        // The filter's name stands as text, so an older modeller without it does not stop the module.
        add_filter('taxmod_list_details', static fn (?string $html, string $source, array $args): ?string => match ($source) {
            StockPage::DETAILS_MATERIAL => (new StockPage())->listDetails($html, $source, $args),
            PurchasePage::DETAILS       => (new PurchasePage())->listDetails($html, $source, $args),
            InboundPage::DETAILS        => (new InboundPage())->listDetails($html, $source, $args),
            ContactsPage::DETAILS       => (new ContactsPage())->listDetails($html, $source, $args),
            default                     => $html,
        }, 10, 3);
    }

    /**
     * D-1146 for wp_tax_manufacturing (D-1142): `apply_filters('taxmod_stock_book_build', null, $args)` books what a build
     * order takes from its box or gives back. `$args`: reference (the order's record), place (the box), lines (thing →
     * pieces, more than 0 taken, less than 0 given back), title, date (Y-m-d). Answers the Vorgang's id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function bookBuild(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args  = is_array($args) ? $args : [];
        $date  = sanitize_text_field((string) ($args['date'] ?? ''));
        $lines = [];

        foreach (is_array($args['lines'] ?? null) ? $args['lines'] : [] as $thing => $quantity) {
            if (absint($thing) > 0 && is_numeric($quantity)) {
                $lines[absint($thing)] = (float) $quantity;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return (new StockService())->bookBuild(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Fertigung', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1143 for wp_tax_manufacturing: `apply_filters('taxmod_stock_book_output', null, $args)` brings the finished things of
     * a build order into stock. `$args`: reference (the order's record), place (where they go), lines (thing → pieces, less
     * than 0 cancels what this order brought in), title, date (Y-m-d), tested (true: tested in the manufacturing, D-1177).
     * Answers the Vorgang's id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function bookOutput(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args  = is_array($args) ? $args : [];
        $date  = sanitize_text_field((string) ($args['date'] ?? ''));
        $lines = [];

        foreach (is_array($args['lines'] ?? null) ? $args['lines'] : [] as $thing => $quantity) {
            if (absint($thing) > 0 && is_numeric($quantity)) {
                $lines[absint($thing)] = (float) $quantity;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return (new StockService())->bookOutput(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Fertigung', 'taxmod-stock'))), ($args['tested'] ?? false) === true);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1143: `apply_filters('taxmod_stock_create_thing', null, ['name' => …, 'group' => node])` creates a material in a
     * group of the things' tree. Answers its record id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     name and group as the caller sends them; `mixed`: checked here
     */
    public static function createThing(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];

        try {
            return (new StockService())->createThing(sanitize_text_field((string) ($args['name'] ?? '')), absint($args['group'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * One booking at a time (D-1147): two pickers who both read «1 left» must not both book it.
     *
     * The second waits for the first, then reads the stock again inside its own request and is refused if it is no longer there.
     * The lock lasts one request and is dropped with the connection, so a closed browser holds nothing. Without a modeller that has
     * the lock (before 0.1.151) the booking runs as before.
     */
    public static function serially(callable $work): void
    {
        if (! class_exists(\Taxmod\WordPress\Persistence\WriteLock::class)) {
            $work();

            return;
        }

        try {
            \Taxmod\WordPress\Persistence\WriteLock::around('stock', $work);
        } catch (\Taxmod\Core\Exception\ConcurrentChange $e) {
            wp_die(esc_html($e->getMessage()), esc_html__('Gerade wird gebucht', 'taxmod-stock'), ['response' => 409, 'back_link' => true]);
        }
    }

    /**
     * D-1146: `apply_filters('taxmod_stock_places_of', null, ['things' => [ids], 'except' => box])` answers thing → place →
     * pieces lying free (no reserved area, no lot a reservation names), outside the box and its places.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the request as the caller sends it; `mixed`: checked here
     * @return array<int, array<int, float>>|\WP_Error
     */
    public static function placesOf(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];

        try {
            return (new StockService())->freeStockByPlace(array_values(array_filter(array_map('absint', is_array($args['things'] ?? null) ? $args['things'] : []))), absint($args['except'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1146: `apply_filters('taxmod_stock_book_pick', null, ['reference' => order, 'place' => box, 'lines' => [['thing' =>
     * id, 'from' => place, 'qty' => pieces], …], 'title' => …, 'date' => Y-m-d])` moves them into the box as one Umbuchung
     * naming the order; answers the Vorgang's id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    /**
     * D-1168: `apply_filters('taxmod_stock_loss_lots', [])` answers lot → «Material · Datum · Rest n», lots with a rest only.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @return array<int, string>
     */
    public static function lossLots(mixed $previous): array
    {
        if (is_array($previous) && $previous !== []) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return [];
        }

        try {
            return (new StockService())->lotChoices();
        } catch (StockError) {
            return [];
        }
    }

    /**
     * D-1168: `apply_filters('taxmod_stock_book_loss', null, ['sale' => id, 'lot' => id, 'quantity' => '1', 'reason' => '…',
     * 'date' => 'Y-m-d', 'title' => '…'])` books the goods sent again as a consumption with the sale as Bezug and the reason
     * as Grund; answers the Vorgang's id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function bookLoss(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $date = sanitize_text_field((string) ($args['date'] ?? ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return (new StockService())->bookLoss(
                absint($args['lot'] ?? 0),
                sanitize_text_field((string) ($args['quantity'] ?? '')),
                absint($args['sale'] ?? 0),
                sanitize_text_field((string) ($args['reason'] ?? '')),
                $date,
                sanitize_text_field((string) ($args['title'] ?? __('Verlust', 'taxmod-stock')))
            );
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1168: `apply_filters('taxmod_stock_cancel_loss', null, ['deal' => id, 'who' => name])` cancels a loss booked
     * through `taxmod_stock_book_loss` (D-1036: a Storno, nothing is deleted); answers the Storno's id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function cancelLoss(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];

        try {
            return (new StockService())->cancelLoss(absint($args['deal'] ?? 0), current_time('Y-m-d H:i:s'), sanitize_text_field((string) ($args['who'] ?? wp_get_current_user()->display_name)));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    public static function bookPick(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args  = is_array($args) ? $args : [];
        $date  = sanitize_text_field((string) ($args['date'] ?? ''));
        $lines = [];

        foreach (is_array($args['lines'] ?? null) ? $args['lines'] : [] as $line) {
            if (is_array($line) && absint($line['thing'] ?? 0) > 0 && absint($line['from'] ?? 0) > 0 && is_numeric($line['qty'] ?? null) && (float) $line['qty'] > 0) {
                $lines[] = ['thing' => absint($line['thing']), 'from' => absint($line['from']), 'quantity' => (float) $line['qty']];
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        if ($lines === []) {
            return new \WP_Error('taxmod_stock_nothing_to_book', Messages::of(StockError::nothingToBook()));
        }

        try {
            return (new StockService())->bookPick(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Fertigung', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /** The branches without material and the test settings, settable over REST (/wp/v2/settings) as well as on Logistik › Einstellungen. */
    public function registerSettings(): void
    {
        register_setting('taxmod_stock', StockService::NOT_MATERIAL, [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => static fn ($value): array => array_values(array_filter(array_map('absint', is_array($value) ? $value : []))),
            'show_in_rest'      => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ]);

        // D-1111: the tested branches and «Lagerort für Test», so the place can be named over REST once it is created.
        register_setting('taxmod_stock', StockService::TESTS, [
            'type'              => 'object',
            'default'           => ['branches' => [], 'place' => null],
            'sanitize_callback' => static fn ($value): array => TestSettings::fromArray(is_array($value) ? $value : [])->toArray(),
            'show_in_rest'      => ['schema' => ['type' => 'object', 'properties' => [
                'branches' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'place'    => ['type' => ['integer', 'null']],
            ]]],
        ]);
    }

    /**
     * D-1053: «Logistik» is the one entry in the Taxmod menu; the pages behind it keep their addresses (bookmarks, links of
     * other modules) but leave the menu, since the stock interface's own navigation reaches them.
     */
    public function registerMenu(): void
    {
        // A page hands back its HTML, or (D-1092) `side` and `main` when it shows a box under the navigation.
        $page = static fn (string $slug, callable $render): \Closure => static function () use ($slug, $render): void {
            $out = $render();
            print is_array($out) ? LogisticsShell::wrap($slug, $out['main'], $out['side']) : LogisticsShell::wrap($slug, $out);
        };

        $hooks = [
            add_submenu_page(self::PARENT, __('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', CockpitPage::PAGE, $page(CockpitPage::PAGE, static fn (): string => (new CockpitPage())->render())),
            // D-1027: demand, source, order per supplier — he orders at the shop himself.
            add_submenu_page(self::PARENT, __('Einkauf', 'taxmod-stock'), __('Einkauf', 'taxmod-stock'), 'manage_options', PurchasePage::PAGE, $page(PurchasePage::PAGE, static fn (): string => (new PurchasePage())->render())),
            add_submenu_page(self::PARENT, __('Wareneingang', 'taxmod-stock'), __('Wareneingang', 'taxmod-stock'), 'manage_options', self::MENU, $page(self::MENU, static fn (): string => (new InboundPage())->render())),
            add_submenu_page(self::PARENT, __('Lager', 'taxmod-stock'), __('Lager', 'taxmod-stock'), 'manage_options', StockPage::PAGE, $page(StockPage::PAGE, static fn (): array => (new StockPage())->parts())),
            // D-1183: Logistik › Kontakte, the model's contact branch.
            add_submenu_page(self::PARENT, __('Kontakte', 'taxmod-stock'), __('Kontakte', 'taxmod-stock'), 'manage_options', ContactsPage::PAGE, $page(ContactsPage::PAGE, static fn (): array => (new ContactsPage())->render())),
            // D-1085: Logistik › Einstellungen › Grundeinstellungen, inside the navigation.
            add_submenu_page(self::PARENT, __('Grundeinstellungen', 'taxmod-stock'), __('Grundeinstellungen', 'taxmod-stock'), 'manage_options', SettingsPage::PAGE, $page(SettingsPage::PAGE, static fn (): string => (new SettingsPage())->render())),
        ];

        // D-1085: a connection module's page inside the navigation; one address for all of them, the module named in the query.
        $moduleHook = add_submenu_page(self::PARENT, __('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', ModulePage::PAGE, $page(ModulePage::PAGE, static fn (): string => (new ModulePage())->render(ModulePage::requested())));
        $hooks[]    = $moduleHook;

        if (is_string($moduleHook) && $moduleHook !== '') {
            add_action('load-' . $moduleHook, [ModulePage::class, 'load'], 20);
        }

        // Notices of other plugins do not show inside the stock interface (design 7.3); its own stand inside the page.
        foreach (array_filter($hooks) as $hook) {
            add_action('load-' . $hook, static function (): void {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            });
        }

        // Removed only when the menu is drawn: WordPress checks access to a page against the submenu, so removing them here
        // would lock the pages. D-1085: the modules' entries go too — the menu keeps Modeller, Logistik, maintenance, Configuration.
        add_action('admin_head', static function (): void {
            foreach ([self::MENU, StockPage::PAGE, PurchasePage::PAGE, SettingsPage::PAGE, ContactsPage::PAGE, ModulePage::PAGE, ...ModulePage::menuEntries()] as $hidden) {
                remove_submenu_page(self::PARENT, $hidden);
            }
        });

        // The modeller's forms (record editor, choosers, dialogs) on a logistics page need its stylesheet in the head: enqueued late,
        // from inside the filter, it came too late, and a chosen project showed its hidden search field beside it (2026-11-03).
        add_action('admin_enqueue_scripts', static function (): void {
            if (in_array(sanitize_key((string) ($_GET['page'] ?? '')), [self::MENU, CockpitPage::PAGE, StockPage::PAGE, PurchasePage::PAGE, SettingsPage::PAGE, ModulePage::PAGE, InboundPage::PAGE, ContactsPage::PAGE], true)) {
                do_action('taxmod_enqueue_admin_style');
            }
        });

        // D-1069: the material page inside a goods receipt dialog shows only itself — no admin menu, bar or logistics navigation.
        add_action('admin_enqueue_scripts', static function (): void {
            if (! in_array(sanitize_key((string) ($_GET['page'] ?? '')), [StockPage::PAGE, 'taxmod'], true)) {
                return;
            }

            wp_register_script('taxmod-stock-frame', false, [], (string) TAXMOD_STOCK_VERSION, false);
            wp_enqueue_script('taxmod-stock-frame');
            wp_add_inline_script('taxmod-stock-frame', 'if(window.self!==window.top){document.documentElement.classList.add("taxmod-in-frame");}');
            wp_register_style('taxmod-stock-frame', false, [], (string) TAXMOD_STOCK_VERSION);
            wp_enqueue_style('taxmod-stock-frame');
            wp_add_inline_style('taxmod-stock-frame', 'html.taxmod-in-frame #adminmenumain,html.taxmod-in-frame #wpadminbar,html.taxmod-in-frame #wpfooter,html.taxmod-in-frame .taxmod-shell-nav,html.taxmod-in-frame .taxmod-stock-pagetabs,html.taxmod-in-frame h1.wp-heading-inline,html.taxmod-in-frame .taxmod-version{display:none!important}html.taxmod-in-frame .taxmod-shell{display:block}'
                . 'html.taxmod-in-frame.wp-toolbar{padding-top:0}html.taxmod-in-frame #wpcontent{margin-left:0}');
        });

        // On a page behind «Logistik» the menu still marks «Logistik».
        add_filter('submenu_file', static fn (?string $file): ?string => in_array(sanitize_key((string) ($_GET['page'] ?? '')), [self::MENU, StockPage::PAGE, PurchasePage::PAGE, SettingsPage::PAGE, ModulePage::PAGE], true) ? CockpitPage::PAGE : $file);
    }
}
