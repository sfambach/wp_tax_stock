<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\Calculation;
use TaxmodStock\Core\ReservationRow;
use TaxmodStock\Core\PlaceFunctions;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TestResult;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\TestSettings;
use TaxmodStock\WordPress\Admin\Acts;
use TaxmodStock\WordPress\Admin\CockpitPage;
use TaxmodStock\WordPress\Admin\ChoiceListsPage;
use TaxmodStock\WordPress\Admin\ContactsPage;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\WordPress\Admin\InboundPage;
use TaxmodStock\WordPress\Admin\LogisticsShell;
use TaxmodStock\WordPress\Admin\MaterialPicker;
use TaxmodStock\WordPress\Admin\Messages;
use TaxmodStock\WordPress\Admin\ModulePage;
use TaxmodStock\WordPress\Admin\Pages;
use TaxmodStock\WordPress\Admin\PurchasePage;
use TaxmodStock\WordPress\Admin\SettingsPage;
use TaxmodStock\WordPress\Admin\TestRulesPage;
use TaxmodStock\WordPress\Admin\FieldCataloguePage;
use TaxmodStock\WordPress\Admin\SettingsTab;
use TaxmodStock\WordPress\Admin\StockPage;

/**
 * Wires the module into WordPress: a tab on the modeller's Configuration, and the goods receipt cockpit, the page «Lager» and
 * the page «Einkauf» under the modeller's menu (D-1028: one Taxmod menu, not two; D-1027).
 */
final class Plugin
{
    /** The slug of the goods receipt cockpit; kept from the former menu «Logistik» so bookmarks and links still work (D-1028). */
    public const MENU = Admin\Pages::INBOUND;

    /** The pages that draw the modeller's forms, choosers and dialogs — they get its stylesheet and scripts in the head. */
    public const MODELLER_STYLED = [self::MENU, Pages::COCKPIT, Pages::STOCK, Pages::PURCHASE, Pages::SETTINGS, Pages::MODULE, Pages::INBOUND, Pages::CONTACTS, Pages::CHOICES, Pages::TEST_RULES, Pages::FIELD_CATALOGUE];

    /** The modeller's menu, which every Taxmod module hangs its pages under (D-1028). */
    public const PARENT = 'taxmod';

    /** D-1146: the filter the manufacturing module books a build's consumption through. */
    public const BOOK_BUILD = 'taxmod_stock_book_build';

    /** D-1146: the pick list reads where the things lie free, outside the order's box. */
    public const PLACES_OF = 'taxmod_stock_places_of';

    /** D-1146: the ticked lines of a pick list go into the box. */
    public const BOOK_PICK = 'taxmod_stock_book_pick';

    /** D-1528: what a subject's picks brought into a box goes back where it came from; others' goods there stay. */
    public const CANCEL_PICK = 'taxmod_stock_cancel_pick';

    /** D-1528 (W6): the places with a function (Wareneingang, Warenausgang), those inside them included. */
    public const PLACES_WITH_FUNCTION = 'taxmod_stock_places_with_function';

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

    /** D-1260: the goods of a sale leave as a Vorgang «Verkauf» naming the sale. */
    public const BOOK_SALE = 'taxmod_stock_book_sale';

    /** D-1260, D-1262: taking a sale back cancels its Vorgang «Verkauf» (D-1036) — by the Vorgang, or every open one of a sale. */
    public const CANCEL_SALE = 'taxmod_stock_cancel_sale';

    /** D-1260: an old sale without stock — «Nacherfassung» in, «Verkauf» out, in one act. */
    public const BOOK_BACKFILL = 'taxmod_stock_book_backfill';

    /** D-1260: material → lot → rest, for the sales module to pick lots from. */
    public const LOTS_OF = 'taxmod_stock_lots_of';

    /** D-1038: the modeller's inbox reports «versendet» / «zugestellt» for an order (Modeller ≥ 0.2.78, D-1326). */
    public const ORDER_EVENT = 'taxmod_stock_event';

    /** D-1328 (D9): longer strips to cut from, and the cuts booked, for the manufacturing module. */
    public const CUT_SOURCES = 'taxmod_stock_cut_sources';

    /** D-1535 (P1): the one cut plan of all pieces of a strip counted in pins, per place — read only. */
    public const PIN_CUT_PLANS = 'taxmod_stock_pin_cut_plans';

    /** D-1535 (P4): plain strip → its round-contact counterpart — read only. */
    public const ROUND_COUNTERPARTS = 'taxmod_stock_round_counterparts';
    public const BOOK_CUT    = 'taxmod_stock_book_cut';

    /** D-1301: the project boxes with their project, path and pieces, for the manufacturing module. */
    public const PROJECT_BOXES = 'taxmod_stock_project_boxes';

    /** D-1301: reservations for a subject (project, sale, production order) as one call each, for the manufacturing module. */
    public const RESERVE          = 'taxmod_stock_reserve';
    public const RESERVATIONS_OF  = 'taxmod_stock_reservations_of';
    public const RELEASE          = 'taxmod_stock_release';

    /** D-1301: «Box leer räumen» — back to where it came from. */
    public const BOOK_RETURN = 'taxmod_stock_book_return';

    /** D-1302: scrap from a build, as «Defekt/Ausschuss». */
    public const BOOK_SCRAP = 'taxmod_stock_book_scrap';
    /** D-1419: the test result of ticked lots, and scrapping them, for the production's step «Test». */
    public const TEST_RESULT = 'taxmod_stock_test_result';
    public const SCRAP_LOTS  = 'taxmod_stock_scrap_lots';
    public const TEST_LOTS   = 'taxmod_stock_test_lots';
    /** D-1433: whole lots to a place, with their test result in the same act. */
    public const MOVE_LOTS   = 'taxmod_stock_move_lots';
    /** D-1433 (E): the label button of one lot, the stock page's own (D-1296). */
    public const LOT_LABEL_BUTTON = 'taxmod_stock_lot_label_button';
    /** D-1425: the prices of what a build takes, and the calculation a finished lot carries. */
    public const BUILD_PRICES   = 'taxmod_stock_build_prices';
    public const COST_BREAKDOWN = 'taxmod_stock_cost_breakdown';

    /** D-1303: the stages of lots from production, and the place finished boards come in. */
    public const LOT_STAGES        = 'taxmod_stock_lot_stages';
    public const SET_LOT_STAGE     = 'taxmod_stock_set_lot_stage';
    public const PRODUCTION_INBOUND = 'taxmod_stock_production_inbound';
    public const TEST_PLACE        = 'taxmod_stock_test_place';

    /** «Aus Bestand zuweisen»: the materials with free stock below a group, for the Fertigung's chooser. */
    public const THINGS_IN_STOCK = 'taxmod_stock_things_in_stock';

    public function boot(): void
    {
        // Checked at runtime rather than with «Requires Plugins»: WordPress matches that header against the folder name, and the
        // modeller lives in a versioned folder on fambach.net, so activation was refused although it was active.
        add_action('plugins_loaded', [$this, 'bootWhenModellerIsThere']);
    }

    /** The modeller's answer whether admin-post.php or admin-ajax.php serve this request (D-1187). */
    private static function handlesAdminActions(): bool
    {
        return \Taxmod\WordPress\Plugin::handlesAdminActions();
    }

    public function bootWhenModellerIsThere(): void
    {
        // *The one door into the model and the shared confirm dialog (D-1186) came with the modeller 0.1.175; the Ausbuchen form needs the narrowed choosers of 0.2.64 (D-1298), every form the toolbar of 0.2.67 (D-1308), the view dialog of 0.2.68 and its script of 0.2.69 (D-1309; the 0.2.69 release still says 0.2.68 in its header, so the gate asks 0.2.68); ListPage::grid of 0.2.120 (D-1409); ListPage::number of 0.2.130 (D-1423); ModelDoor::remembering of 0.2.132 (D-1186, G5); DialogNumber at the edge of 0.2.133 (D-1431); number columns, the complete answer and the searched texts of a row of 0.2.146 (D-1462); listBar, iconButton, date, dateCell, money of 0.2.172 (D-1500); an older one is named
        // rather than failing on a page.*
        // Asked by version, not by loading the classes: method_exists() compiled the 43 KB list page on every page view (D-1187).
        // D-1501/D-1504: the fields go through ListPage::field; Tick and Choice came with the modeller 0.2.175. D-1546: the test rules' node chooses under several roots since 0.2.200.
        if (! class_exists(\Taxmod\WordPress\Plugin::class, false) || version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.2.200', '<')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Stock braucht den aktiven Taxonomy Modeller ab 0.2.200.', 'taxmod-stock') . '</p></div>';
            });

            return;
        }

        add_filter('taxmod_config_tabs', [SettingsTab::class, 'register']);
        // D-1480: a merged material takes its doubled Lager record to the trash, or the merge is vetoed — applied by the modeller.
        // D-1499: on the modeller's core dispatcher (`taxmod_listen`, D-1498) where it has one; the filter only for an older modeller.
        if (class_exists(\Taxmod\Core\Event\RecordsMerging::class)) {
            add_action('taxmod_listen', static function (\Taxmod\Core\Event\ListenerProvider $listeners): void {
                $listeners->listen(\Taxmod\Core\Event\RecordsMerging::class, static function (\Taxmod\Core\Event\RecordsMerging $merging): void {
                    $mine = (new StockService())->mergeAnswer([], $merging->from, $merging->into);
                    $merging->veto((string) ($mine['veto'] ?? ''));
                    $merging->trash(...array_map('intval', $mine['trash'] ?? []));
                });
            });
        } else {
            add_filter('taxmod_merge_record', static fn (mixed $answer, int $from, int $into): array => (new StockService())->mergeAnswer(is_array($answer) ? $answer : [], $from, $into), 10, 3);
        }
        // D-1209: the stock for a reader over MCP, in the modeller's category (registered by the modeller beforehand).
        add_action('wp_abilities_api_init', static fn () => (new StockAbility())->register());
        add_action('wp_abilities_api_init', static fn () => (new CorrectionAbility())->register());
        add_action('wp_abilities_api_init', static fn () => (new TransferAbility())->register());
        // Only where a setting is saved (options.php) or read over REST: naming the options compiled the 100 KB stock service on every page view (D-1187).
        if (($GLOBALS['pagenow'] ?? '') === 'options.php') {
            add_action('admin_init', [$this, 'registerSettings']);
            // D-1440: booking is part of «Angekommen»; the old address of «Einbuchen» goes there, the split form stays.
            add_action('admin_init', static function (): void {
                $tab = sanitize_key((string) ($_GET['tab'] ?? ''));

                if (sanitize_key((string) ($_GET['page'] ?? '')) === Pages::STOCK && in_array($tab, ['', 'wareneingang'], true) && absint($_GET['split'] ?? 0) === 0 && current_user_can('manage_options')) {
                    wp_safe_redirect(InboundPage::url(array_filter(['tab' => InboundStage::Arrived->value, 's' => sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')))])));
                    exit;
                }
            });
            // D-1259: the old branch rows move to the groups once, as soon as the modeller can hold them.
            add_action('admin_init', static fn () => current_user_can('manage_options') ? (new StockService())->moveBranchSettingsToGroups() : null);
        }
        add_action('rest_api_init', [$this, 'registerSettings']);
        // After the modeller (priority 10) has created its menu.
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        // Last, when every module has hung its page under the Taxmod menu, and before admin_head takes the entries out.
        add_action('admin_menu', [ModulePage::class, 'capture'], PHP_INT_MAX);
        if (self::handlesAdminActions()) {
            add_action('admin_post_' . StockPage::ACTION, static fn () => Acts::run(static fn () => self::serially(static fn () => (new StockPage())->handle())));
            add_action('admin_post_' . StockPage::POSITIONS, static fn () => Acts::json(static fn () => (new StockPage())->positions()));
            add_action('admin_post_' . StockPage::ASSIGN, static fn () => Acts::json(static fn () => self::serially(static fn () => (new StockPage())->assign(), true)));
            add_action('admin_post_' . StockPage::GROUP_PROPERTIES, static fn () => Acts::run(static fn () => (new StockPage())->saveGroupProperties()));
            add_action('admin_post_' . StockPage::FORM_LABELS, static fn () => Acts::run(static fn () => (new StockPage())->saveFormLabels()));
            add_action('admin_post_' . StockPage::MAKER, static fn () => Acts::json(static fn () => (new StockPage())->maker()));
            add_action('admin_post_' . StockPage::THING_SEARCH, static fn () => Acts::json(static fn () => (new StockPage())->thingSearch()));
            add_action('admin_post_' . StockPage::THING_UNIT, static fn () => Acts::json(static fn () => (new StockPage())->thingUnit()));
            add_action('admin_post_' . MaterialPicker::PLACES, static fn () => Acts::json(static fn () => MaterialPicker::placesAnswer()));
            add_action('admin_post_' . StockPage::THING_NODES, static fn () => Acts::json(static fn () => (new StockPage())->thingNodes()));
            add_action('admin_post_' . StockPage::THING_BRANCH, static fn () => Acts::json(static fn () => (new StockPage())->thingBranch()));
            add_action('admin_post_' . StockPage::LOT_SEARCH, static fn () => Acts::json(static fn () => (new StockPage())->lotSearch()));
            add_action('admin_post_' . StockPage::LOT_BRANCH, static fn () => Acts::json(static fn () => (new StockPage())->lotBranch()));
            add_action('admin_post_' . InboundPage::ACTION, static fn () => Acts::run(static fn () => self::serially(static fn () => (new InboundPage())->handle())));
            add_action('admin_post_' . PurchasePage::ACTION, static fn () => Acts::run(static fn () => self::serially(static fn () => (new PurchasePage())->handle())));
            add_action('admin_post_' . ContactsPage::ACTION, static fn () => Acts::run(static fn () => (new ContactsPage())->handle()));
            // D-1474: Stammdaten › Auswahllisten.
            add_action('admin_post_' . ChoiceListsPage::ACTION, static fn () => Acts::run(static fn () => (new ChoiceListsPage())->handle()));
            // D-1541: Einstellungen › Testregeln.
            add_action('admin_post_' . TestRulesPage::ACTION, static fn () => Acts::run(static fn () => (new TestRulesPage())->handle()));
            add_action('admin_post_' . SettingsPage::ACTION, static fn () => Acts::run(static fn () => (new SettingsPage())->handle()));
            add_action('admin_post_' . SettingsPage::USER_ACTION, static fn () => Acts::run(static fn () => (new SettingsPage())->handleUser()));
            add_action('admin_post_' . LogisticsShell::FULLSCREEN_ACTION, static fn () => Acts::run([LogisticsShell::class, 'switchFullscreen']));
        }
        // D-1235, D-1238: the print thread asks for one material, lot or place label, as the dialog would build it.
        add_filter('taxmod_label_for', static fn (?array $found, string $prefix, int $id, int $template, int $printer): ?array => $found ?? (in_array($prefix, StockPage::LABEL_PREFIXES, true) ? (new StockPage())->labelFor($prefix, $id, $template, $printer) : null), 10, 5);
        // D-1259: what a group of material carries for the stock, inherited down the tree and set in its Lager view.
        add_filter('taxmod_node_properties', static fn (array $properties): array => $properties + [
            StockService::TEST_PROPERTY  => __('Nach Wareneingang testen', 'taxmod-stock'),
            StockService::PLACE_PROPERTY => ['label' => __('Standard-Lagerort', 'taxmod-stock'), 'type' => 'id'],
            StockService::MATERIAL_LABEL_PROPERTY => ['label' => __('Standard-Etikett Material', 'taxmod-stock'), 'type' => 'id'],
            StockService::LOT_LABEL_PROPERTY      => ['label' => __('Standard-Etikett Posten', 'taxmod-stock'), 'type' => 'id'],
        ]);
        // D-1519 R2: the preselections of Bauform, group and place type, offered to the labels module as print rules.
        add_filter('taxmod_label_rule_proposals', static fn (mixed $proposals): array => array_merge(is_array($proposals) ? $proposals : [], (new StockService())->labelRuleProposals()));
        // D-1546, D-1548: where material lies, as «Material steht in» says (D-1522) — the roots of a rule's node in other modules.
        add_filter(StockService::MATERIAL_ROOTS, static fn (mixed $roots): array => (new StockService())->materialRoots());
        add_filter(self::BOOK_BUILD, [self::class, 'bookBuild'], 10, 2);
        add_filter(self::PLACES_OF, [self::class, 'placesOf'], 10, 2);
        // D-1387: the material search as a building block for other modules' dialogs.
        add_filter(MaterialPicker::FILTER, [MaterialPicker::class, 'filter'], 10, 2);
        add_filter(self::BOOK_PICK, [self::class, 'bookPick'], 10, 2);
        add_filter(self::CANCEL_PICK, [self::class, 'cancelPick'], 10, 2);
        add_filter(self::PLACES_WITH_FUNCTION, [self::class, 'placesWithFunction'], 10, 2);
        add_filter(self::BOOK_OUTPUT, [self::class, 'bookOutput'], 10, 2);
        add_filter(self::CREATE_THING, [self::class, 'createThing'], 10, 2);
        add_filter(self::LOSS_LOTS, [self::class, 'lossLots']);
        add_filter(self::BOOK_LOSS, [self::class, 'bookLoss'], 10, 2);
        add_filter(self::CANCEL_LOSS, [self::class, 'cancelLoss'], 10, 2);
        add_filter(self::BOOK_SALE, [self::class, 'bookSale'], 10, 2);
        add_filter(self::CANCEL_SALE, [self::class, 'cancelSale'], 10, 2);
        add_filter(self::BOOK_BACKFILL, [self::class, 'bookBackfill'], 10, 2);
        add_filter(self::LOTS_OF, [self::class, 'lotsOf'], 10, 2);
        // D-1499: the inbox's report as a core event where the modeller sends one; the action only for an older modeller.
        if (class_exists(\Taxmod\Core\Event\OrderReported::class)) {
            add_action('taxmod_listen', static function (\Taxmod\Core\Event\ListenerProvider $listeners): void {
                $listeners->listen(\Taxmod\Core\Event\OrderReported::class, static fn (\Taxmod\Core\Event\OrderReported $report) => self::orderEvent($report->orderRecordId, $report->kind));
            });
        } else {
            add_action(self::ORDER_EVENT, [self::class, 'orderEvent'], 10, 2);
        }
        add_filter(self::CUT_SOURCES, [self::class, 'cutSources'], 10, 2);
        add_filter(self::PIN_CUT_PLANS, [self::class, 'pinCutPlans'], 10, 2);
        add_filter(self::ROUND_COUNTERPARTS, [self::class, 'roundCounterparts'], 10, 2);
        add_filter(self::BOOK_CUT, [self::class, 'bookCut'], 10, 2);
        add_filter(self::PROJECT_BOXES, [self::class, 'projectBoxes'], 10, 2);
        add_filter(self::RESERVE, [self::class, 'reserve'], 10, 2);
        add_filter(self::RESERVATIONS_OF, [self::class, 'reservationsOf'], 10, 2);
        add_filter(self::RELEASE, [self::class, 'release'], 10, 2);
        add_filter(self::BOOK_RETURN, [self::class, 'bookReturn'], 10, 2);
        add_filter(self::BOOK_SCRAP, [self::class, 'bookScrap'], 10, 2);
        add_filter(self::TEST_RESULT, [self::class, 'testResult'], 10, 2);
        add_filter(self::SCRAP_LOTS, [self::class, 'scrapLots'], 10, 2);
        add_filter(self::TEST_LOTS, [self::class, 'testLots'], 10, 2);
        add_filter(self::MOVE_LOTS, [self::class, 'moveLots'], 10, 2);
        add_filter(self::LOT_LABEL_BUTTON, [self::class, 'lotLabelButton'], 10, 2);
        add_filter(self::BUILD_PRICES, [self::class, 'buildPrices'], 10, 2);
        add_filter(self::COST_BREAKDOWN, [self::class, 'costBreakdown'], 10, 2);
        add_filter(self::LOT_STAGES, [self::class, 'lotStages'], 10, 2);
        add_filter(self::SET_LOT_STAGE, [self::class, 'setLotStage'], 10, 2);
        add_filter(self::PRODUCTION_INBOUND, [self::class, 'productionInbound'], 10, 1);
        add_filter(self::TEST_PLACE, [self::class, 'testPlace'], 10, 1);
        add_filter(self::THINGS_IN_STOCK, [self::class, 'thingsInStock'], 10, 2);
        // D-1315 with the modeller's D-1334: the kind of a place with places inside changes only through «Platzart ändern …».
        add_filter('taxmod_claimed_fields', [self::class, 'claimedFields'], 10, 3);
        // D-1147: the rows of the material and purchase lists draw their details when opened.
        // The filter's name stands as text, so an older modeller without it does not stop the module.
        add_filter('taxmod_list_details', static fn (?string $html, string $source, array $args): ?string => match ($source) {
            StockPage::DETAILS_MATERIAL => (new StockPage())->listDetails($html, $source, $args),
            ContactsPage::DETAILS       => (new ContactsPage())->listDetails($html, $source, $args),
            default                     => $html,
        }, 10, 3);
        // D-1203: the modeller's scanner test page opens a scanned place, box or material here.
        add_filter('taxmod_scan_links', [self::class, 'scanLinks'], 10, 3);
        // D-1483: a record this module keeps opens on its page wherever the modeller links to it.
        add_filter('taxmod_record_home', [self::class, 'recordHome'], 10, 3);
    }

    /** @var array<string, array<int, true>>|null The branches whose records open here, read once per request: key → node ids. */
    private static ?array $homes = null;

    /**
     * D-1483: orders open in logistics, materials and lots at their stock, places and boxes in the storage structure, sales in
     * the sales module. The node the modeller names is tried first; only a node above every branch asks for the record's own.
     */
    public static function recordHome(?string $url, int $record, int $node): ?string
    {
        if ($url !== null && $url !== '' || $record <= 0) {
            return $url;
        }

        $stock = self::reading();

        try {
            $key = self::homeOf($node, $stock) ?? self::homeOf($stock->nodesOf([$record])[$record] ?? 0, $stock);
        } catch (StockError) {
            return $url;
        }

        return match ($key) {
            'order'    => InboundPage::url(['order' => $record]),
            'lot'      => array_values(self::lotLink($record))[0] ?? $url,
            'location' => add_query_arg(['page' => Pages::STOCK, 'tab' => 'lagerstruktur', 'ort' => $record], admin_url('admin.php')),
            'lager'    => StockPage::thingUrl($record, 'bestand'),
            'sale'     => add_query_arg(['page' => Pages::MODULE, 'module' => 'taxmod-verkauf'], admin_url('admin.php')),
            default    => $url,
        };
    }

    /** The branch `$node` lies in — the narrower ones first, since lots and places may hang below the materials. */
    private static function homeOf(int $node, StockService $stock): ?string
    {
        if ($node <= 0) {
            return null;
        }

        if (self::$homes === null) {
            $s           = $stock->structure();
            $model       = new ModelGateway();
            self::$homes = [];

            foreach (['lot', 'location', 'order', 'sale', 'lager'] as $key) {
                self::$homes[$key] = $s->hasNode($key) ? array_fill_keys(array_keys($model->tree($s->node($key))), true) : [];
            }
        }

        foreach (self::$homes as $key => $nodes) {
            if (isset($nodes[$node])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Links for a scanned label code (D-1203): O and K open the place in the storage structure, M the material's stock.
     *
     * @param array<string, string> $links label → URL
     * @return array<string, string>
     */
    public static function scanLinks(array $links, string $kind, int $record): array
    {
        return $links + match ($kind) {
            'O', 'K' => [__('Lagerort öffnen', 'taxmod-stock') => add_query_arg(['page' => Pages::STOCK, 'tab' => 'lagerstruktur', 'ort' => $record], admin_url('admin.php'))],
            'M'      => [__('Material im Lager öffnen', 'taxmod-stock') => StockPage::thingUrl($record, 'bestand')],
            // D-1223: a lot label opens the stock of its material, where the lot stands with its places.
            'P'      => self::lotLink($record),
            default  => [],
        };
    }

    /** The service the read filters share within one request: a page of the manufacturing module asks several of them. */
    private static ?StockService $reading = null;

    /** The module's write count when {@see $reading} was made. */
    private static int $readAt = -1;

    /** One read of the stock per request for every read filter; any write of the module in between starts it afresh. */
    private static function reading(): StockService
    {
        if (self::$reading === null || self::$readAt !== ModelGateway::$writes) {
            self::$reading = new StockService();
            self::$readAt  = ModelGateway::$writes;
        }

        return self::$reading;
    }

    /** A write filter's own service; what the read filters read before it is forgotten. */
    private static function writing(): StockService
    {
        self::$reading = null;

        return new StockService();
    }

    /** @return array<string, string> */
    private static function lotLink(int $lot): array
    {
        $stock = self::reading();
        $s     = $stock->structure();
        $thing = $s->hasField('lot_thing') ? (int) $stock->table('lot')->first($lot, $s->field('lot_thing')) : 0;

        return $thing > 0 ? [__('Charge im Lager öffnen', 'taxmod-stock') => StockPage::thingUrl($thing, 'bestand')] : [];
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
            return self::writing()->bookBuild(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Fertigung', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1328: `apply_filters('taxmod_stock_cut_sources', null, ['things' => [thing => missing pieces], 'except' => box])`
     * answers thing → list of ['thing', 'pins', 'lot', 'place', 'left'], shortest strip first — read only, one call.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function cutSources(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args   = is_array($args) ? $args : [];
        $wanted = [];

        foreach (is_array($args['things'] ?? null) ? $args['things'] : [] as $thing => $pieces) {
            if (absint($thing) > 0 && is_numeric($pieces) && (float) $pieces > 0) {
                $wanted[absint($thing)] = (float) $pieces;
            }
        }

        try {
            return self::reading()->cutSources($wanted, absint($args['except'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1535 (P1): `apply_filters('taxmod_stock_pin_cut_plans', null, ['thing' => id, 'places' => [place, …], 'cuts'
     * => [pins per piece => pieces]])` answers place → ['opened' => whole strips opened, 'strips' => [['pins', 'whole',
     * 'cuts', 'rest', 'scrap'], …]] — read only, places the pieces do not fit are left out.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function pinCutPlans(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args   = is_array($args) ? $args : [];
        $places = array_values(array_filter(array_map('absint', is_array($args['places'] ?? null) ? $args['places'] : [])));
        $cuts   = self::cutsOf(is_array($args['cuts'] ?? null) ? $args['cuts'] : []);

        try {
            return self::reading()->pinCutPlans(absint($args['thing'] ?? 0), $places, $cuts);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1535 (P4): `apply_filters('taxmod_stock_round_counterparts', null, ['things' => [id, …]])` answers plain strip →
     * its round-contact counterpart, only where there is one — read only, one call.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function roundCounterparts(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args   = is_array($args) ? $args : [];
        $things = array_values(array_unique(array_filter(array_map('absint', is_array($args['things'] ?? null) ? $args['things'] : []))));

        try {
            return self::reading()->roundCounterparts($things);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * Pins per piece → pieces, both whole numbers above 0.
     *
     * @param array<mixed> $cuts `mixed`: as the caller sends it
     * @return array<int, int>
     */
    private static function cutsOf(array $cuts): array
    {
        $out = [];

        foreach ($cuts as $pins => $pieces) {
            if (absint($pins) > 0 && is_numeric($pieces) && (int) $pieces > 0) {
                $out[absint($pins)] = (int) $pieces;
            }
        }

        return $out;
    }

    /**
     * D-1301: `apply_filters('taxmod_stock_project_boxes', null, [])` → `[place => ['project' => id, 'label' => path,
     * 'pieces' => n]]`, the pieces counted in the box and every place below it.
     */
    public static function projectBoxes(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        try {
            return self::reading()->projectBoxSummary();
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1301: `apply_filters('taxmod_stock_reserve', null, ['subject' => id, 'rows' => [['thing' => id, 'quantity' => n,
     * 'lot' => id|0, 'place' => id|0, 'position' => bill-of-materials position|0], …], 'deadline' => Y-m-d|'', 'date' => Y-m-d, 'title' => …])` — all rows into the subject's open
     * reservation, in one act. Answers the modeller's answer.
     */
    public static function reserve(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args     = is_array($args) ? $args : [];
        $date     = sanitize_text_field((string) ($args['date'] ?? ''));
        $deadline = sanitize_text_field((string) ($args['deadline'] ?? ''));
        $rows     = [];
        $places   = [];

        foreach (is_array($args['rows'] ?? null) ? $args['rows'] : [] as $row) {
            if (is_array($row) && absint($row['thing'] ?? 0) > 0 && is_numeric($row['quantity'] ?? null) && (float) $row['quantity'] > 0) {
                if (absint($row['place'] ?? 0) > 0) {
                    $places[count($rows)] = absint($row['place']);
                }

                $rows[] = new ReservationRow(absint($row['thing']), (string) (float) $row['quantity'], absint($row['lot'] ?? 0) ?: null, absint($row['position'] ?? 0) ?: null);
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ($deadline !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) !== 1)) {
            return new \WP_Error('taxmod_stock_not_a_date', __('Datum fehlt oder ist kein Datum (JJJJ-MM-TT).', 'taxmod-stock'));
        }

        if ($rows === []) {
            return new \WP_Error('taxmod_stock_no_rows', __('Keine Position zum Reservieren.', 'taxmod-stock'));
        }

        try {
            return self::writing()->reserveRows(absint($args['subject'] ?? 0), $deadline, $rows, $date, sanitize_text_field((string) ($args['title'] ?? __('Reservierung', 'taxmod-stock'))), $places);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1301: `apply_filters('taxmod_stock_reservations_of', null, ['subjects' => [id, …]])` → `[subject => [['deal', 'line',
     * 'thing', 'lot', 'quantity', 'places' => [place => n], 'position' => bill-of-materials position|0], …]]`, the open positions only.
     */
    public static function reservationsOf(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args     = is_array($args) ? $args : [];
        $subjects = array_values(array_unique(array_filter(array_map('absint', is_array($args['subjects'] ?? null) ? $args['subjects'] : []))));

        try {
            return $subjects === [] ? [] : self::reading()->reservationsOf($subjects);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1301: `apply_filters('taxmod_stock_release', null, ['subject' => id, 'lines' => [line, …]|null])` gives the
     * subject's reservation free — whole, or those positions — in one Storno.
     */
    public static function release(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args  = is_array($args) ? $args : [];
        $lines = is_array($args['lines'] ?? null) ? array_values(array_filter(array_map('absint', $args['lines']))) : null;

        try {
            return self::writing()->releaseReservations(absint($args['subject'] ?? 0), $lines, current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1301: `apply_filters('taxmod_stock_book_return', null, ['box' => place, 'reference' => order, 'date' => Y-m-d,
     * 'title' => …])` moves what lies in the box back to its places, one Umbuchung; answers the Vorgang.
     */
    public static function bookReturn(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $date = sanitize_text_field((string) ($args['date'] ?? current_time('Y-m-d')));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', __('Datum fehlt oder ist kein Datum (JJJJ-MM-TT).', 'taxmod-stock'));
        }

        try {
            return self::writing()->bookReturn(absint($args['box'] ?? 0), absint($args['reference'] ?? 0), $date, sanitize_text_field((string) ($args['title'] ?? __('Box leer räumen', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1528: `apply_filters('taxmod_stock_cancel_pick', null, ['box' => place, 'reference' => subject, 'title' => …, 'date' =>
     * Y-m-d])` → the Vorgang's id: what the subject's picks brought into the box and still lies there goes back.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function cancelPick(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $date = sanitize_text_field((string) ($args['date'] ?? current_time('Y-m-d')));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return self::writing()->bookUnpick(absint($args['box'] ?? 0), absint($args['reference'] ?? 0), $date, sanitize_text_field((string) ($args['title'] ?? __('Bereitstellung zurück', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1528 (W6): `apply_filters('taxmod_stock_places_with_function', null, ['function' => 'outbound'|'inbound'])` → the place
     * ids with that function, those inside them included. «Test» and «Klärung» are not answered until D-1503 is decided.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     * @return list<int>|\WP_Error
     */
    public static function placesWithFunction(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $key = match (sanitize_key((string) (is_array($args) ? ($args['function'] ?? '') : ''))) {
            'outbound' => PlaceFunctions::OUTBOUND,
            'inbound'  => PlaceFunctions::INBOUND,
            default    => null,
        };

        try {
            return $key === null ? [] : self::reading()->placeFunctions()->with($key);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1302: `apply_filters('taxmod_stock_book_scrap', null, ['reference' => order, 'place' => box, 'lines' => [thing =>
     * pieces], 'reason' => 'Ausschuss Fertigung[: …]', 'title' => …, 'date' => Y-m-d])` → the Vorgang's id.
     */
    public static function bookScrap(mixed $previous, mixed $args): int|\WP_Error
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

        foreach (is_array($args['lines'] ?? null) ? $args['lines'] : [] as $thing => $pieces) {
            if (absint($thing) > 0 && is_numeric($pieces) && (float) $pieces > 0) {
                $lines[absint($thing)] = (float) $pieces;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return self::writing()->bookScrap(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, sanitize_text_field((string) ($args['reason'] ?? __('Ausschuss Fertigung', 'taxmod-stock'))), $date, sanitize_text_field((string) ($args['title'] ?? __('Ausschuss', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1303: `apply_filters('taxmod_stock_lot_stages', null, ['lots' => [id, …]])` → `['lots' => [lot => stage value id|0],
     * 'stages' => [key => value id]]`, the configured stages in their order; with `['place' => id]` instead every lot at
     * the place and below it, as `lot => ['stage', 'thing', 'quantity']`.
     */
    public static function lotStages(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $lots = array_values(array_unique(array_filter(array_map('absint', is_array($args['lots'] ?? null) ? $args['lots'] : []))));

        try {
            $stock = self::reading();
            $s     = $stock->structure();

            $keys  = array_values(array_filter(Structure::STAGES, $s->hasValue(...)));

            $place = absint($args['place'] ?? 0);
            $found = $place > 0 ? $stock->lotStagesAt($place) : ($lots === [] ? [] : $stock->lotStages($lots));

            return ['lots' => $found, 'stages' => array_combine($keys, array_map($s->value(...), $keys))];
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /** D-1303: `apply_filters('taxmod_stock_set_lot_stage', null, ['lots' => [id, …], 'stage' => value id])` → the modeller's answer. */
    public static function setLotStage(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $lots = array_values(array_unique(array_filter(array_map('absint', is_array($args['lots'] ?? null) ? $args['lots'] : []))));

        try {
            return self::writing()->setLotStage($lots, absint($args['stage'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1315: the field «Platzart» of a place with fixed places inside, claimed for the dialog «Platzart ändern …» (D-1334).
     *
     * @param array<int, array{label: string, url: string}> $claims
     * @return array<int, array{label: string, url: string}>
     */
    public static function claimedFields(mixed $claims, int $recordId, int $nodeId): array
    {
        $claims = is_array($claims) ? $claims : [];

        try {
            $stock = self::reading();
            $s     = $stock->structure();

            if (! $s->hasNode('location') || $nodeId !== $s->node('location') || ! $s->hasField('location_place_type') || $stock->locationTree()->nestedBelow($recordId) === []) {
                return $claims;
            }

            $claims[$s->field('location_place_type')] = ['label' => __('Platzart ändern …', 'taxmod-stock'), 'url' => StockPage::kindChangeUrl($recordId)];
        } catch (StockError) {
            return $claims;
        }

        return $claims;
    }

    /**
     * D-1419: `apply_filters('taxmod_stock_test_result', null, ['lots' => [id, …], 'result' => 'passed'|'failed', 'note' => '…',
     * 'date' => Y-m-d])` → the change number (undo it with `undo_act`, D-1410) or a WP_Error. «passed» sets «funktioniert»,
     * «failed» «defekt»; both free the lot (D-1342). D-1433: «untested» sets «ungetestet» with the note as finding and blocks
     * it again (rework done, back to test).
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the result as the caller sends it; `mixed`: checked here
     */
    public static function testResult(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args   = is_array($args) ? $args : [];
        $date   = sanitize_text_field((string) ($args['date'] ?? ''));
        $result = self::resultOf($args['result'] ?? '');

        if ($result === false) {
            return new \WP_Error('taxmod_stock_no_result', __('Das Testergebnis muss «passed», «failed» oder «untested» sein.', 'taxmod-stock'));
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            $answer = self::writing()->recordTests(array_values(array_map('absint', is_array($args['lots'] ?? null) ? $args['lots'] : [])), $result, sanitize_textarea_field((string) ($args['note'] ?? '')), $date);

            return (int) ($answer['act'] ?? 0);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1433 (E): `apply_filters('taxmod_stock_lot_label_button', '', ['lot' => id])` → the HTML of the lot's label button
     * (template preselected by group or form, its batch), printed only on a click; '' for no such lot or no right.
     *
     * @param mixed $previous a button another callback drew already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function lotLabelButton(mixed $previous, mixed $args): string
    {
        if (is_string($previous) && $previous !== '') {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return '';
        }

        try {
            return (new StockPage())->lotLabelButton(absint((is_array($args) ? $args : [])['lot'] ?? 0));
        } catch (StockError) {
            return '';
        }
    }

    /** @return TestResult|false|null the result asked for; null is «untested» (D-1433), false none of the three */
    private static function resultOf(mixed $given): TestResult|false|null
    {
        return match (sanitize_key((string) $given)) {
            'passed'   => TestResult::Ok,
            'failed'   => TestResult::Defect,
            'untested' => null,
            default    => false,
        };
    }

    /**
     * D-1433: `apply_filters('taxmod_stock_move_lots', null, ['lots' => [id, …], 'place' => id, 'date' => Y-m-d, 'title' => …,
     * 'reference' => order (optional), 'result' => 'passed'|'untested' (optional), 'note' => …])` → the change number or a
     * WP_Error. Whole lots from wherever they lie to the place (D-1233), the result in the same act — one undo (D-1410).
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function moveLots(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args   = is_array($args) ? $args : [];
        $date   = sanitize_text_field((string) ($args['date'] ?? ''));
        $result = isset($args['result']) ? self::resultOf($args['result']) : false;

        if (isset($args['result']) && ! in_array($result, [TestResult::Ok, null], true)) {
            return new \WP_Error('taxmod_stock_no_result', __('Beim Umbuchen darf das Testergebnis nur «passed» oder «untested» sein.', 'taxmod-stock'));
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            $answer = self::writing()->moveLotsTested(
                array_values(array_map('absint', is_array($args['lots'] ?? null) ? $args['lots'] : [])),
                absint($args['place'] ?? 0),
                $date,
                sanitize_text_field((string) ($args['title'] ?? __('Umbuchung', 'taxmod-stock'))),
                absint($args['reference'] ?? 0),
                $result,
                sanitize_textarea_field((string) ($args['note'] ?? ''))
            );

            return (int) ($answer['act'] ?? 0);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1419: `apply_filters('taxmod_stock_test_lots', null, ['reference' => order])` → lot => ['thing', 'place', 'quantity',
     * 'state' => untested|passed|failed|scrapped, 'tested_on', 'note'] for the lots the order's receipts brought in, or a WP_Error.
     * D-1433: with ['references' => [order, …]] → order → (lot → the same row), all orders in one read.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the question as the caller sends it; `mixed`: checked here
     */
    public static function testLots(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        try {
            $args = is_array($args) ? $args : [];

            // D-1433: the order list asks for all its orders at once.
            if (is_array($args['references'] ?? null)) {
                return self::reading()->testLotsOf(array_values(array_filter(array_map('absint', $args['references']))));
            }

            return self::reading()->testLots(absint($args['reference'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1425: `apply_filters('taxmod_stock_build_prices', null, ['reference' => order, 'place' => box, 'lines' => [thing => pieces]])`
     * → ['parts' => thing → [['lot', 'quantity', 'unit_price' (euro per piece|null), 'source' => lot|last_purchase|none], …],
     * 'scrap' => thing → pieces this order scrapped] or a WP_Error — read only, the plan `taxmod_stock_book_build` books.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function buildPrices(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args  = is_array($args) ? $args : [];
        $lines = [];

        foreach (is_array($args['lines'] ?? null) ? $args['lines'] : [] as $thing => $quantity) {
            if (absint($thing) > 0 && is_numeric($quantity)) {
                $lines[absint($thing)] = (float) $quantity;
            }
        }

        try {
            return (new InboundService(self::reading()))->buildPrices(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines);
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1425: `apply_filters('taxmod_stock_cost_breakdown', null, ['lots' => [id, …]])` → lot → breakdown|null, or with
     * `['thing' => id]` the newest breakdown of that material|null. A breakdown: parts [{thing, lot, quantity, unit_price|null,
     * source}], parts_total, complete, setup {hours, wage, divisor, amount}, work {minutes|null, wage, amount}, scrap
     * [{thing, quantity}], total — per board in euro — and order, date, deal (the receipt).
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     * @return array<int|string, mixed>|null|\WP_Error
     */
    public static function costBreakdown(mixed $previous, mixed $args): array|null|\WP_Error
    {
        if ($previous !== null) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];

        if (isset($args['thing'])) {
            return self::reading()->latestCostBreakdown(absint($args['thing']));
        }

        return self::reading()->costBreakdown(array_values(array_filter(array_map('absint', is_array($args['lots'] ?? null) ? $args['lots'] : []))));
    }

    /**
     * D-1419: `apply_filters('taxmod_stock_scrap_lots', null, ['lots' => [id, …], 'reference' => order, 'reason' => '…',
     * 'title' => …, 'date' => Y-m-d])` → the Vorgang «Defekt/Ausschuss»'s id or a WP_Error; the lots leave whole and their
     * test report says «defekt», one act.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function scrapLots(mixed $previous, mixed $args): int|\WP_Error
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
            return self::writing()->scrapLots(
                array_values(array_map('absint', is_array($args['lots'] ?? null) ? $args['lots'] : [])),
                absint($args['reference'] ?? 0),
                sanitize_text_field((string) ($args['reason'] ?? __('Ausschuss Fertigung', 'taxmod-stock'))),
                $date,
                sanitize_text_field((string) ($args['title'] ?? __('Ausschuss', 'taxmod-stock')))
            );
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /** D-1342: `apply_filters('taxmod_stock_test_place', null)` → the place «Test» (D-1111 setting) untested boards come in at, 0 while none is set. */
    public static function testPlace(mixed $previous): int
    {
        if (is_int($previous) && $previous > 0) {
            return $previous;
        }

        try {
            $stock = self::reading();
            $place = (int) ($stock->testSettings([])->place ?? 0);

            return $place > 0 && $stock->table('location')->has($place) ? $place : 0;
        } catch (StockError) {
            return 0;
        }
    }

    /** D-1303: `apply_filters('taxmod_stock_production_inbound', null)` → the place finished boards come in, 0 while none is set. */
    public static function productionInbound(mixed $previous): int
    {
        if (is_int($previous) && $previous > 0) {
            return $previous;
        }

        try {
            return self::reading()->productionInbound();
        } catch (StockError) {
            return 0;
        }
    }

    /**
     * D-1328: `apply_filters('taxmod_stock_book_cut', null, ['reference' => order, 'into' => box, 'cuts' => [['from' =>
     * lot, 'thing' => material, 'pieces' => n], …], 'title' => …, 'date' => Y-m-d])` books all cuts in one Vorgang.
     * Answers its id or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function bookCut(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $date = sanitize_text_field((string) ($args['date'] ?? ''));
        $cuts = [];

        foreach (is_array($args['cuts'] ?? null) ? $args['cuts'] : [] as $cut) {
            if (is_array($cut) && absint($cut['from'] ?? 0) > 0 && absint($cut['thing'] ?? 0) > 0 && absint($cut['pieces'] ?? 0) > 0) {
                $cuts[] = ['from' => absint($cut['from']), 'thing' => absint($cut['thing']), 'pieces' => absint($cut['pieces'])];
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', __('Datum fehlt oder ist kein Datum (JJJJ-MM-TT).', 'taxmod-stock'));
        }

        try {
            return self::writing()->bookCut(absint($args['reference'] ?? 0), absint($args['into'] ?? 0), $cuts, $date, sanitize_text_field((string) ($args['title'] ?? __('Ablängen', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1038: sets the order status the inbox's event names. No capability check: the event is not a request but the
     * modeller's own import, which may run from cron after its checks; it writes nothing but the forward status.
     */
    public static function orderEvent(mixed $order, mixed $event): void
    {
        try {
            self::writing()->orderEvent(absint($order), sanitize_key((string) $event));
        } catch (StockError $refused) {
            // CD-10: not silenced — the import goes on, the refusal is logged for the order.
            error_log(sprintf('taxmod-stock: status of order %d after «%s» not set: %s', absint($order), sanitize_key((string) $event), $refused->getMessage()));
        }
    }

    /**
     * D-1143 for wp_tax_manufacturing: `apply_filters('taxmod_stock_book_output', null, $args)` brings the finished things of
     * a build order into stock. `$args`: reference (the order's record), place (where they go), lines (thing → pieces, less
     * than 0 cancels what this order brought in), title, date (Y-m-d), tested (true: tested in the manufacturing, D-1177),
     * stage (D-1303: a key of the stages, e.g. «stage_from_production», set on the new lots in the same act), cost (D-1425:
     * the calculation per piece; its «total» in euro becomes each new lot's value in the same act).
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

        $cost  = is_array($args['cost'] ?? null) ? $args['cost'] : null;
        $total = $cost['total'] ?? null;

        if ($total !== null && (! is_numeric($total) || (float) $total < 0.0)) {
            return new \WP_Error('taxmod_stock_not_a_price', __('Der Stückpreis der Kalkulation muss eine Zahl ab 0 sein.', 'taxmod-stock'));
        }

        try {
            // D-1425: the inputs are kept with the receipt where the caller sent them (set-up given); the total alone is the lot's value.
            $calculation = $cost !== null && is_array($cost['setup'] ?? null) ? Calculation::fromCost($cost) : null;

            return self::writing()->bookOutput(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Fertigung', 'taxmod-stock'))), ($args['tested'] ?? false) === true, sanitize_key((string) ($args['stage'] ?? '')), $total === null ? null : (float) $total, $calculation);
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
            return self::writing()->createThing(sanitize_text_field((string) ($args['name'] ?? '')), absint($args['group'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * One booking at a time (D-1147): two pickers who both read «1 left» must not both book it.
     *
     * The second waits for the first, then reads the stock again inside its own request and is refused if it is no longer there.
     * The lock lasts one request and is dropped with the connection, so a closed browser holds nothing; it is the modeller's,
     * through its one door (D-1186).
     *
     * @param bool $answers in an ajax answer: a booking in progress is thrown on, for `ListPage::json()` to say (D-1254)
     */
    public static function serially(callable $work, bool $answers = false): void
    {
        try {
            \Taxmod\WordPress\ModelDoor::serially('stock', $work);
        } catch (\Taxmod\Core\Exception\ConcurrentChange $e) {
            if ($answers) {
                throw $e;
            }

            Acts::deny($e->getMessage(), 409);
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
            return self::reading()->freeStockByPlace(array_values(array_filter(array_map('absint', is_array($args['things'] ?? null) ? $args['things'] : []))), absint($args['except'] ?? 0));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * `apply_filters('taxmod_stock_things_in_stock', null, ['group' => node] or ['node' => node], 'except' => box)` → thing →
     * free pieces below that node (D-1298 root), outside the box; reserved places and bound lots do not count. D-1539: with
     * `['nodes' => [node, …], 'except' => box]` → node → thing → free pieces, in one read.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function thingsInStock(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];
        $root = absint($args['group'] ?? 0) ?: absint($args['node'] ?? 0);

        try {
            // D-1539 (CD-7): with 'nodes' all at once, node → thing → free pieces.
            if (is_array($args['nodes'] ?? null)) {
                return self::reading()->thingsInStockByNode(array_values(array_filter(array_map('absint', $args['nodes']))), absint($args['except'] ?? 0));
            }

            return $root === 0 ? [] : self::reading()->thingsInStock($root, absint($args['except'] ?? 0));
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
            return self::reading()->lotChoices();
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
            return self::writing()->bookLoss(
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
     * D-1260: `apply_filters('taxmod_stock_book_sale', null, ['sale' => id, 'lines' => [lot => '2', …], 'date' => 'Y-m-d',
     * 'title' => '…'])` books the goods of a sale as one Vorgang «Verkauf» with the sale as Bezug; answers its id or a
     * WP_Error. `taxmod_stock_cancel_sale` with ['deal' => id] takes it back.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function bookSale(mixed $previous, mixed $args): int|\WP_Error
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

        foreach (is_array($args['lines'] ?? null) ? $args['lines'] : [] as $lot => $quantity) {
            if (absint($lot) > 0 && is_numeric($quantity) && (float) $quantity > 0) {
                $lines[absint($lot)] = (string) $quantity;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return self::writing()->bookSale(absint($args['sale'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Verkauf', 'taxmod-stock'))));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1262: `apply_filters('taxmod_stock_cancel_sale', null, ['sale' => id, 'who' => name])` cancels every open Vorgang
     * «Verkauf» of the sale in one act and answers how many; with ['deal' => id] it cancels that one Vorgang and answers the
     * Storno's id, as `taxmod_stock_cancel_loss` does.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function cancelSale(mixed $previous, mixed $args): int|\WP_Error
    {
        $args = is_array($args) ? $args : [];

        if (is_int($previous) || $previous instanceof \WP_Error || ! isset($args['sale'])) {
            return self::cancelLoss($previous, $args);
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        try {
            return self::writing()->cancelSale(absint($args['sale']), current_time('Y-m-d H:i:s'), sanitize_text_field((string) ($args['who'] ?? wp_get_current_user()->display_name)));
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1260: `apply_filters('taxmod_stock_book_backfill', null, ['material' => id, 'quantity' => '1', 'date' => 'Y-m-d' (the
     * day of the sale), 'reference' => sale, 'value' => '12.50' (per piece, optional), 'place' => id (optional), 'title' =>
     * '…'])` brings the goods in as «Nacherfassung» and out as «Verkauf» in one act; answers the new lot or a WP_Error.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     the booking as the caller sends it; `mixed`: checked here
     */
    public static function bookBackfill(mixed $previous, mixed $args): int|\WP_Error
    {
        if (is_int($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args  = is_array($args) ? $args : [];
        $date  = sanitize_text_field((string) ($args['date'] ?? ''));
        $value = isset($args['value']) && is_numeric($args['value']) ? (string) $args['value'] : null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        try {
            return self::writing()->bookBackfill(
                absint($args['material'] ?? 0),
                sanitize_text_field((string) ($args['quantity'] ?? '')),
                absint($args['reference'] ?? 0),
                $date,
                $value,
                absint($args['place'] ?? 0),
                sanitize_text_field((string) ($args['title'] ?? __('Verkauf', 'taxmod-stock')))
            );
        } catch (StockError $e) {
            return new \WP_Error('taxmod_stock_' . $e->reason, Messages::of($e));
        }
    }

    /**
     * D-1260: `apply_filters('taxmod_stock_lots_of', null, ['materials' => [ids]])` answers material → lot → rest, only lots
     * with something left, oldest first; read only.
     *
     * @param mixed $previous an answer another callback gave already; `mixed`: whatever it handed on
     * @param mixed $args     `mixed`: checked here
     * @return array<int, array<int, float>>|\WP_Error
     */
    public static function lotsOf(mixed $previous, mixed $args): array|\WP_Error
    {
        if (is_array($previous) || $previous instanceof \WP_Error) {
            return $previous;
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('taxmod_stock_forbidden', __('Keine Berechtigung für Lagerbuchungen.', 'taxmod-stock'));
        }

        $args = is_array($args) ? $args : [];

        try {
            return self::reading()->lotsWithRest(array_values(array_filter(array_map('absint', is_array($args['materials'] ?? null) ? $args['materials'] : []))));
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
            return self::writing()->cancelLoss(absint($args['deal'] ?? 0), current_time('Y-m-d H:i:s'), sanitize_text_field((string) ($args['who'] ?? wp_get_current_user()->display_name)));
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
                // D-1398: «pieces» pieces of «piece» pins each, for a strip counted in pins.
                $lines[] = ['thing' => absint($line['thing']), 'from' => absint($line['from']), 'quantity' => (float) $line['qty'], 'pieces' => absint($line['pieces'] ?? 0), 'piece' => absint($line['piece'] ?? 0)]
                    // D-1535 (P1): «cuts» = pins per piece → pieces, all lengths of the material in one plan.
                    + (is_array($line['cuts'] ?? null) && ($cuts = self::cutsOf($line['cuts'])) !== [] ? ['cuts' => $cuts] : []);
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return new \WP_Error('taxmod_stock_not_a_date', Messages::of(StockError::notADate($date)));
        }

        if ($lines === []) {
            return new \WP_Error('taxmod_stock_nothing_to_book', Messages::of(StockError::nothingToBook()));
        }

        try {
            return self::writing()->bookPick(absint($args['reference'] ?? 0), absint($args['place'] ?? 0), $lines, $date, sanitize_text_field((string) ($args['title'] ?? __('Fertigung', 'taxmod-stock'))));
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

        // D-1522: «Material steht in»; no default, so «never set» stays apart from «none» and the older list holds until then.
        register_setting('taxmod_stock', StockService::MATERIAL_IN, [
            'type'              => 'array',
            'sanitize_callback' => static fn ($value): array => array_values(array_unique(array_filter(array_map('absint', is_array($value) ? $value : [])))),
            'show_in_rest'      => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ]);

        // D-1440: «Lagerort Wareneingang», like D-1303.
        register_setting('taxmod_stock', StockService::RECEIPT_INBOUND, [
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => true,
        ]);

        // D-1303: «Lagerort Wareneingang Produktion», like D-1111.
        register_setting('taxmod_stock', StockService::PRODUCTION_INBOUND, [
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => true,
        ]);

        // D-1111: «Lagerort für Test» (the tested groups are a group property since D-1259), so the place can be named over REST once it is created.
        register_setting('taxmod_stock', StockService::TESTS, [
            'type'              => 'object',
            'default'           => ['place' => null],
            'sanitize_callback' => static fn ($value): array => ['place' => TestSettings::fromArray(is_array($value) ? $value : [])->place],
            'show_in_rest'      => ['schema' => ['type' => 'object', 'properties' => [
                'place' => ['type' => ['integer', 'null']],
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
            // His report 2026-11-16 «platz auswahl zeigt graue seite»: an error no page caught ends as a message on the page,
            // named with where it happened, never as an empty screen (D-1254).
            try {
                // Review 2026-11-17 (E3): the modeller's read memory, as its node page has it (D-814) — the same statement asked twice
                // while one page is drawn is answered once. Any write makes it forget, so nothing reads a stale state. Through the
                // door, not the persistence layer (D-1186, design guard G5).
                $out = \Taxmod\WordPress\ModelDoor::remembering($render);
            } catch (\Throwable $e) {
                error_log('taxmod-stock ' . $slug . ': ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $out = \Taxmod\WordPress\Admin\ListPage::page(__('Logistik', 'taxmod-stock'), [], [['html' => '<div class="notice notice-error"><p>' . esc_html(sprintf(
                    /* translators: 1: error text, 2: file and line */
                    __('Die Seite konnte nicht gezeichnet werden: %1$s (%2$s)', 'taxmod-stock'),
                    $e->getMessage(),
                    basename($e->getFile()) . ':' . $e->getLine()
                )) . '</p></div>']], (string) TAXMOD_STOCK_VERSION);
            }

            print is_array($out) ? LogisticsShell::wrap($slug, $out['main'], $out['side']) : LogisticsShell::wrap($slug, $out);
        };

        $hooks = [
            add_submenu_page(self::PARENT, __('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', Pages::COCKPIT, $page(Pages::COCKPIT, static fn (): string => (new CockpitPage())->render())),
            // D-1027: demand, source, order per supplier — he orders at the shop himself.
            add_submenu_page(self::PARENT, __('Einkauf', 'taxmod-stock'), __('Einkauf', 'taxmod-stock'), 'manage_options', Pages::PURCHASE, $page(Pages::PURCHASE, static fn (): string => (new PurchasePage())->render())),
            add_submenu_page(self::PARENT, __('Wareneingang', 'taxmod-stock'), __('Wareneingang', 'taxmod-stock'), 'manage_options', self::MENU, $page(self::MENU, static fn (): string => (new InboundPage())->render())),
            add_submenu_page(self::PARENT, __('Lager', 'taxmod-stock'), __('Lager', 'taxmod-stock'), 'manage_options', Pages::STOCK, $page(Pages::STOCK, static fn (): array => (new StockPage())->parts())),
            // D-1184: Logistik › Kontakte, the model's contact branch.
            add_submenu_page(self::PARENT, __('Kontakte', 'taxmod-stock'), __('Kontakte', 'taxmod-stock'), 'manage_options', Pages::CONTACTS, $page(Pages::CONTACTS, static fn (): array => (new ContactsPage())->render())),
            // D-1474: Logistik › Stammdaten › Auswahllisten, the model's choice lists.
            add_submenu_page(self::PARENT, __('Auswahllisten', 'taxmod-stock'), __('Auswahllisten', 'taxmod-stock'), 'manage_options', Pages::CHOICES, $page(Pages::CHOICES, static fn (): string => (new ChoiceListsPage())->render())),
            // D-1541: Logistik › Einstellungen › Testregeln.
            add_submenu_page(self::PARENT, __('Testregeln', 'taxmod-stock'), __('Testregeln', 'taxmod-stock'), 'manage_options', Pages::TEST_RULES, $page(Pages::TEST_RULES, static fn (): string => (new TestRulesPage())->render())),
            // D-1555: Logistik › Einstellungen › Feldkatalog.
            add_submenu_page(self::PARENT, __('Feldkatalog', 'taxmod-stock'), __('Feldkatalog', 'taxmod-stock'), 'manage_options', Pages::FIELD_CATALOGUE, $page(Pages::FIELD_CATALOGUE, static fn (): string => (new FieldCataloguePage())->render())),
            // D-1085: Logistik › Einstellungen › Grundeinstellungen, inside the navigation.
            add_submenu_page(self::PARENT, __('Grundeinstellungen', 'taxmod-stock'), __('Grundeinstellungen', 'taxmod-stock'), 'manage_options', Pages::SETTINGS, $page(Pages::SETTINGS, static fn (): string => (new SettingsPage())->render())),
        ];

        // D-1085: a connection module's page inside the navigation; one address for all of them, the module named in the query.
        $moduleHook = add_submenu_page(self::PARENT, __('Logistik', 'taxmod-stock'), __('Logistik', 'taxmod-stock'), 'manage_options', Pages::MODULE, $page(Pages::MODULE, static fn (): string => (new ModulePage())->render(ModulePage::requested())));
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
            foreach ([self::MENU, Pages::STOCK, Pages::PURCHASE, Pages::SETTINGS, Pages::CONTACTS, Pages::CHOICES, Pages::MODULE, ...ModulePage::menuEntries()] as $hidden) {
                remove_submenu_page(self::PARENT, $hidden);
            }
        });

        // The modeller's forms (record editor, choosers, dialogs) on a logistics page need its stylesheet in the head: enqueued late,
        // from inside the filter, it came too late, and a chosen project showed its hidden search field beside it (2026-11-03).
        add_action('admin_enqueue_scripts', static function (): void {
            if (in_array(sanitize_key((string) ($_GET['page'] ?? '')), self::MODELLER_STYLED, true)) {
                do_action('taxmod_enqueue_admin_style');
            }
        });

        // D-1069: the material page inside a goods receipt dialog shows only itself — no admin menu, bar or logistics navigation.
        add_action('admin_enqueue_scripts', static function (): void {
            if (! in_array(sanitize_key((string) ($_GET['page'] ?? '')), [Pages::STOCK, 'taxmod'], true)) {
                return;
            }

            wp_register_script('taxmod-stock-frame', false, [], (string) TAXMOD_STOCK_VERSION, false);
            wp_enqueue_script('taxmod-stock-frame');
            wp_add_inline_script('taxmod-stock-frame', 'if(window.self!==window.top){document.documentElement.classList.add("taxmod-in-frame");}');
            wp_register_style('taxmod-stock-frame', false, [], (string) TAXMOD_STOCK_VERSION);
            wp_enqueue_style('taxmod-stock-frame');
            wp_add_inline_style('taxmod-stock-frame', 'html.taxmod-in-frame #adminmenumain,html.taxmod-in-frame #wpadminbar,html.taxmod-in-frame #wpfooter,html.taxmod-in-frame .taxmod-shell-nav,html.taxmod-in-frame h1.wp-heading-inline,html.taxmod-in-frame .taxmod-version{display:none!important}html.taxmod-in-frame .taxmod-shell{display:block}'
                . 'html.taxmod-in-frame.wp-toolbar{padding-top:0}html.taxmod-in-frame #wpcontent{margin-left:0}');
        });

        // On a page behind «Logistik» the menu still marks «Logistik».
        add_filter('submenu_file', static fn (?string $file): ?string => in_array(sanitize_key((string) ($_GET['page'] ?? '')), [self::MENU, Pages::STOCK, Pages::PURCHASE, Pages::SETTINGS, Pages::MODULE], true) ? Pages::COCKPIT : $file);
    }
}
