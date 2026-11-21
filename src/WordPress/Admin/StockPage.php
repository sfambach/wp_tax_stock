<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Renderer\DecimalText;
use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Listing\ListView;
use Taxmod\Core\Listing\Wildcard;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Renderer\DialogMarkup;
use Taxmod\Core\Tile\Tile;
use Taxmod\Core\Tile\TileTone;
use Taxmod\Core\Renderer\SelectMarkup;
use Taxmod\Core\Renderer\GridMarkup;
use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\Tiles;
use Taxmod\WordPress\Printing\PrintService;
use TaxmodStock\Core\FitLevel;
use TaxmodStock\Core\GroupStock;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\LagerInput;
use TaxmodStock\Core\Pack;
use TaxmodStock\Core\SourceRow;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\LocationStock;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\StockList;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\Storno;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\Things;
use TaxmodStock\WordPress\StockService;

/**
 * The page «Lager» under the Taxonomy Modeller: goods receipt, materials, stock count, consumption (stage 1, D-958 F7).
 *
 * D-995: the thing in the tree is the material. D-997: «Material» lists the things the stock knows; a click opens the
 * whole thing — its head fixed, the views as tabs below (Technische Daten, Einkauf, Lager, Bestand, Verkauf).
 *
 * Drawn with the modeller's shared list page (D-989): heading, tabs with counts, search, filters, bulk bar with
 * «Expand all», sortable columns, the row's buttons on the right, its details in a row below.
 * «Wareneingang» comes first, as the daily task.
 *
 * Every form posts to one handler, which works in the order of CD-5 and books in one write (all or nothing).
 */
final class StockPage
{
    /** D-1223: the layout field that prints a lot's batch number. */
    public const CHARGE_FIELD = 'Charge';

    /** D-1567: the Bezug choice that names a Bestandsposten. */
    private const PURPOSE_LOT = 'bestand';

    /** Whether this page drew the lot-mode words already ({@see self::lotWords()}). */
    private bool $lotWordsDrawn = false;

    /** The codes «print-label» may ask this page for (D-1235, D-1238): material, lot, place, box. */
    public const LABEL_PREFIXES = ['M', 'P', 'O', 'K'];

    public const PAGE       = Pages::STOCK;
    public const ACTION     = 'taxmod_stock_book';
    public const POSITIONS  = 'taxmod_stock_positions';

    /** D-1256: the manufacturer control of the group chosen in a «Neues Material» dialog. */
    public const MAKER      = 'taxmod_stock_maker';

    /** INF-116: the choice of material saved from an order not yet arrived, answered without a reload. */
    public const ASSIGN     = 'taxmod_stock_assign';

    /** D-1259: a group's properties, set in its Lager view. */
    public const GROUP_PROPERTIES = 'taxmod_stock_group_properties';
    /** The notice after a booking; the goods receipt cockpit shows it too, when the booking came from there. */
    public const NOTICE     = 'taxmod_stock_notice';
    private const LAST_LIST = 'taxmod_stock_last_list_';
    /** The address word of a search begun on the page «Bestand» ({@see self::asksStock()}). */
    public const ASKS_STOCK = 'bestand';
    /** D-1438: the Umbuchung held back by the question «Platz leer?», per user, until the page draws it. */
    private const EMPTY_QUESTION = 'taxmod_stock_empty_question_';

    /** D-1147: the source name under which the material list's rows draw their lots when opened. */
    public const DETAILS_MATERIAL = 'stock-material';

    private const BACK_TO_LIST = 'materialliste';
    /** Rows per page of the material list (design 20: a list of thousands draws only what is seen). */
    private const PER_PAGE  = 50;
    /** Where {@see self::tools()} puts the search into the bar (D-1462). */
    private const SEARCH_SPOT = '<!--taxmod-stock-search-->';
    private const ROWS      = 6;
    private const MANUFACTURING = 'taxmod-fertigung';
    /** D-1377: the bookings that go back to where their form was opened. */
    /** The bookings of one order's receipt: they go back to that order (D-1001). */
    private const RECEIPT_KINDS = ['positioneinlagern', 'wareneingang', 'aufteilen', 'zuordnen', 'nichteinlagern', 'einlagern', 'sammelnichteinlagern', 'wareneingangzurueck', 'angekommen', 'nichtangekommen', 'bilduebernehmen'];
    private const RETURNING = ['verbrauch', 'umbuchen', 'postenumbuchen', 'sammelumbuchen', 'inventur', 'wareneingang', 'einlagern'];

    private const TABS      = ['wareneingang', 'material', 'gruppenbestand', 'inventur', 'verbrauch', 'reservierungen', 'lagerorte', 'lagerstruktur', 'platzarten', 'fertigung'];
    private const VIEWS     = ['daten', 'bearbeiten', 'einkauf', 'lager', 'bestand', 'verkauf'];
    private const FILTER_FORM = 'taxmod-stock-filter';
    private const BULK_FORM   = 'taxmod-stock-bulk';
    /** The bulk form of a place's list of Unterorte on «Lagerstruktur». */
    private const CHILD_FORM  = 'taxmod-stock-children';
    private const PLACE_FORM  = 'taxmod-stock-place-lots';

    /** The thing's own fields that its head shows, or that say nothing in «Technische Daten». */
    private const HEAD_FIELDS = ['label', 'description', 'title_image'];

    /** The modeller's search over every record under `Model` (D-958 F3a), reused to pick a thing. */
    public const THING_SEARCH = 'taxmod_stock_thing_search';
    /** D-1504: the unit word of one material, for the quantity beside a chooser — shown only, never read back. */
    public const THING_UNIT = 'taxmod_stock_thing_unit';
    public const THING_NODES  = 'taxmod_stock_thing_nodes';
    public const THING_BRANCH = 'taxmod_stock_thing_branch';
    /** D-1299: the lot chooser of Ausbuchen — search and a group's lots. */
    public const LOT_SEARCH   = 'taxmod_stock_lot_search';
    public const LOT_BRANCH   = 'taxmod_stock_lot_branch';

    /** The side trees' names for the state the modeller keeps per user (`ListPage::sideTree()`). */
    private const PLACE_TREE = 'taxmod_stock_places';
    private const GROUP_TREE = 'taxmod_stock_groups';

    /** What {@see parts()} puts beside the navigation; filled while the page is drawn. */
    private string $side = '';

    /** @var array<int, array{categories: list<string>, materials: list<string>}> D-1199: what lies on a place, read once per request */
    private array $contents = [];

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /**
     * The page alone, for a caller without the logistics frame: what the frame shows beside the navigation stands above it.
     */
    public function render(): string
    {
        $parts = $this->parts();

        return $parts['side'] . $parts['main'];
    }

    /**
     * D-1092: the page split for the logistics frame — `side` goes into the left column under the navigation (the place
     * tree on «Lagerorte» and «Lagerstruktur», empty elsewhere), `main` is the page.
     *
     * @return array{side: string, main: string}
     */
    public function parts(): array
    {
        $this->side = '';
        $main       = $this->main();

        return ['side' => $this->side, 'main' => $main];
    }

    private function main(): string
    {
        $tab  = sanitize_key((string) ($_GET['tab'] ?? self::TABS[0]));
        $tab  = in_array($tab, self::TABS, true) ? $tab : self::TABS[0];
        $list = $this->listRequest();
        $html = Notice::take(self::NOTICE, true);
        $html .= $this->sourceEmptyQuestion();

        try {
            $thing = absint($_GET['thing'] ?? 0);
            // D-1243, his word 2026-11-09: «Ich überlege, ob wir die Tabs nicht ganz rausmachen … Kannst du die mal bei Bestand
            // rausnehmen?» — the pages of «Bestand» are reached through the navigation on the left only.
            $html .= match ($tab) {
                // D-1195: one material reads only its own purchases, lots and bookings.
                'material'  => $thing > 0 ? (new self($this->stock->scopedTo($thing)))->thingView($thing) : $this->materialList($list),
                'gruppenbestand' => $this->groupStock($list),
                'inventur'  => $this->inventoryForm(),
                'verbrauch' => $this->consumptionForm($thing, absint($_GET['lot'] ?? 0), absint($_GET['place'] ?? 0)),
                'lagerorte' => $this->locationSplit(false),
                'reservierungen' => $this->reservationList(),
                'lagerstruktur' => $this->locationSplit(true),
                'platzarten' => $this->placeTypesPage(absint($_GET['typ'] ?? 0)),
                'fertigung' => self::boxesInModule() === null ? $this->projectBoxList() : ListPage::toolbar([['<a class="button button-primary" href="' . esc_url((string) self::boxesInModule()) . '">' . esc_html__('Projekt-Gebinde in der Fertigung öffnen', 'taxmod-stock') . '</a>']]),
                default     => absint($_GET['split'] ?? 0) > 0
                    ? $this->splitForm(absint($_GET['order'] ?? 0), absint($_GET['split']), sanitize_key((string) ($_GET['back'] ?? '')))
                    // D-1440: booking is part of «Angekommen»; the old address is sent there before the page is drawn.
                    : '<p><a href="' . esc_url(InboundPage::url(['tab' => \TaxmodStock\Core\InboundStage::Arrived->value])) . '">' . esc_html__('Eingebucht wird unter «Angekommen».', 'taxmod-stock') . '</a></p>',
            };
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        // D-1373: the page in the modeller's template.
        return ListPage::page(__('Lager', 'taxmod-stock'), [], [['html' => $html . $this->script($tab === 'wareneingang')]], (string) TAXMOD_STOCK_VERSION);
    }

    /** admin-post handler: capability → nonce → validate → sanitize → act → back to the page with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::ACTION);

        $kind  = sanitize_key((string) ($_POST['booking'] ?? ''));
        // D-1058: the row buttons carry their purchase in the value, «nichteinlagern-<id>» and «einlagern-<id>».
        $rowPurchase = preg_match('/^(positioneinlagern|nichteinlagern|einlagern|bilduebernehmen|umhaengen|reservieren|freigeben|ortkopieren|ortloeschen|artkopieren|unterplaetze|artwechsel|kurzzeichen)-(\d+)$/', $kind, $m) === 1 ? (int) $m[2] : 0;
        $kind        = $rowPurchase > 0 ? $m[1] : $kind;
        // D-1440: the one table of «Angekommen» holds the purchases of several orders; each row names its own.
        $multi       = ($_POST['multi'] ?? '') === '1';

        if ($multi && $rowPurchase > 0) {
            $_POST['order'] = (string) absint(((array) ($_POST['order_of'] ?? []))[$rowPurchase] ?? 0);
        }
        // D-1414 as changed on 2026-11-18: «Umbuchen» with «Quelle danach leer» ticked also books the rest left there out.
        $emptySource = $kind === 'umbuchen' && ($_POST['source_empty'] ?? '') === '1';
        $today = current_time('Y-m-d');
        $arrivedPick = array_values(array_filter(array_map('absint', (array) ($_POST['purchases'] ?? []))));
        $undone      = ['done' => [], 'skipped' => []];
        // The goods receipt sends «thing» as a list (one per purchase); only the other forms send one thing.
        $thing = is_array($_POST['thing'] ?? null) ? 0 : absint($_POST['thing'] ?? 0);
        $back  = ['material', []];
        // D-1001: a receipt booked from the goods receipt cockpit goes back there.
        $fromCockpit = in_array($kind, self::RECEIPT_KINDS, true) && sanitize_key((string) ($_POST['back'] ?? '')) === InboundPage::BACK;

        // D-1438, his word 2026-11-18 08:18: «Wenn der Platz laut System leer ist, aber beim Absenden die Tickbox nicht getickt
        // ist, dann soll nochmal ein Dialog erscheinen mit einer Frage» — this submit books nothing, the question answers it.
        if ($kind === 'umbuchen' && ! $emptySource && ($_POST['source_checked'] ?? '') !== '1') {
            $this->askIfSourceEmpty();
        }

        // D-1438: «Noch dort» of that question — booked back in at the old place with the Umbuchung, in one write.
        $found = $kind === 'umbuchen' && ($_POST['source_answer'] ?? '') === 'noch' && is_array($_POST['menge'] ?? null) ? $this->stock->consumptionTyped($_POST['menge'], [])['quantity'] : '';

        // His wish 2026-11-04: «liste mit kindern und massen funktion bsp alle etiketten drucken» — a PDF or the print service.
        if ($kind === 'etiketten-service' || $kind === 'etiketten-pdf') {
            $this->printPlaceLabels(array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? [])))), absint($_POST['return_ort'] ?? 0), $kind === 'etiketten-service');
        }

        // D-1202, his wish 2026-11-06: «Drucken» in the material and group stock lists — one label per ticked material.
        if ($kind === 'materialetiketten-service' || $kind === 'materialetiketten-pdf') {
            $this->printMaterialLabels(
                // A ticked lot on «Platzbestand» posts «lot:place:thing» (D-1224); since D-1225 it prints the label of that lot.
                self::labelItems(array_map('strval', (array) ($_POST['selected'] ?? []))),
                absint($_POST['label_choice'] ?? 0),
                absint($_POST['label_printer'] ?? 0),
                absint($_POST['copies'] ?? 0),
                $kind === 'materialetiketten-service'
            );
        }

        // D-1072: the material just created with «Neues Material» opens in its dialog once the page is back.
        $open = 0;
        // D-1130: a copy opens at once, to change what differs.
        $copy = 0;

        try {
            // D-1298: «Menge» and «Art» of Ausbuchen come from the modeller's controls and are read back by it.
            $consumption = $kind === 'verbrauch'
                ? $this->stock->consumptionTyped(is_array($_POST['menge'] ?? null) ? $_POST['menge'] : [], is_array($_POST['vorgang'] ?? null) ? $_POST['vorgang'] : [])
                : ['quantity' => '', 'kind' => 0];
            $result = match ($kind) {
                'inventur'     => $this->stock->bookInventory($this->inventoryRows(), $today, $this->title(__('Inventur', 'taxmod-stock'), $today)),
                'lager'        => $this->stock->saveLager($thing, $this->lagerInput()),
                'verschieben'  => $this->stock->moveThing($thing, absint($_POST['group'] ?? 0)),
                'postenumbuchen' => $this->stock->moveLotsWhole(
                    array_values(array_filter(array_map(static function (string $one): ?array {
                        $parts = array_map('absint', explode(':', $one));

                        return count($parts) === 3 && $parts[0] > 0 && $parts[1] > 0 ? [$parts[0], $parts[1]] : null;
                    }, array_map('strval', (array) ($_POST['selected'] ?? []))))),
                    absint($_POST['location'] ?? 0),
                    $today,
                    $this->title(__('Umbuchung', 'taxmod-stock'), $today)
                ),
                'sammelvarianten' => $this->stock->makeVariants(array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))))),
                'sammelverschieben' => $this->stock->moveThingsToGroup(array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? [])))), absint($_POST['group'] ?? 0)),
                'gruppeanlegen' => $copy = $this->stock->createGroup(absint($_POST['group'] ?? 0), sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')))),
                'sammelumbuchen' => $this->stock->moveThings(
                    array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? [])))),
                    absint($_POST['location'] ?? 0),
                    $today,
                    $this->title(__('Umbuchung', 'taxmod-stock'), $today)
                ),
                'umbuchen'     => isset($_POST['lots']) && str_contains((string) $_POST['lots'], ',') ? $this->stock->moveLots(
                    array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) $_POST['lots'])))))),
                    absint($_POST['from'] ?? 0),
                    absint($_POST['location'] ?? 0),
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    $today,
                    $this->title(__('Umbuchung', 'taxmod-stock'), $today),
                    $emptySource,
                    $found
                ) : $this->stock->moveLot(
                    absint($_POST['lot'] ?? 0),
                    absint($_POST['from'] ?? 0),
                    absint($_POST['location'] ?? 0),
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    $today,
                    $this->title(__('Umbuchung', 'taxmod-stock'), $today),
                    $emptySource,
                    $found
                ),
                'reservieren'  => $this->stock->reserve(
                    absint($_POST['subject'] ?? 0),
                    sanitize_text_field(wp_unslash((string) ($_POST['deadline'] ?? ''))),
                    $thing,
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    absint($_POST['lot'] ?? 0) ?: null,
                    $today,
                    $this->title(__('Reservierung', 'taxmod-stock'), $today)
                ),
                'reservierungstorno' => $this->stock->cancelReservation(absint($_POST['transaction'] ?? 0), absint($_POST['line'] ?? 0) ?: null, current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                // D-1127: «Stornieren …» over the ticked reservations of the list; a reserved place («o…») is no Vorgang and is left.
                'reservierungensammelstorno' => $this->cancelReservations(array_values(array_filter(array_map(
                    static fn (mixed $row): int => str_starts_with((string) $row, 'r') ? absint(substr((string) $row, 1)) : 0,
                    (array) ($_POST['selected'] ?? [])
                )))),
                'storno'       => $this->stock->cancelTransfer(absint($_POST['transaction'] ?? 0), current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                'verbrauchstorno' => $this->stock->cancelConsumption(absint($_POST['transaction'] ?? 0), current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                'inventurstorno' => $this->stock->cancelCount(absint($_POST['transaction'] ?? 0), absint($_POST['line'] ?? 0) ?: null, current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                'positioneinlagern' => $this->storePosition(absint($_POST['order'] ?? 0), $rowPurchase, $today),
                'wareneingang' => $multi ? $this->receiptPerOrder(array_keys($this->receiptChoices()), fn (int $order, array $only): array => $this->stock->bookReceipt(
                    $order,
                    $this->toArrival(array_intersect_key($this->receiptChoices(), array_flip($only))),
                    $today,
                    $this->title(__('Wareneingang', 'taxmod-stock'), $today),
                    array_intersect_key($this->receiptQuantities(), array_flip($only))
                )) : $this->stock->bookReceipt(
                    absint($_POST['order'] ?? 0),
                    $this->receiptChoices(),
                    $today,
                    $this->title(__('Wareneingang', 'taxmod-stock'), $today),
                    $this->receiptQuantities()
                ),
                'aufteilen'    => $this->stock->splitPurchase(absint($_POST['order'] ?? 0), absint($_POST['purchase'] ?? 0), $this->splitRows()),
                'nichteinlagern' => $this->stock->closeWithoutStock(
                    absint($_POST['order'] ?? 0),
                    $rowPurchase,
                    sanitize_text_field(wp_unslash((string) ((array) ($_POST['reason'] ?? []))[$rowPurchase] ?? ''))
                ),
                'einlagern'    => $this->stock->reopenForStock(absint($_POST['order'] ?? 0), $rowPurchase),
                // D-1030: the row buttons of the structure page carry their place the same way.
                'umhaengen'    => $this->stock->movePlace($rowPurchase, absint(((array) ($_POST['parent'] ?? []))[$rowPurchase] ?? 0), $today, $this->title(__('Umlagerung', 'taxmod-stock'), $today)),
                'orteverschieben' => $this->stock->movePlaces(
                    array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? [])))),
                    absint($_POST['children_parent'] ?? 0),
                    $today,
                    $this->title(__('Umlagerung', 'taxmod-stock'), $today)
                ),
                // D-1082: the flag «reserviert für Produktion», set and taken off from the row's dialog.
                'reservieren', 'freigeben' => $this->stock->reservePlace($rowPurchase, $kind === 'reservieren'),
                'ort'          => $this->stock->createPlace(
                    sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
                    absint($_POST['new_parent'] ?? 0),
                    ! empty($_POST['movable']),
                    absint($_POST['project'] ?? 0),
                    ! empty($_POST['reserved']),
                    absint($_POST['place_type'] ?? 0)
                ),
                'platzart'     => $this->stock->createPlaceType(sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')))),
                // D-1267: the page goes back to the parent of the place deleted.
                'ortloeschen'  => $copy = $this->stock->deletePlace($rowPurchase, current_time('Y-m-d'), __('Lagerort gelöscht', 'taxmod-stock'), ! empty($_POST['below'])),
                'unterplaetze' => $this->stock->createSubPlaces($rowPurchase),
                'kurzzeichen'  => $this->stock->fillShortMarks($rowPurchase),
                // D-1095: dragged above or below a sibling in the place tree.
                'reihenfolge'  => $this->stock->rankPlace(absint($_POST['place'] ?? 0), absint($_POST['target'] ?? 0), ($_POST['where'] ?? '') === 'after'),
                // C1: only the «Bezugsquellen» lines, read back through the modeller's controls (D-1250).
                'quellen'      => $this->stock->saveSources($thing, is_array($_POST['quelle'] ?? null) ? wp_unslash($_POST['quelle']) : []),
                // D-1315: the way comes from the dialog, never a default.
                'artwechsel'   => $this->stock->changePlaceKind($rowPurchase, absint($_POST['place_type'] ?? 0), sanitize_key((string) ($_POST['way'] ?? '')), $today, $this->title(__('Umbuchung', 'taxmod-stock'), $today)),
                'artkopieren'  => $copy = $this->stock->copyPlaceType($rowPurchase, sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')))),
                // D-1130: «Kopieren» — the copy's id is what the page goes back to.
                'ortkopieren'  => $copy = $this->stock->copyPlace($rowPurchase, sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))), ! empty($_POST['below'])),
                'kopieren'     => $copy = $this->stock->copyThing($thing),
                'sammelnichteinlagern' => array_map(fn (int $purchase): array => $this->stock->closeWithoutStock($multi ? absint(((array) ($_POST['order_of'] ?? []))[$purchase] ?? 0) : absint($_POST['order'] ?? 0), $purchase, sanitize_text_field(wp_unslash((string) ($_POST['bulk_reason'] ?? '')))),
                    array_values(array_filter(array_map('absint', (array) ($_POST['purchases'] ?? [])))) ?: throw StockError::nothingToBook()),
                'wareneingangzurueck' => $undone = $multi ? $this->unbookOrders() : (new \TaxmodStock\WordPress\InboundService($this->stock))->unbookFree(absint($_POST['order'] ?? 0)),
                // Stefan 16.11. «nicht möglich zu sagen dass einzelne teile angekommen sind»: the choice is kept, the order
                // arrives («Eingegangen am» only when empty), and the receipt opens with the ticked purchases alone.
                'angekommen'   => $this->arriveTicked(absint($_POST['order'] ?? 0), $arrivedPick, $today),
                // D-1411: ticked purchases step back from «Angekommen»; the others stay.
                'nichtangekommen' => $multi ? $this->receiptPerOrder($arrivedPick, fn (int $order, array $only): array => (new \TaxmodStock\WordPress\InboundService($this->stock))->markPurchasesNotArrived($order, $only))
                    : (new \TaxmodStock\WordPress\InboundService($this->stock))->markPurchasesNotArrived(absint($_POST['order'] ?? 0), $arrivedPick ?: throw StockError::nothingToBook()),
                'bilduebernehmen' => $this->stock->takePurchasePicture($rowPurchase),
                'zuordnen'     => $multi ? $this->receiptPerOrder(array_keys(array_map('absint', (array) ($_POST['order_of'] ?? []))), fn (int $order, array $only): array => $this->stock->assignPurchases(
                    $order,
                    array_intersect_key($this->assignedThings(), array_flip($only)),
                    array_intersect_key($this->assignedPlaces(), array_flip($only)),
                    array_intersect_key($this->newThings(), array_flip($only)),
                    array_intersect_key($this->receiptQuantities(), array_flip($only))
                )) : $this->stock->assignPurchases(absint($_POST['order'] ?? 0), $this->assignedThings(), $this->assignedPlaces(), $this->newThings(), $this->receiptQuantities()),
                'verbrauch'    => $this->stock->bookConsumption(
                    $thing,
                    absint($_POST['lot'] ?? 0) ?: null,
                    $consumption['quantity'] !== '' ? $consumption['quantity'] : sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    $this->purpose(sanitize_key(wp_unslash((string) ($_POST['purpose'] ?? ''))), absint($_POST['purpose_lot'] ?? 0)),
                    $today,
                    $this->title(__('Verbrauch', 'taxmod-stock'), $today),
                    absint($_POST['place'] ?? 0) ?: null,
                    sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? ''))),
                    $consumption['kind'],
                    absint($_POST['pieces'] ?? 0),
                    absint($_POST['piece_pins'] ?? 0)
                ),
                default        => throw StockError::nothingToBook(),
            };
            Notice::remember('success', match ($kind) {
                'umhaengen'      => __('Umgehängt: der Ort liegt jetzt dort, mit allem, was in ihm liegt.', 'taxmod-stock'),
                'orteverschieben' => __('Verschoben: die gewählten Orte liegen jetzt dort, mit allem, was in ihnen liegt.', 'taxmod-stock'),
                'umbuchen'       => $emptySource
                    ? __('Umgebucht; der Rest am alten Ort ist per Inventur auf 0 ausgebucht. Ein Undo nimmt beides zurück.', 'taxmod-stock')
                    : ((float) str_replace(',', '.', $found) > 0.0
                        ? __('Umgebucht; was am alten Platz noch liegt, ist dort per Inventur eingebucht. Ein Undo nimmt beides zurück.', 'taxmod-stock')
                            : __('Umgebucht: ein Vorgang «Umbuchung» mit Abgang am alten und Zugang am neuen Ort.', 'taxmod-stock')),
                'sammelverschieben' => __('Verschoben: die gewählten Materialien stehen jetzt in der neuen Gruppe.', 'taxmod-stock'),
                'sammelvarianten' => __('Zusammengefasst: die gewählten Materialien sind jetzt Varianten eines neuen Materials; Nummern, Bestand und Etiketten bleiben.', 'taxmod-stock'),
                'gruppeanlegen'  => __('Gruppe angelegt.', 'taxmod-stock'),
                'sammelumbuchen' => __('Umgebucht: alle gewählten Materialien liegen jetzt am neuen Ort, als ein Vorgang «Umbuchung».', 'taxmod-stock'),
                'postenumbuchen' => __('Umgebucht: die gewählten Posten liegen jetzt ganz am neuen Ort, als ein Vorgang «Umbuchung».', 'taxmod-stock'),
                'storno', 'reservierungstorno', 'reservierungensammelstorno', 'verbrauchstorno', 'inventurstorno' => __('Storniert: die Gegenbuchung hebt den Vorgang auf, beide bleiben im Protokoll.', 'taxmod-stock'),
                'reservieren'    => __('Reserviert: der verfügbare Bestand sinkt, der Bestand bleibt.', 'taxmod-stock'),
                'ort'            => __('Lagerort angelegt.', 'taxmod-stock'),
                'platzart'       => __('Platzart angelegt; die weiteren Felder stehen jetzt zum Ausfüllen da.', 'taxmod-stock'),
                'unterplaetze'   => __('Unterplätze angelegt, wie die Platzart sie vorgibt.', 'taxmod-stock'),
                'quellen'        => __('Bezugsquellen gespeichert.', 'taxmod-stock'),
                'kurzzeichen'    => __('Kurzzeichen erzeugt; vorhandene blieben stehen.', 'taxmod-stock'),
                'reihenfolge'    => __('Eingereiht: die Orte dieser Ebene stehen jetzt in der neuen Reihenfolge.', 'taxmod-stock'),
                'artwechsel'     => __('Art gewechselt: die neuen Unterplätze stehen, der Bestand ist umgebucht, die alten Unterplätze sind entfernt.', 'taxmod-stock'),
                'artkopieren'    => __('Kopiert: die neue Platzart trägt Felder und Teile der alten; die Plätze bleiben bei der alten.', 'taxmod-stock'),
                'ortkopieren'    => __('Kopiert: der neue Ort trägt die Felder des alten, aber nichts von dem, was dort liegt.', 'taxmod-stock'),
                'ortloeschen'    => __('Gelöscht: was dort lag ist jetzt im übergeordneten Ort. Rückgängig geht es oben über die Liste der letzten Änderungen.', 'taxmod-stock'),
                'kopieren'       => __('Kopiert: das neue Material trägt Daten und Lagersicht des alten, aber keinen Bestand; Bestellnummern bitte neu eintragen.', 'taxmod-stock'),
                'reservieren'    => __('Reserviert für Produktion: alles, was dort und darunter liegt, ist reserviert.', 'taxmod-stock'),
                'freigeben'      => __('Kennzeichen entfernt: was dort liegt, ist nicht mehr reserviert.', 'taxmod-stock'),
                'verschieben'    => __('Verschoben: das Material steht jetzt in der neuen Gruppe, ihre weiteren Felder sind leer.', 'taxmod-stock'),
                'positioneinlagern' => __('Eingelagert: der Kauf ist im Bestand; ohne Lagerort liegt er am «Lagerort Wareneingang» und geht von dort per Umbuchen weiter.', 'taxmod-stock'),
                /* translators: %d: number of orders */
                'wareneingang' => $multi ? sprintf(_n('Eingelagert: die angehakten Käufe aus %d Bestellung sind im Bestand.', 'Eingelagert: die angehakten Käufe aus %d Bestellungen sind im Bestand, je Bestellung ein Schritt.', count($result), 'taxmod-stock'), count($result))
                    : sprintf(__('Gebucht: %d Änderungen in einem Schritt.', 'taxmod-stock'), count((array) ($result['applied'] ?? []))),
                'nichteinlagern' => __('Abgeschlossen ohne Lager: kein Bestand, der Grund steht am Kauf.', 'taxmod-stock'),
                'einlagern'      => __('Zurückgenommen: der Kauf kann wieder eingebucht werden.', 'taxmod-stock'),
                'aufteilen' => __('Aufgeteilt: jede Zeile ist jetzt ein Kauf mit seinem Material und kann eingebucht werden.', 'taxmod-stock'),
                'zuordnen'  => __('Zuordnung gespeichert: Material und Lagerort bleiben stehen, noch nichts eingebucht.', 'taxmod-stock'),
                /* translators: %d: number of purchases */
                'sammelnichteinlagern' => sprintf(_n('%d Kauf ohne Lager abgeschlossen, der Grund steht am Kauf.', '%d Käufe ohne Lager abgeschlossen, der Grund steht an jedem Kauf.', count($result), 'taxmod-stock'), count($result)),
                'wareneingangzurueck' => $this->unbookedText($undone),
                /* translators: %d: number of purchases */
                'angekommen' => sprintf(_n('Angekommen: %d Kauf steht jetzt unter «Angekommen».', 'Angekommen: %d Käufe stehen jetzt unter «Angekommen».', count($arrivedPick), 'taxmod-stock'), count($arrivedPick)),
                'nichtangekommen' => sprintf(_n('%d Kauf ist wieder unterwegs.', '%d Käufe sind wieder unterwegs.', count($arrivedPick), 'taxmod-stock'), count($arrivedPick)),
                'bilduebernehmen' => __('Bild übernommen: es steht jetzt auch am Material.', 'taxmod-stock'),
                /* translators: %d: number of changes the modeller applied */
                default     => sprintf(__('Gebucht: %d Änderungen in einem Schritt.', 'taxmod-stock'), count((array) ($result['applied'] ?? []))),
            });

            // D-1158: hung where it does not fit — done all the same, but said.
            $misfits = match ($kind) {
                'umhaengen'       => $this->stock->misfits([$rowPurchase], absint(((array) ($_POST['parent'] ?? []))[$rowPurchase] ?? 0)),
                'orteverschieben' => $this->stock->misfits(array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? [])))), absint($_POST['children_parent'] ?? 0)),
                'ort'             => $this->stock->fitsNew(absint($_POST['place_type'] ?? 0), absint($_POST['new_parent'] ?? 0)) === false ? [0] : [],
                default           => [],
            };

            if ($misfits !== []) {
                $info = $this->stock->fitLevelOf(absint($kind === 'ort' ? ($_POST['new_parent'] ?? 0) : ($kind === 'umhaengen' ? (((array) ($_POST['parent'] ?? []))[$rowPurchase] ?? 0) : ($_POST['children_parent'] ?? 0)))) === FitLevel::Info;
                Notice::remember($info ? 'info' : 'warning', __('Erledigt, aber: die Außenabmessung passt nicht in die Innenabmessung des Ortes darüber (Passberechnung seiner Platzart).', 'taxmod-stock'));
            }

            $open = $kind === 'zuordnen' ? (int) array_key_first(array_filter(array_map('absint', (array) ($_POST['newgroup'] ?? [])))) : 0;
            $back = match ($kind) {
                'verschieben'  => ['material', ['thing' => $thing, 'view' => 'daten']],
                'kopieren'     => ['material', ['thing' => $copy, 'view' => 'bearbeiten']],
                'lager'        => ['material', ['thing' => $thing, 'view' => 'lager']],
                'quellen'      => ['material', ['thing' => $thing, 'view' => 'einkauf']],
                'umbuchen', 'storno', 'verbrauchstorno', 'inventurstorno' => ['material', ['thing' => $thing, 'view' => 'bestand']],
                'reservierungensammelstorno' => ['reservierungen', []],
                'reservieren', 'reservierungstorno' => sanitize_key((string) ($_POST['back'] ?? '')) === 'bestand' && $thing > 0
                    ? ['material', ['thing' => $thing, 'view' => 'bestand']] : ['reservierungen', []],
                'positioneinlagern', 'wareneingang', 'aufteilen', 'zuordnen', 'nichteinlagern', 'einlagern', 'sammelnichteinlagern', 'wareneingangzurueck', 'angekommen', 'nichtangekommen', 'bilduebernehmen' => ['wareneingang', ['order' => absint($_POST['order'] ?? 0)]],
                'platzart'     => ['platzarten', array_filter(['typ' => (int) (((array) ($result['refs'] ?? []))['art'] ?? 0)])],
                'artkopieren'  => ['platzarten', array_filter(['typ' => $copy])],
                'gruppeanlegen' => ['gruppenbestand', array_filter(['group' => $copy])],
                'umhaengen', 'orteverschieben', 'ort', 'reservieren', 'freigeben' => ['lagerstruktur', []],
                'reihenfolge'  => ['lagerstruktur', ['ort' => absint($_POST['place'] ?? 0)]],
                default        => ['material', $thing > 0 ? ['thing' => $thing, 'view' => 'bestand'] : []],
            };
        } catch (StockError $e) {
            $failed = true;
            Notice::remember('error', Messages::of($e));
            $back = match ($kind) {
                'positioneinlagern', 'wareneingang', 'zuordnen', 'nichteinlagern', 'einlagern', 'sammelnichteinlagern', 'wareneingangzurueck', 'angekommen', 'nichtangekommen', 'bilduebernehmen' => ['wareneingang', ['order' => absint($_POST['order'] ?? 0)]],
                'platzart'          => ['platzarten', []],
                'artkopieren'       => ['platzarten', array_filter(['typ' => $rowPurchase])],
                'gruppeanlegen'     => ['gruppenbestand', array_filter(['group' => absint($_POST['group'] ?? 0)])],
                'umhaengen', 'orteverschieben', 'ort', 'reservieren', 'freigeben', 'reihenfolge' => ['lagerstruktur', []],
                'aufteilen'         => ['wareneingang', ['order' => absint($_POST['order'] ?? 0), 'split' => absint($_POST['purchase'] ?? 0)]],
                'quellen'           => ['material', ['thing' => $thing, 'view' => 'einkauf']],
                'lager', 'umbuchen', 'storno', 'verbrauchstorno', 'inventurstorno' => ['material', ['thing' => $thing, 'view' => $kind === 'lager' ? 'lager' : 'bestand']],
                // D-1073: refused because values would fall — named, nothing moved (his doubt 2026-11-01: «das verschieben ist problematisch»).
                'verschieben'       => ['material', ['thing' => $thing, 'view' => 'daten']],
                'verbrauch'         => ['verbrauch', ['thing' => $thing]],
                'kopieren'          => ['material', ['thing' => $thing, 'view' => 'daten']],
                'reservierungensammelstorno' => ['reservierungen', []],
                'reservieren', 'reservierungstorno' => sanitize_key((string) ($_POST['back'] ?? '')) === 'bestand' && $thing > 0
                    ? ['material', ['thing' => $thing, 'view' => 'bestand']] : ['reservierungen', []],
                default             => [$kind, []],
            };
        }

        // D-1377, his word 2026-11-15 «nach ausbuchen des verbrauch sollte der zurück springen woher er kam»: a booking goes back
        // to where its form was opened; without that, to where it always went.
        if (in_array($kind, self::RETURNING, true) && ! ($failed ?? false) && ($return = self::returnUrl((string) ($_POST['return'] ?? ''))) !== '') {
            wp_safe_redirect($return);
            exit;
        }

        // D-1090: the split pages go back to the place that was open.
        $returnTab = sanitize_key((string) ($_POST['return_tab'] ?? ''));

        if (in_array($returnTab, ['lagerorte', 'lagerstruktur'], true)) {
            $back = [$returnTab, array_filter(['ort' => in_array($kind, ['ortkopieren', 'ortloeschen'], true) ? $copy : absint($_POST['return_ort'] ?? 0)])];
        }

        // An Umbuchung started in the material list goes back to that very list, search and page included.
        $last = $returnTab === self::BACK_TO_LIST ? get_transient(self::LAST_LIST . get_current_user_id()) : false;

        if (is_string($last) && $last !== '') {
            wp_safe_redirect($last);
            exit;
        }

        // D-1440: the one table of «Angekommen» stays the list it was; no order's own view opens.
        if ($fromCockpit && $multi) {
            wp_safe_redirect(InboundPage::url(['tab' => sanitize_key((string) ($_POST['stage'] ?? '')) ?: \TaxmodStock\Core\InboundStage::Arrived->value]));
            exit;
        }

        if ($fromCockpit) {
            // D-1411: booked goes on to «Eingebucht», the rest stays under «Angekommen» while something is still there.
            $to = InboundPage::orderUrl(absint($_POST['order'] ?? 0), $kind === 'wareneingang' && ! ($failed ?? false) ? 'booked' : 'arrived');
            wp_safe_redirect($open > 0 ? add_query_arg('material', $open, $to) : $to);
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => $back[0]] + $back[1] + ($open > 0 ? ['material' => $open] : []), admin_url('admin.php')));
        exit;
    }

    /**
     * D-997: the things the stock knows as one list; a group chosen adds the columns of its technical data, and a
     * row's details are its lots.
     */
    private function materialList(StockList $list, bool $branch = false, int $chosen = 0): string
    {
        $tab    = $branch ? 'gruppenbestand' : 'material';
        $s      = $this->stock->structure();
        $things = $this->stock->things();
        $all    = $things->all();
        // His screenshot 2026-11-19 05:47: on «Gruppenbestand» the group is the one the tree holds (kept per user), not only the address's.
        $group  = $branch && $chosen > 0 ? $chosen : absint($_GET['group'] ?? 0);

        if ($all === []) {
            return '<p>' . esc_html__('Noch kein Material. Eine Inventur oder ein Wareneingang bringt das erste.', 'taxmod-stock') . '</p>';
        }

        // D-1174: on «Gruppenbestand» the group holds the groups below it; their technical data differ, so no such columns.
        $within    = $branch && $group > 0 ? GroupStock::branch($this->stock->groupParents(), $group) : [];
        $technical = $group > 0 && ! $branch ? $this->technicalColumns($group) : [];
        $data      = $technical !== [] ? $this->stock->tableOf($group) : null;
        // D-1204: a picture per row, unless the Configuration has pictures in lists off.
        $images    = ListPage::showsImages();
        // D-1462: a picture has nothing to filter by; stock and minimum filter by number (2, >2, <2, 2-10).
        $columns   = [...($images ? [new ListColumn('image', __('Bild', 'taxmod-stock'), fixed: true, width: '56px', filterable: false)] : []),
            new ListColumn('nr', __('Nr.', 'taxmod-stock'), fixed: true, width: '6em'), new ListColumn('name', __('Material', 'taxmod-stock'), fixed: true),
            new ListColumn('group', __('Gruppe', 'taxmod-stock'))];
        // D-1030: where it lies, by path and project — so a search for a project finds the parts in its box.
        $columns[] = new ListColumn('places', __('Lagerort', 'taxmod-stock'));
        $position  = 0;

        foreach ($technical as $relation => $name) {
            // D-1054: every plain field of the group can be a column; the first four are the starting set.
            $columns[] = new ListColumn('f' . $relation, $name, $position++ < 4);
        }

        $columns[] = new ListColumn('stock', __('Bestand', 'taxmod-stock'), width: '7em', number: true);
        $columns[] = new ListColumn('min', __('Mindestbestand', 'taxmod-stock'), width: '9em', number: true);
        $listId    = ($branch ? 'stock-groupstock' : 'stock-material') . ($group > 0 ? '-' . $group : '');
        $view      = ListPage::view($listId, $columns, 'name');
        $groups    = [];
        $rows      = [];
        // D-1147: nothing is drawn until a search, a filter or a group asks for it, or «Alle anzeigen».
        $waits     = $branch ? $group === 0 : ListPage::waitsForSearch($view, $group > 0 || $list->filter !== '');

        $packs = $waits ? [] : $this->stock->packsOf(array_keys($all));
        // Ladezeit (17.11.2026): a list waiting for a search draws no row, so it reads no book — before, every opening of
        // «Material» read every lot, line and transaction to show an empty list.
        $ledger = $waits ? null : $this->stock->ledger();
        $lager  = $waits ? null : $this->stock->table('lager');

        foreach ($waits ? [] : array_keys($all) as $id) {
            $node = $things->nodeOf($id);

            if ($node !== null) {
                $groups[$node] ??= $this->stock->path($node);
            }

            if ($branch ? ! isset($within[$node ?? 0]) : $group > 0 && $node !== $group) {
                continue;
            }

            assert($ledger !== null && $lager !== null);
            $record = $things->lagerOf($id);
            $min    = $record === null ? null : $lager->first($record, $s->field('lager_min'));
            $stock  = $ledger->stockOf($id);
            $name   = $things->word($id);
            // His word 2026-11-05: «bei mehreren positionen den platz in der zusammenfassung weg lassen».
            $at     = count(array_unique(array_column($this->heldAt($id, $ledger), 1))) > 1 ? [] : $this->placesOf($id, $ledger);
            $cells  = ['nr' => (string) $id, 'name' => $name, 'group' => $node === null ? '' : $groups[$node], 'places' => implode('; ', $at)];

            foreach (array_keys($technical) as $relation) {
                $cells['f' . $relation] = implode(', ', (array) $data?->all($id, $relation));
            }

            $rows[] = ['id' => $id, 'name' => $name, 'stock' => $stock, 'groupId' => $node ?? 0, 'placeIds' => $at, 'low' => $min !== null && $stock < (float) $min, 'words' => [],
                // Optional fields (Structure::OPTIONAL_FIELDS): a model without a base unit has none to group by.
                'unit' => $record === null || ! $s->hasField('lager_unit') ? '' : $lager->first($record, $s->field('lager_unit')) . '/' . ($s->hasField('lager_prefix') ? $lager->first($record, $s->field('lager_prefix')) : ''),
                'cells' => $cells + ['stock' => $this->number($stock) . (isset($packs[$id]) ? ' ' . $this->inPacks($packs[$id], $stock) : ''), 'min' => $min === null ? '' : (string) $min],
                'sort'  => ['nr' => $id, 'stock' => $stock, 'min' => $min === null ? -1.0 : (float) $min,
                    'name' => mb_strtolower((string) preg_replace('/^#\d+( · )?/u', '', $name))]];
        }

        // D-1479: a parent stands as a row of its own with the sum of its variants' stock.
        $parentOf = $waits ? [] : $this->stock->variantParents();
        $rows     = $this->withParentRows($rows, $parentOf, $technical, $groups, $things);

        if ($waits) {
            foreach ($things->nodes() as $node) {
                $groups[$node] ??= $this->stock->path($node);
            }
        }

        asort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        // The preset filter (with stock, below minimum) stays the page's own; search, column filters and order are the list's.
        $shown   = self::variantsBeneath($view->apply((new StockList('', $list->filter))->apply($rows)), $parentOf);

        $visible = $view->visible();
        $open    = $this->stock->viewSettings()->expanded('material') || self::asksStock();
        $url     = fn (array $change): string => $this->listUrl($tab, $list, $change + ['group' => $group > 0 ? (string) $group : '', ListPage::SHOW_ALL => ListPage::showsAll() ? '1' : '', 'f' => $view->filters(), 'orderby' => $view->orderBy === 'name' ? '' : $view->orderBy, 'order' => $view->descending ? 'desc' : '']);

        // «← Zur Liste» in the material view comes back to exactly this list (design 7.3) — kept before a single hit opens, so
        // «back» shows this list with its one row, not the one before.
        set_transient(self::LAST_LIST . get_current_user_id(), $url(['paged' => absint($_GET['paged'] ?? 0) > 1 ? (string) absint($_GET['paged']) : '']), DAY_IN_SECONDS);

        // D-1329, changed by D-1468: a search with exactly one hit opens that material only where it is chosen — in the
        // Grundeinstellungen, or by the person, whose choice wins. Otherwise the list stays with its one row.
        if (! $branch && trim($list->search) !== '' && count($shown) === 1
            && $this->stock->viewSettings()->opensSingleHit('material', SettingsPage::ownSingleHit())) {
            $one = (int) reset($shown)['id'];

            return (new self($this->stock->scopedTo($one)))->thingView($one);
        }

        $html    = ($branch ? $this->groupTotals(array_values(array_filter($rows, static fn (array $row): bool => empty($row['parent'])))) : '') . $this->tools($tab, $list, [
            ['filter', $this->materialFilters(), $list->filter, __('Filter', 'taxmod-stock')],
            ...($branch ? [] : [['group', ['' => __('Alle Gruppen', 'taxmod-stock')] + $groups, $group > 0 ? (string) $group : '', __('Gruppe', 'taxmod-stock')]]),
        ], ['group' => $group > 0 ? (string) $group : '', self::ASKS_STOCK => self::asksStock() ? '1' : '', 'orderby' => $view->orderBy === 'name' ? '' : $view->orderBy, 'order' => $view->descending ? 'desc' : ''],
            __('Material, Nummer, Gruppe …', 'taxmod-stock'), $waits ? sprintf(
            /* translators: %d: number of materials */
            _n('%d Material im Lager', '%d Materialien im Lager', count($all), 'taxmod-stock'),
            count($all)
        ) : sprintf(
            /* translators: %d: number of materials */
            _n('%d Material', '%d Materialien', count($shown), 'taxmod-stock'),
            count($shown)
        ), ListPage::filterToggle() . ListPage::columnMenu($listId, $view, $url([])), $this->bulkTransferAsk() + $this->bulkGroupAsk() + $this->bulkVariantsAsk() + $this->materialLabelsAsk());
        $body    = $waits ? ($branch ? '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) (count($visible) + 2)) . '">'
            . esc_html__('Links eine Materialgruppe wählen: angezeigt wird alles darin und in den Gruppen darunter.', 'taxmod-stock') . '</td></tr>'
            : ListPage::searchPrompt(count($visible), $url([ListPage::SHOW_ALL => '1']), count($all))) : ($shown === [] ? ($branch && $list->search === '' && $list->filter === ''
                ? '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) (count($visible) + 2)) . '">' . esc_html__('Keine Materialien in dieser Gruppe und den Gruppen darunter.', 'taxmod-stock') . '</td></tr>'
                : $this->noItems(count($visible))) : '');

        $paged  = absint($_GET['paged'] ?? 1);
        // D-1479: a parent and its variants count as one unit, so a page never separates them.
        $units  = self::units($shown);
        $page   = array_merge(...array_values(ListView::page($units, $paged, self::PER_PAGE)) ?: [[]]);
        $places = $page === [] ? [] : $this->stock->places();
        // Only the rows of this page, in one read (CD-7).
        $thumbs = $images ? ListPage::thumbnails($this->stock->titleImages(array_column($page, 'id'))) : [];
        $this->prefetchRowFacts(array_column($page, 'id'));

        foreach ($page as $row) {
            $id    = $row['id'];
            $link  = esc_url($this->thingUrl($id, 'daten'));
            $cells = [];

            foreach ($visible as $column) {
                $text    = $row['cells'][$column->key] ?? '';
                $cells[] = match ($column->key) {
                    'image' => ($thumbs[$id] ?? '') === '' ? '' : '<a href="' . $link . '">' . $thumbs[$id] . '</a>',
                    'nr'    => '<a id="taxmod-stock-row-' . esc_attr((string) $id) . '" href="' . $link . '">' . esc_html($text) . '</a>',
                    'name'  => '<strong><a href="' . $link . '">' . esc_html($text === '' ? '—' : $text) . '</a></strong>',
                    'stock' => '<span' . ($row['low'] ? ' style="color:#b32d2e;font-weight:600"' : '') . '>' . esc_html($text) . '</span>',
                    // His word 2026-11-20 20:23: «ich kann nicht zu dem Platz springen. Oder … zu der Gruppe».
                    'group'  => ($row['groupId'] ?? 0) > 0 && $text !== '' ? '<a href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'group' => $row['groupId']], admin_url('admin.php'))) . '">' . esc_html($text) . '</a>' : esc_html($text === '' ? '—' : $text),
                    'places' => ($row['placeIds'] ?? []) === [] ? esc_html($text === '' ? '—' : $text) : implode('; ', array_map(
                        static fn (int $place, string $path): string => '<a href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'lagerorte', 'ort' => $place], admin_url('admin.php'))) . '">' . esc_html($path) . '</a>',
                        array_keys($row['placeIds']), $row['placeIds'])),
                    default => esc_html($text === '' ? '—' : $text),
                };
            }

            // His wish 2026-11-03: «vielleicht könnte man den button umbuchen schon hier anzeigen» — one lot at one place
            // moves straight from the row; with more, each lies in the details below with its own «Umbuchen».
            $held  = $ledger === null ? [] : $this->heldAt($id, $ledger);
            $move  = count($held) === 1 ? $this->transferDialog($id, $held[0][0], $held[0][1], $held[0][2], $places, 0, true) . ' ' : '';
            // D-1147: the lots are drawn when the row opens, not with the list.
            // His wish 2026-11-06: «druck button pro positon» — the material label from the row; the modeller reads the
            // material's own words when it prints, so the rows cost no query each.
            $print = $this->materialLabel($id, (string) ($row['cells']['name'] ?? ''));
            // D-1462: the row carries what the search looked at, so the head narrows by the same words.
            $body .= ListPage::row(self::BULK_FORM, (string) $id, $cells, $move . ($print === '' ? '' : $print . ' ') . ListPage::iconButton('visibility', __('Öffnen', 'taxmod-stock'), html_entity_decode($link)), ListPage::lazyDetails(self::DETAILS_MATERIAL, ['thing' => $id]), $open, ListView::searched($row));
        }

        // D-1462: every hit on this page — the head narrows and sorts it without asking again.
        return $html . ListPage::table(ListPage::heads($view, $url), $body, ListPage::filterRow(self::FILTER_FORM, $view), ! $waits && ListView::onePage(count($units), self::PER_PAGE))
            . ListPage::pagination(count($units), $paged, self::PER_PAGE, $url);
    }

    /**
     * D-1479: each parent of the variants listed as a row of its own, its stock the sum of theirs; a parent the stock knows
     * already only takes the sum. The names of new parents in one read (CD-7).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, int>            $parentOf variant → parent
     * @param array<int, string>         $technical
     * @param array<int, string>         $groups
     * @return list<array<string, mixed>>
     */
    private function withParentRows(array $rows, array $parentOf, array $technical, array $groups, \TaxmodStock\Core\Things $things): array
    {
        $sums = [];
        $node = [];

        foreach ($rows as $row) {
            if (isset($parentOf[$row['id']])) {
                $parent        = $parentOf[$row['id']];
                $sums[$parent] = ($sums[$parent] ?? 0.0) + (float) $row['stock'];
                $node[$parent] ??= $things->nodeOf($row['id']);
            }
        }

        if ($sums === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if (isset($sums[$row['id']])) {
                $rows[$index]['stock']          = $sums[$row['id']];
                $rows[$index]['cells']['stock'] = $this->number($sums[$row['id']]);
                $rows[$index]['sort']['stock']  = $sums[$row['id']];
                $rows[$index]['parent']         = true;
                unset($sums[$row['id']]);
            }
        }

        $names = $sums === [] ? [] : $this->stock->recordLabels(array_keys($sums));

        foreach ($sums as $parent => $stock) {
            $name   = $names[$parent] ?? '';
            $cells  = ['nr' => (string) $parent, 'name' => $name, 'group' => $groups[$node[$parent] ?? 0] ?? '', 'places' => ''];

            foreach (array_keys($technical) as $relation) {
                $cells['f' . $relation] = '';
            }

            $rows[] = ['id' => $parent, 'parent' => true, 'name' => $name, 'stock' => $stock, 'low' => false, 'words' => [], 'unit' => '',
                'cells' => $cells + ['stock' => $this->number($stock), 'min' => ''],
                'sort'  => ['nr' => $parent, 'stock' => $stock, 'min' => -1.0, 'name' => mb_strtolower($name)]];
        }

        return $rows;
    }

    /**
     * D-1479: the variants of a parent shown stand indented right beneath it, in their own order; a variant whose parent is not
     * shown keeps its place.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, int>            $parentOf
     * @return list<array<string, mixed>>
     */
    private static function variantsBeneath(array $rows, array $parentOf): array
    {
        if ($parentOf === []) {
            return $rows;
        }

        $shown    = array_flip(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $beneath  = [];
        $out      = [];

        foreach ($rows as $row) {
            $parent = $parentOf[$row['id']] ?? 0;

            if ($parent > 0 && isset($shown[$parent])) {
                $row['cells']['name'] = '↳ ' . $row['cells']['name'];
                $row['beneath']       = true;
                $beneath[$parent][]   = $row;
            }
        }

        foreach ($rows as $row) {
            if (isset($beneath[$parentOf[$row['id']] ?? 0])) {
                continue;
            }

            $out[] = $row;

            foreach ($beneath[$row['id']] ?? [] as $variant) {
                $out[] = $variant;
            }
        }

        return $out;
    }

    /**
     * D-1479: the rows as units for paging — a variant drawn beneath its parent belongs to the parent's unit.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private static function units(array $rows): array
    {
        $units = [];

        foreach ($rows as $row) {
            if (! empty($row['beneath']) && $units !== []) {
                $units[count($units) - 1][] = $row;
            } else {
                $units[] = [$row];
            }
        }

        return $units;
    }

    /**
     * D-1174, his word 2026-11-05: «gruppen bestand auswahl aus material tax baum knoten wird alles angezeigt unter dem knoten» —
     * the material tree on the left (the modeller's node tree, fixed on the page), on the right everything in the chosen group
     * and the groups below it, in the material list's table with its lots opened lazily.
     */
    private function groupStock(StockList $list): string
    {
        $group  = $this->sideChosen(self::GROUP_TREE, absint($_GET['group'] ?? 0));
        $group  = isset($this->stock->tree()[$group]) ? $group : 0;
        $pick   = add_query_arg(['page' => self::PAGE, 'tab' => 'gruppenbestand', 'group' => '{id}'], admin_url('admin.php'));
        $tree   = $this->stock->groupChooser('taxmod_stock_group', $group, '', $pick);

        $this->side = $tree !== ''
            ? ListPage::sideTree(self::GROUP_TREE, __('Materialgruppen', 'taxmod-stock'), $tree, '', 'taxmod-stock-places')
            : '<div class="taxmod-stock-places"><div class="taxmod-stock-places-head"><p class="taxmod-shell-title">'
                . esc_html__('Materialgruppen', 'taxmod-stock') . '</p></div>'
                . $tree
                . '</div>' . $this->placeTreeScript();

        $newGroup = $group > 0 ? $this->newGroupDialog($group) : '';

        return ($group > 0 ? '<h2>' . esc_html($this->stock->path($group)) . '</h2>' . ($newGroup === '' ? '' : ListPage::toolbar([[$newGroup]])) . $this->groupProperties($group) : '') . $this->materialList($list, true, $group);
    }

    /**
     * D-1259, his word «wir müssen einstellungen auch an der material gruppe ermöglichen … so etwas wie hazardous»: what the
     * group carries for the stock, inherited from the group above unless set here. A value equal to the parent's reads as
     * inherited, since the modeller answers the resolved value only.
     */
    private function groupProperties(int $group): string
    {
        $parent = $this->stock->groupParents()[$group] ?? 0;
        $tested = $this->stock->groupProperty(StockService::TEST_PROPERTY);
        $places = $this->stock->groupProperty(StockService::PLACE_PROPERTY);
        $label  = static fn (bool|int|null $on): string => $on === true ? __('Ja', 'taxmod-stock') : __('Nein', 'taxmod-stock');
        $test   = $this->stock->ownGroupProperty($group, StockService::TEST_PROPERTY);
        $place  = $this->stock->ownGroupProperty($group, StockService::PLACE_PROPERTY);
        $above  = $places[$parent] ?? null;
        $names  = $this->stock->places();

        $rows = [
            ['label' => __('Nach Wareneingang testen', 'taxmod-stock'), 'for' => 'taxmod-stock-group-test', 'control' => SelectMarkup::of('test', ['1' => __('Ja', 'taxmod-stock'), '0' => __('Nein', 'taxmod-stock')], $test === null ? null : ($test ? '1' : '0'),
                    attributes: ['id' => 'taxmod-stock-group-test'], nothingWord: sprintf(/* translators: %s: Ja or Nein, from the group above */ __('wie darüber (%s)', 'taxmod-stock'), $label($tested[$parent] ?? null))),
                'hint' => __('Gebrauchte Ware dieser Gruppe und aller Gruppen darunter steht nach dem Wareneingang unter «Test».', 'taxmod-stock')],
            ['label' => __('Standard-Lagerort', 'taxmod-stock'), 'control' => $this->stock->placeChooser('place', is_int($place) ? $place : 0),
                'hint' => is_int($above) && isset($names[$above])
                    ? sprintf(/* translators: %s: the place of the group above */ __('Leer: wie darüber, %s. Ein Lagerort am Material geht vor.', 'taxmod-stock'), $names[$above])
                    : __('Leer: wie darüber, sonst der Standard-Ort. Ein Lagerort am Material geht vor.', 'taxmod-stock')],
        ];

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-stock-group-properties">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::GROUP_PROPERTIES) . '"><input type="hidden" name="group" value="' . esc_attr((string) $group) . '">'
            . wp_nonce_field(self::GROUP_PROPERTIES, '_wpnonce', true, false)
            . self::actForm($rows, self::saveButton(__('Gruppe speichern', 'taxmod-stock')))
            . '</form>';
    }

    /** D-1259: admin-post — set the group's own values; «wie darüber» and an empty place let it inherit again. */
    public function saveGroupProperties(): never
    {
        if (! current_user_can('manage_options')) {
            Acts::deny(__('Keine Berechtigung.', 'taxmod-stock'));
        }

        Acts::nonce(self::GROUP_PROPERTIES);
        $group = absint($_POST['group'] ?? 0);

        if (! isset($this->stock->tree()[$group])) {
            throw StockError::fromModel(__('Diese Gruppe gibt es nicht.', 'taxmod-stock'));
        }

        $test  = sanitize_key((string) ($_POST['test'] ?? ''));
        $place = absint($_POST['place'] ?? 0);
        $this->stock->setGroupProperty($group, StockService::TEST_PROPERTY, $test === '' ? null : $test === '1');
        $this->stock->setGroupProperty($group, StockService::PLACE_PROPERTY, $place > 0 && isset($this->stock->places()[$place]) ? $place : null);

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => 'gruppenbestand', 'group' => $group], admin_url('admin.php')));
        exit;
    }

    /**
     * D-1174: what the group's rows add up to, as the modeller's shared tiles. The quantity is left out when the materials count
     * in different units.
     *
     * @param list<array{stock: float, low: bool, unit: string}> $rows
     */
    private function groupTotals(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $totals = GroupStock::totals($rows);
        $tile   = static fn (string $label, string $value, TileTone $tone = TileTone::Normal): Tile => new Tile($label, '', $value, [], $tone);

        return Tiles::grid([
            $tile(__('Materialien', 'taxmod-stock'), (string) $totals['materials']),
            $tile(__('Mit Bestand', 'taxmod-stock'), (string) $totals['inStock'], $totals['inStock'] > 0 ? TileTone::Normal : TileTone::Empty),
            $tile(__('Unter Mindestbestand', 'taxmod-stock'), (string) $totals['low'], $totals['low'] > 0 ? TileTone::Alert : TileTone::Empty),
            $tile(__('Menge gesamt', 'taxmod-stock'), $totals['quantity'] === null ? __('gemischte Einheiten', 'taxmod-stock') : $this->number($totals['quantity']),
                $totals['quantity'] === null ? TileTone::Empty : TileTone::Normal),
        ]);
    }

    /**
     * D-1147: a material row's lots, drawn when the row opens. Capability as for the page; the nonce was checked by the
     * modeller's handler.
     *
     * @param array<string, string> $args
     */
    public function listDetails(?string $html, string $source, array $args): ?string
    {
        if ($source !== self::DETAILS_MATERIAL || ! current_user_can('manage_options')) {
            return $html;
        }

        $thing  = absint($args['thing'] ?? 0);
        // D-1195: an opened row reads its own lots and bookings, not the whole ledger.
        $view   = new self($this->stock->scopedTo($thing));
        $ledger = $view->stock->ledger();

        return $view->lotList($thing, $ledger, count($view->heldAt($thing, $ledger)) > 1);
    }

    /**
     * The fields of a group that make sense as list columns: plain values, not the head's.
     *
     * @return array<int, string> relation id → name
     */
    private function technicalColumns(int $group): array
    {
        $out = [];

        foreach ($this->technicalFields($group) as $relation => $field) {
            if (in_array($field['type'], ['text', 'int', 'decimal'], true)) {
                $out[$relation] = $field['name'];
            }
        }

        return $out;
    }

    /**
     * The fields of a thing's node that belong to «Technische Daten»: all but the head's and the tree's own settings.
     *
     * @return array<int, array{name: string, target: int, type: string|null}>
     */
    private function technicalFields(int $node): array
    {
        $s    = $this->stock->structure();
        $skip = [];

        foreach (self::HEAD_FIELDS as $key) {
            if ($s->hasField($key)) {
                $skip[$s->field($key)] = true;
            }
        }

        return array_filter(
            $this->stock->fieldsOf($node),
            static fn (array $field, int $relation): bool => ! isset($skip[$relation]) && $field['name'] !== 'position',
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** The places where a thing has a rest, by path and project; a lot may lie at several (D-1039). */
    /** @return array<int, string> place id => path */
    private function placesOf(int $thing, \TaxmodStock\Core\Ledger $ledger): array
    {
        $places = $this->stock->places();
        $out    = [];

        foreach ($ledger->lotsOf($thing) as $lot) {
            foreach ($ledger->placesOf($lot) as $place => $quantity) {
                if ($place > 0 && $quantity > 0.0) {
                    $out[$place] = $places[$place] ?? $this->stock->locationTree()->pathOf($place);
                }
            }
        }

        return $out;
    }

    /**
     * The positions of a thing, one line each: date, quantity, price, origin, place — a lot lying at two places is two
     * positions, each with its own «Umbuchen» (his word 2026-11-05: «das müssten zwei positonen … des geleichen materials sein»).
     */
    private function lotList(int $thing, \TaxmodStock\Core\Ledger $ledger, bool $withTransfer = false): string
    {
        $s      = $this->stock->structure();
        $lots   = $this->stock->table('lot');
        $money  = $this->stock->table('money');
        $places = $this->stock->places();
        $lines  = [];
        $html   = '';

        foreach ($ledger->lotsOf($thing) as $lot) {
            $value    = $lots->first($lot, $s->field('lot_value'));
            $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
            $purchase = $lots->first($lot, $s->field('lot_purchase'));
            $built    = $purchase === null ? ($this->stock->producedLots()[$lot] ?? 0) : 0;
            // Stefan 21.11. 15:08: a lot an order built says «Produktion», not «Inventur».
            $origin   = ($amount === null ? '' : ' · ' . esc_html($amount) . ' ' . esc_html__('je Stück', 'taxmod-stock'))
                . ($purchase !== null ? ' · ' . esc_html__('Kauf', 'taxmod-stock') . ' #' . esc_html($purchase)
                    : ($built > 0 ? ' · ' . esc_html__('Produktion', 'taxmod-stock') . ' #' . esc_html((string) $built) : ' · ' . esc_html__('Inventur', 'taxmod-stock')));
            // A list item is no table cell: the date as the site writes it, without the sort span.
            $date     = esc_html(ListPage::date(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10)));
            $at       = array_filter($ledger->placesOf($lot), static fn (float $q): bool => $q > 0.0);

            if ($at === []) {
                $html .= '<li>' . $date . ' · ' . esc_html__('Rest', 'taxmod-stock') . ' ' . esc_html($this->number($ledger->restOf($lot))) . $origin . '</li>';

                continue;
            }

            // D-1181, his word «verschmilzt es solange die daten gleich sind» — «können wir so bauen»: lots of the same data
            // at the same place stand as one line; the lots themselves stay apart.
            $condition = $s->hasField('lot_condition') ? (string) $lots->first($lot, $s->field('lot_condition')) : '';

            foreach ($at as $place => $quantity) {
                $key = $place . '|' . $date . '|' . $origin . '|' . $condition;
                $lines[$key] ??= ['place' => (int) $place, 'text' => $date . ' · %s' . $origin, 'quantity' => 0.0, 'lots' => []];
                $lines[$key]['quantity'] += $quantity;
                $lines[$key]['lots'][]    = $lot;
            }
        }

        foreach ($lines as $line) {
            $html .= '<li>' . sprintf($line['text'], esc_html__('Rest', 'taxmod-stock') . ' ' . esc_html($this->number($line['quantity'])))
                . ' · ' . esc_html($places[$line['place']] ?? '#' . $line['place'])
                . ($withTransfer ? ' ' . $this->transferDialog($thing, $line['lots'][0], $line['place'], $line['quantity'], $places, 0, true, $line['lots']) : '')
                . '</li>';
        }

        return $html === '' ? '—' : '<ul style="margin:0">' . $html . '</ul>';
    }

    /**
     * Where a thing lies, one entry per lot and place with something left — the cases «Umbuchen» can act on.
     *
     * @return list<array{0: int, 1: int, 2: float}> lot, place, quantity
     */
    private function heldAt(int $thing, \TaxmodStock\Core\Ledger $ledger): array
    {
        $held = [];

        foreach ($ledger->lotsOf($thing) as $lot) {
            foreach ($ledger->placesOf($lot) as $place => $quantity) {
                if ($quantity > 0.0) {
                    $held[] = [$lot, (int) $place, $quantity];
                }
            }
        }

        return $held;
    }

    /**
     * D-1223, his word 2026-11-07: «im grunde drucken wir etiketten für einen bestimmt bestand … charge material und die charge
     * müsste auch textuell auf den etiketten stehen» — the label of one lot: its code is P-<lot>, its fields come from the material,
     * and «Charge» carries the lot's batch number (D-1043).
     */
    /**
     * How many labels, as the model's own integer field (his word 2026-11-08: «kein Standard-Int-Feld. Sondern wieder was
     * Eigenes.»); empty means each record's own number, said beside the field.
     */
    private static function copiesInput(string $formId, string $emptyMeans, ?int $value = null): string
    {
        $input = \Taxmod\WordPress\Draw\DialogNumber::input('copies', $value, 1, 50, $formId);

        return $input . ' <span class="description">' . esc_html($emptyMeans) . '</span>';
    }

    /**
     * D-1433 (E), D-1296: the label button of one lot, as the stock page draws it — for the production's positions, so labels
     * have one way only. '' for no such lot.
     */
    public function lotLabelButton(int $lot): string
    {
        $thing = (int) ($this->stock->ledger()->thingOf($lot) ?? 0);

        if ($thing <= 0) {
            return '';
        }

        return $this->lotLabel($thing, $lot, (string) ($this->stock->recordLabels([$thing])[$thing] ?? ''), $this->charges([$lot])[$lot] ?? '');
    }

    /**
     * D-1461, his wish 2026-11-18 18:41 «bei eingebucht … ein Bestandsetikett»: the label buttons of many lots, names and batch
     * numbers read once for all (CD-7). Lots without material are left out.
     *
     * @param list<int> $lots
     * @return array<int, string> lot → button
     */
    public function lotLabelButtons(array $lots): array
    {
        $ledger  = $this->stock->ledger();
        $thingOf = array_filter(array_combine($lots, array_map(static fn (int $lot): int => (int) ($ledger->thingOf($lot) ?? 0), $lots)) ?: []);
        $names   = $this->stock->recordLabels(array_values(array_unique($thingOf)));
        $this->stock->rememberForms(array_values(array_unique($thingOf)));
        $charges = $this->charges(array_keys($thingOf));
        $out     = [];

        foreach ($thingOf as $lot => $thing) {
            $out[$lot] = $this->lotLabel($thing, $lot, (string) ($names[$thing] ?? ''), $charges[$lot] ?? '');
        }

        return $out;
    }

    private function lotLabel(int $thing, int $lot, string $label, string $charge): string
    {
        [$templates, $first, $origin, $copies] = $this->thingLabel($thing, 'lot');
        $chosen   = $first > 0 ? $templates->get($first) : null;
        $template = $chosen === null ? ['no_rule' => true] : $templates->dialog($first, $origin + ['record' => $thing, 'values' => [self::CHARGE_FIELD => sprintf(/* translators: %s: batch number */ __('Charge: %s', 'taxmod-stock'), $charge)]]);

        return (string) apply_filters('taxmod_label_button', '', 'P', $lot, $label === '' ? '#' . $thing : $label, '', $copies ?? $chosen['copies'] ?? 1, $template);
    }

    /**
     * D-1519: the label a material or a lot starts with — the first print rule that holds, on its printer and with its count;
     * where none holds, none (the catch-all rules per «Wofür» are data). The old preselection by Bauform or group is gone (INF-137).
     *
     * @return array{0: \TaxmodLabel\Core\LabelCatalog|\TaxmodStock\WordPress\NoLabels, 1: int, 2: array<string, mixed>, 3: int|null} labels, label, origin, copies
     */
    private function thingLabel(int $thing, string $kind): array
    {
        $templates = $this->stock->labelTemplates();
        $rule      = $this->stock->labelRule($kind, $thing);
        if ($rule !== null) {
            return [$templates->preferring($rule['printer']), $rule['label'], self::ruleOrigin($rule), $rule['copies']];
        }

        // Prüftor 21.11.: no rule, no preselection — the catch-all rule per «Wofür» is data, never a fallback here.
        return [$templates, 0, [], null];
    }

    /**
     * D-1519: the dialog names the rule that chose, and links to it (the labels module knows where its rules are).
     *
     * @param array{id: int, name: string} $rule
     * @return array{origin: string, rule: int}
     */
    private static function ruleOrigin(array $rule): array
    {
        return ['origin' => sprintf(/* translators: %s: the print rule */ __('Vorgewählt nach Regel: %s', 'taxmod-stock'), $rule['name'] === '' ? '#' . $rule['id'] : $rule['name']), 'rule' => $rule['id']];
    }

    /**
     * D-1043: the batch number of each lot — the order number of the purchase it came from; a lot without one (an Inventur) is
     * known by its own code. One pass over the orders, not one per lot (CD-7).
     *
     * @param list<int> $lots
     * @return array<int, string>
     */
    private function charges(array $lots): array
    {
        $s       = $this->stock->structure();
        $table   = $this->stock->table('lot');
        $orders  = $this->stock->table('order');
        $orderOf = [];
        if ($s->hasField('order_lines') && $s->hasField('order_number')) {
            foreach ($orders->ids() as $order) {
                foreach ($orders->all($order, $s->field('order_lines')) as $purchase) {
                    $orderOf[(int) $purchase] ??= $order;
                }
            }
        }
        $out = [];
        foreach ($lots as $lot) {
            $purchase = (int) $table->first($lot, $s->field('lot_purchase'));
            $number   = isset($orderOf[$purchase]) ? trim((string) $orders->first($orderOf[$purchase], $s->field('order_number'))) : '';
            $out[$lot] = $number !== '' ? $number : 'P-' . $lot;
        }

        return $out;
    }

    /**
     * D-1235: one label for the print thread's «print-label» — a material (M) or a lot (P), on this label of «Materialetiketten»
     * and, when given, this printer; built as the dialog builds it. Null when the code, the label or the printer does not fit.
     *
     * @return array{title: string, template: array<string, mixed>}|null
     */
    public function labelFor(string $prefix, int $id, int $template, int $printer): ?array
    {
        $s         = $this->stock->structure();
        $templates = $this->stock->labelTemplates();
        $chosen    = $templates->get($template);
        if ($prefix === 'O' || $prefix === 'K') {
            return $this->placeLabelFor($id, $template, $printer);
        }
        if ($chosen === null || ! $s->hasNode('label_materials') || ($chosen['node'] ?? 0) !== $s->node('label_materials')) {
            return null;
        }
        $lot   = $prefix === 'P' ? $id : 0;
        $thing = $prefix === 'M' ? $id : ($lot > 0 ? (int) ($this->stock->ledger()->thingOf($lot) ?? 0) : 0);
        if ($thing <= 0) {
            return null;
        }
        $more   = ['record' => $thing] + ($lot > 0 ? ['values' => [self::CHARGE_FIELD => sprintf(/* translators: %s: batch number */ __('Charge: %s', 'taxmod-stock'), $this->charges([$lot])[$lot] ?? 'P-' . $lot)]] : []);
        $dialog = $templates->dialog($template, $more);
        if ($printer > 0) {
            $one = $dialog['printers'][$printer] ?? null;
            if (! is_array($one) || ! in_array($template, $one['allows'] ?? [], true)) {
                return null;
            }
            $dialog['printer'] = array_diff_key($one, ['allows' => 1, 'roll_name' => 1]);
        }
        $title = $this->stock->recordLabels([$thing])[$thing] ?? '';

        return ['title' => $title === '' ? '#' . $thing : $title, 'template' => array_diff_key($dialog, ['choices' => 1, 'chosen' => 1, 'printers' => 1])];
    }

    /**
     * D-1238: a place (O, or K for a movable one) for «print-label», on the label asked for, filled as «Etikett drucken» at the place fills it.
     *
     * @return array{title: string, subtitle: string, template: array<string, mixed>}|null
     */
    private function placeLabelFor(int $place, int $template, int $printer): ?array
    {
        $tree = $this->stock->locationTree();
        if ($template <= 0 || $this->stock->labelTemplates()->get($template) === null || ! $this->stock->table('location')->has($place)) {
            return null;
        }
        [, , $title, $subtitle, , $dialog] = $this->placeLabel($place, $tree, $template);
        if ($dialog === []) {
            return null;
        }
        if ($printer > 0) {
            $one = $dialog['printers'][$printer] ?? null;
            if (! is_array($one) || ! in_array($template, $one['allows'] ?? [], true)) {
                return null;
            }
            $dialog['printer'] = array_diff_key($one, ['allows' => 1, 'roll_name' => 1]);
        }

        return ['title' => $title, 'subtitle' => $subtitle, 'template' => array_diff_key($dialog, ['choices' => 1, 'chosen' => 1, 'printers' => 1, 'words' => 1])];
    }

    /** D-997: one thing opened whole — the head fixed, the views as tabs below. */
    /**
     * D-1153 step 3, D-1160: «Etikett drucken» for a material — the first label of the category «Materialetiketten», its fields
     * read by the modeller from the material itself. Without such a label the modeller's fixed material format stands.
     */
    private function materialLabel(int $thing, string $label): string
    {
        [$templates, $first, $origin, $copies] = $this->thingLabel($thing, 'material');
        $chosen   = $first > 0 ? $templates->get($first) : null;
        $template = $chosen === null ? ['no_rule' => true] : $templates->dialog($first, $origin + ['record' => $thing]);

        return (string) apply_filters('taxmod_label_button', '', 'M', $thing, $label === '' ? '#' . $thing : $label, '', $copies ?? $chosen['copies'] ?? 1, $template);
    }

    private function thingView(int $thing): string
    {
        $s     = $this->stock->structure();
        $nodes = $this->stock->nodesOf([$thing]);
        $node  = $nodes[$thing] ?? null;
        $tree  = $this->stock->tree();

        if ($node === null || ! isset($tree[$node])) {
            throw StockError::notAThing($thing);
        }

        $view   = sanitize_key((string) ($_GET['view'] ?? (self::asksStock() ? 'bestand' : 'daten')));
        $view   = in_array($view, self::VIEWS, true) ? $view : 'daten';
        $table  = $this->stock->tableOf($node);
        $label  = (string) $table->first($thing, $s->field('label'));
        $about  = $s->hasField('description') ? (string) $table->first($thing, $s->field('description')) : '';
        $media  = $this->stock->titleImages([$thing])[$thing] ?? 0;
        $back   = $this->lastList() . '#taxmod-stock-row-' . $thing;
        $chips  = $this->chips($thing);
        $titles = [
            'daten'   => __('Technische Daten', 'taxmod-stock'),
            'einkauf' => __('Einkauf', 'taxmod-stock'),
            'lager'   => __('Lager', 'taxmod-stock'),
            'bestand' => __('Bestand', 'taxmod-stock'),
            'verkauf' => __('Verkauf', 'taxmod-stock'),
        ];

        // D-1329 (his wish 2026-11-13): the list's search on top, to go on to the next material without going back.
        // D-1373: «← Zur Liste» on top, first in the toolbar.
        $search = ListPage::recordHead(__('Material', 'taxmod-stock'), $label === '' ? '—' : $label, $back, __('Zur Liste', 'taxmod-stock')) . ListPage::toolbar([[ListPage::searchBox('taxmod-stock-thing-search', '', __('Nächstes Material suchen …', 'taxmod-stock'), ['page' => self::PAGE, 'tab' => 'material'], __('Suchen', 'taxmod-stock'))]]);
        $html   = $search . '<div class="taxmod-stock-head" style="display:flex;gap:16px;align-items:flex-start;background:#fff;border:1px solid #c3c4c7;padding:16px;margin:8px 0 0">'
            . ($media > 0 ? (string) wp_get_attachment_image($media, [96, 96], false, ['style' => 'width:96px;height:96px;object-fit:cover;border-radius:4px']) : '<div style="width:96px;height:96px;background:#f0f0f1;border-radius:4px"></div>')
            . '<div><p style="margin:0;color:#50575e">' . esc_html(sprintf(/* translators: %d: record id of the thing */ __('Nr. %d', 'taxmod-stock'), $thing)) . ' · ' . $this->pathLinks($node) . '</p>'
            . ($about === '' ? '' : '<p style="margin:0">' . esc_html($about) . '</p>') . $chips . '</div>'
            . '<span style="margin-left:auto;white-space:nowrap"><a class="button button-primary" href="' . esc_url(PurchasePage::reorderUrl($thing)) . '">'
            . esc_html__('Nachbestellen', 'taxmod-stock') . '</a> <a class="button" href="' . esc_url($this->thingUrl($thing, 'bearbeiten')) . '">'
            . esc_html__('Material bearbeiten', 'taxmod-stock') . '</a> ' . $this->copyDialog($thing) . ' ' . $this->moveDialog($thing, $node) . ' ' . $this->materialLabel($thing, $label) . '</span></div>'
            . '<nav class="nav-tab-wrapper" style="margin-top:0">';

        foreach ($titles as $slug => $title) {
            $active = $slug === $view || ($slug === 'daten' && $view === 'bearbeiten');
            $html  .= '<a href="' . esc_url($this->thingUrl($thing, $slug)) . '" class="nav-tab' . ($active ? ' nav-tab-active' : '') . '">' . esc_html($title) . '</a>';
        }

        return $html . '</nav><div style="background:#fff;border:1px solid #c3c4c7;border-top:0;padding:16px">' . match ($view) {
            'bearbeiten' => $this->editView($thing, $node),
            'einkauf' => $this->purchaseView($thing),
            'lager'   => $this->lagerView($thing),
            'bestand' => $this->stockView($thing),
            'verkauf' => '<p>' . esc_html__('Die Verkaufs-Sicht (VK-Preis, Angebote) kommt später.', 'taxmod-stock') . '</p>',
            default   => $this->dataView($thing, $node),
        } . '</div>';
    }

    /** The path of the head, each group a link to its group stock — his word: «damit ich auf display drücken kann sollte dann im gruppen bestand anzeigen». */
    private function pathLinks(int $node): string
    {
        $links = [];

        foreach ($this->stock->pathNodes($node) as $group => $name) {
            $links[] = '<a href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'gruppenbestand', 'group' => $group], admin_url('admin.php'))) . '">' . esc_html($name) . '</a>';
        }

        return implode(' ▸ ', $links);
    }

    /** The list the material was opened from, if it was this user's last one today; else the plain material list. */
    private function lastList(): string
    {
        $plain = add_query_arg(['page' => self::PAGE, 'tab' => 'material'], admin_url('admin.php'));
        $group = add_query_arg(['page' => self::PAGE, 'tab' => 'gruppenbestand'], admin_url('admin.php'));
        $last  = get_transient(self::LAST_LIST . get_current_user_id());

        // His report 2026-11-18 19:52: from «Gruppenbestand» back goes to that group, not to «Material».
        return is_string($last) && (str_starts_with($last, $plain) || str_starts_with($last, $group)) ? $last : $plain;
    }

    /**
     * The links of the head (design 7.3): how many purchases and lots the material has, each opening its tab; a chip with
     * nothing behind it is left out.
     */
    private function chips(int $thing): string
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $bought    = count(array_filter($purchases->ids(), static fn (int $id): bool => (int) $purchases->first($id, $s->field('purchase_model')) === $thing));
        $lots      = count($this->stock->ledger()->lotsOf($thing));
        $chips     = [];

        if ($bought > 0) {
            $chips[] = '<a href="' . esc_url($this->thingUrl($thing, 'einkauf')) . '">' . esc_html(sprintf(/* translators: %d: purchases */ _n('%d Kauf', '%d Käufe', $bought, 'taxmod-stock'), $bought)) . '</a>';
        }

        if ($lots > 0) {
            $chips[] = '<a href="' . esc_url($this->thingUrl($thing, 'bestand')) . '">' . esc_html(sprintf(/* translators: %d: lots */ _n('%d Charge', '%d Chargen', $lots, 'taxmod-stock'), $lots)) . '</a>';
        }

        return $chips === [] ? '' : '<p class="taxmod-stock-chips" style="margin:6px 0 0">' . implode(' · ', $chips) . '</p>';
    }

    /**
     * D-1025: the modeller's own form for the thing, saved here and coming back here. A modeller without that form still
     * opens its own page.
     */
    private function editView(int $thing, int $node): string
    {
        // D-1308: «Abbrechen» joins the editor's toolbar, after «Speichern». Saving comes back to the editor, not the
        // reading view — his report 2026-11-21 16:55 «speichert man material eigenschaften springt er aus dem editionsmodus raus».
        $form = (string) apply_filters('taxmod_record_editor', '', $thing, $this->thingUrl($thing, 'bearbeiten'),
            [['<a class="button" href="' . esc_url($this->thingUrl($thing, 'daten')) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a>']]);

        if ($form === '') {
            return self::editorMissing();
        }

        return $form;
    }

    /** Every field of the thing's node but the head's; references show the name of what they point at. */
    private function dataView(int $thing, int $node): string
    {
        $fields = $this->technicalFields($node);
        // His finding 2026-11-19 20:56 «bearbeiten button bei material details immer noch unten»: the one toolbar on top (D-1373, D-1403).
        $note   = ListPage::toolbar([[ListPage::iconLink(ListPage::ICON_EDIT, __('Bearbeiten', 'taxmod-stock'), $this->thingUrl($thing, 'bearbeiten'))]]);

        // The modeller draws the record as readers see it — units, parts and choices in their own words; the plain table
        // below is only for a modeller without that block.
        if (\WP_Block_Type_Registry::get_instance()->is_registered('taxmod/record')) {
            wp_enqueue_style('taxmod-record-block');

            return $note . render_block(['blockName' => 'taxmod/record', 'attrs' => ['record' => $thing, 'rowFields' => implode(',', array_keys($fields))], 'innerBlocks' => []]);
        }

        $table  = $this->stock->tableOf($node);
        $refs   = [];

        foreach ($fields as $relation => $field) {
            if (! in_array($field['type'], ['text', 'int', 'decimal', 'bool', 'date', 'datetime', 'media'], true)) {
                foreach ($table->all($thing, $relation) as $value) {
                    $refs[] = (int) $value;
                }
            }
        }

        $labels = $this->stock->recordLabels(array_values(array_unique(array_filter($refs))));
        $html   = '<table class="form-table" role="presentation"><tbody>';

        foreach ($fields as $relation => $field) {
            $values = $table->all($thing, $relation);
            $shown  = array_map(static fn (string $v): string => isset($labels[(int) $v]) && $labels[(int) $v] !== '' ? $labels[(int) $v] : $v, $values);
            $html  .= '<tr><th scope="row">' . esc_html($field['name']) . '</th><td>' . esc_html($shown === [] ? '—' : implode(', ', $shown)) . '</td></tr>';
        }

        return $note . $html . '</tbody></table>';
    }

    /** The purchases whose «Modell» is this thing. ⚠️ «Preisangaben» are not listed yet: no ability finds what points here. */
    private function purchaseView(int $thing): string
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $about     = [];

        foreach ($purchases->ids() as $id) {
            if ((int) $purchases->first($id, $s->field('purchase_model')) === $thing) {
                $about[] = $id;
            }
        }

        $priced = [];

        if ($about === []) {
            return $this->sourcesSection($thing, []) . '<p>' . esc_html__('Noch kein Kauf zeigt auf dieses Material.', 'taxmod-stock') . '</p>';
        }

        $money   = $this->stock->table('money');
        $orders  = $this->stock->table('order');
        $orderOf = $this->ordersOfPurchases();

        $orderValue = static fn (?int $order, string $key): string => $order === null || ! $s->hasField($key) ? '' : trim((string) $orders->first($order, $s->field($key)));
        $amountOf   = static function (?string $part) use ($money, $s): string {
            $amount = $part === null ? null : $money->first((int) $part, $s->field('money_amount'));

            return $amount === null ? '—' : ListPage::number(round((float) $amount, 2), 2);
        };

        // D-1249 on the material's purchases too (his report 2026-11-09 «habe kein link zur bestellung wie wirs vereinbart hatten»).
        $inbound = new \TaxmodStock\WordPress\InboundService($this->stock);
        $linked  = static fn (string $text, ?string $url): string => $text === '' ? '—' : ($url === null ? esc_html($text)
            : '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($text) . '</a>');

        $rows = [];

        foreach ($about as $id) {
            $order    = $orderOf[$id] ?? null;
            $merchant = $orderValue($order, 'order_merchant');
            $merchant = $merchant !== '' ? $merchant : $this->stock->platformWord($orderValue($order, 'order_platform'));
            $total    = $s->hasField('purchase_total_price') ? $purchases->first($id, $s->field('purchase_total_price')) : null;
            $unit     = $purchases->first($id, $s->field('purchase_landed_price')) ?? $purchases->first($id, $s->field('purchase_unit_price'));
            $count    = (float) ($purchases->first($id, $s->field('purchase_quantity')) ?? 0);
            $each     = $unit === null ? null : $money->first((int) $unit, $s->field('money_amount'));
            // Without a total of its own, the price per piece times the quantity.
            $sum      = $total !== null ? $amountOf($total) : ($each === null || $count <= 0.0 ? '—' : ListPage::number(round((float) $each * $count, 2), 2));
            $article  = $s->hasField('purchase_number') ? trim((string) ($purchases->first($id, $s->field('purchase_number')) ?? '')) : '';
            $number   = $order === null ? '' : $inbound->orderNumber($order);
            // D-1310, his word 2026-11-11 «auf jeden fall sollte hier ein link zur internen bestellung bestehen»: purchase and order open
            // the order's own view in the Wareneingang at the purchase's row, in a new tab; the shop's order page follows as an icon (D-1249).
            $inside   = $order === null ? null : InboundPage::url(['order' => $order]) . '#taxmod-stock-purchase-' . $id;
            $shop     = $order === null ? null : $inbound->orderLink($order);
            $word     = (string) $purchases->first($id, $s->field('label'));
            $priced[] = ['purchase' => $id, 'number' => $article, 'date' => substr($orderValue($order, 'order_date'), 0, 10), 'price' => $each === null ? null : (float) $each];
            $rows[]   = [($inside === null ? esc_html($word) : '<a href="' . esc_url($inside) . '" target="_blank" rel="noopener">' . esc_html($word) . '</a>')
                . ($article === '' ? '' : '<br><span class="description">' . $linked($article, $order === null ? null : $inbound->productLink($order, $article)) . '</span>'),
                ($inside === null ? '—' : '<a href="' . esc_url($inside) . '" target="_blank" rel="noopener">' . esc_html($number === '' ? '#' . $order : $number) . '</a>')
                . ($shop === null ? '' : ' ' . str_replace('<a ', '<a target="_blank" rel="noopener noreferrer" ', ListPage::iconLink('external', __('Beim Händler öffnen', 'taxmod-stock'), $shop))),
                esc_html($merchant === '' ? '—' : $merchant),
                esc_html(($date = substr($orderValue($order, 'order_date'), 0, 10)) === '' ? '—' : $date),
                esc_html((string) ($purchases->first($id, $s->field('purchase_quantity')) ?? '—')),
                esc_html($amountOf($unit)),
                esc_html($sum)];
        }

        return $this->sourcesSection($thing, $priced) . '<h3>' . esc_html__('Käufe', 'taxmod-stock') . '</h3>'
            . ListPage::grid([[__('Kauf', 'taxmod-stock')], [__('Bestellung', 'taxmod-stock')], [__('Lieferant', 'taxmod-stock')], [__('Datum', 'taxmod-stock')],
                [__('Menge', 'taxmod-stock'), GridMarkup::NUMBER], [__('Preis je Stück', 'taxmod-stock'), GridMarkup::NUMBER], [__('Gesamtpreis', 'taxmod-stock'), GridMarkup::NUMBER]], $rows);
    }

    /**
     * C2 (D-1320): per source with a number, the supplier's current offer through `taxmod_supplier_offers` — the one with the
     * source's number, else the first of the source's firm; none found, none shown. Errors name the supplier.
     *
     * @param list<array{part: ?int, controls: array<int, string>, row: SourceRow}> $lines
     * @param array<int, string> $firms
     * @return array{rows: array<int, string>, errors: list<string>}
     */
    private function currentOffers(array $lines, array $firms): array
    {
        $rows   = [];
        $errors = [];
        $same   = static fn (string $a, string $b): bool => $a !== '' && mb_strtolower(trim($a)) === mb_strtolower(trim($b));

        foreach ($lines as $i => $line) {
            $row = $line['row'];

            if (trim($row->number) === '') {
                continue;
            }

            $answer = apply_filters(PurchasePage::OFFERS, ['list' => [], 'errors' => []], $row->number, 1);
            $list   = array_values(array_filter(is_array($answer['list'] ?? null) ? $answer['list'] : [], 'is_array'));
            $firm   = (string) ($firms[(int) $row->firm] ?? '');
            $offer  = array_values(array_filter($list, static fn (array $o): bool => $same((string) ($o['number'] ?? ''), $row->number)))[0]
                ?? array_values(array_filter($list, static fn (array $o): bool => $same((string) ($o['merchant'] ?? ''), $firm)))[0] ?? null;

            foreach ((array) ($answer['errors'] ?? []) as $merchant => $error) {
                $errors[(string) $merchant] = $merchant . ': ' . $error;
            }

            if ($offer !== null) {
                $text     = static fn (string $key): string => trim((string) ($offer[$key] ?? ''));
                $said     = trim($text('merchant') . ' ' . ($text('price') === '' ? '—' : $text('price') . ' ' . $text('currency'))) . ' · ' . sprintf(/* translators: %d: pieces in stock */ __('%d am Lager', 'taxmod-stock'), (int) ($offer['stock'] ?? 0));
                $rows[$i] = $text('url') === '' ? esc_html($said) : '<a href="' . esc_url($text('url')) . '" target="_blank" rel="noopener noreferrer">' . esc_html($said) . '</a>';
            }
        }

        return ['rows' => $rows, 'errors' => array_values($errors)];
    }

    /**
     * C1 (D-1320): «Bezugsquellen» in the material's «Einkauf» — firm, number and pack content in the modeller's own controls
     * (D-1250, D-1018), the link from the firm's template (D-1249) and the price from the purchases with that number, the
     * latest and the ones before (D-1314); both only shown, never stored. No purchase, no price: «—».
     *
     * @param list<array{purchase: int, number: string, date: string, price: ?float}> $priced
     */
    private function sourcesSection(int $thing, array $priced): string
    {
        $formId = 'taxmod-stock-sources-' . $thing;
        $blank  = max(1, min(10, absint($_GET['zeilen'] ?? 1)));
        $lines  = $this->stock->sourceLines($thing, $formId, $blank);

        if ($lines === []) {
            return '';
        }

        $s      = $this->stock->structure();
        $firms  = array_replace([], ...array_values($this->stock->firms()));
        $prices = \TaxmodStock\Core\SourcePrices::of(array_map(static fn (array $l): SourceRow => $l['row'], $lines), $priced);
        $cell   = static fn (array $l, string $key): string => $s->hasField($key) ? ($l['controls'][$s->field($key)] ?? '') : '';
        // D-1019: the pack content counts in the material's base unit — shown beside the number, never typed; an empty one
        // means one piece only where that unit is «Stück», otherwise nothing is known (architecture, 2026-11-13).
        $unit    = $this->stock->unitWords([$thing])[$thing];
        $empty   = $unit === __('Stück', 'taxmod-stock') ? '1' : '—';
        $content = static fn (array $l): string => (string) preg_replace('/<input\b(?![^>]*type="hidden")/', '<input placeholder="' . esc_attr($empty) . '"', $cell($l, 'source_content'), 1)
            . ' <span class="taxmod-stock-unit">' . esc_html($unit) . '</span>';
        $price  = static fn (array $p): string => $p['price'] === null ? '—' : ListPage::number(round($p['price'], 2), 2) . ($p['date'] === '' ? '' : ' (' . ListPage::date(substr($p['date'], 0, 10)) . ')');
        $more   = add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'thing' => $thing, 'view' => 'einkauf', 'zeilen' => $blank + 1], admin_url('admin.php'));
        // C2 (D-1320): the suppliers' current offer per source, asked only on request — each a call to the supplier.
        $asking = has_filter(PurchasePage::OFFERS) !== false;
        $offers = $asking && absint($_GET['angebote'] ?? 0) === 1 ? $this->currentOffers($lines, $firms) : null;
        $fetch  = add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'thing' => $thing, 'view' => 'einkauf', 'angebote' => 1], admin_url('admin.php'));
        $html   = '<h3>' . esc_html__('Bezugsquellen', 'taxmod-stock') . '</h3>'
            . '<form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="booking" value="quellen">'
            . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::toolbar([[self::saveButton()], ['<a class="button" href="' . esc_url($more) . '">' . esc_html__('+ Zeile', 'taxmod-stock') . '</a>'],
                ...($asking ? [['<a class="button" href="' . esc_url($fetch) . '">' . esc_html__('Angebote holen', 'taxmod-stock') . '</a>']] : [])])
            . ($offers === null ? '' : implode('', array_map(static fn (string $e): string => '<div class="notice notice-warning inline"><p>' . esc_html($e) . '</p></div>', $offers['errors'])))
            . '<table class="widefat striped"><thead><tr><th>' . esc_html__('Firma', 'taxmod-stock') . '</th><th>' . esc_html__('Nummer', 'taxmod-stock') . '</th>'
            . ($s->hasField('source_content') ? '<th>' . esc_html__('Packungsinhalt', 'taxmod-stock') . '</th>' : '')
            . '<th>' . esc_html(sprintf(/* translators: %s: the material's base unit */ __('Letzter Preis je %s', 'taxmod-stock'), $unit)) . '</th><th>' . esc_html__('Verlauf', 'taxmod-stock') . '</th>'
            . ($offers === null ? '' : '<th>' . esc_html__('Angebot', 'taxmod-stock') . '</th>') . '<th>' . esc_html__('Entfernen', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ($lines as $i => $line) {
            $row     = $line['row'];
            $link    = $row->firm === null ? null : \TaxmodStock\Core\PlatformLink::filled($this->stock->linkTemplates((string) ($firms[$row->firm] ?? ''))['product'], $row->number);
            $history = $prices[$i] ?? [];
            $html   .= '<tr><td>' . ($line['part'] === null ? '' : '<input type="hidden" name="quelle[' . $i . '][part]" value="' . esc_attr((string) $line['part']) . '">') . $cell($line, 'source_firm') . '</td>'
                . '<td>' . $cell($line, 'source_number') . ($link === null ? '' : ' ' . str_replace('<a ', '<a target="_blank" rel="noopener noreferrer" ', ListPage::iconLink('external', __('Beim Händler öffnen', 'taxmod-stock'), $link))) . '</td>'
                . ($s->hasField('source_content') ? '<td style="white-space:nowrap">' . $content($line) . '</td>' : '')
                . '<td>' . esc_html($history === [] ? '—' : $price($history[0])) . '</td>'
                . '<td>' . (count($history) < 2 ? '' : '<details><summary>' . esc_html(sprintf(/* translators: %d: earlier purchases */ _n('%d früherer Kauf', '%d frühere Käufe', count($history) - 1, 'taxmod-stock'), count($history) - 1)) . '</summary>'
                    . implode('<br>', array_map(static fn (array $p): string => esc_html($price($p)), array_slice($history, 1))) . '</details>') . '</td>'
                . ($offers === null ? '' : '<td>' . ($offers['rows'][$i] ?? '—') . '</td>')
                . '<td>' . ($line['part'] === null ? '' : ListPage::field(InputKind::Tick, 'quelle[' . $i . '][remove]', '1')) . '</td></tr>';
        }

        return $html . '</tbody></table></form>';
    }

    /** D-997, tab «Lager»: Standard-Lagerort, Mindestbestand, Einzelstück (and «Verwendung» where it is set up). */
    private function lagerView(int $thing): string
    {
        $s      = $this->stock->structure();
        $record = $this->stock->things()->lagerOf($thing);
        $lager  = $this->stock->table('lager');
        $value  = static fn (string $key): string => $record === null || ! $s->hasField($key) ? '' : (string) $lager->first($record, $s->field($key));
        // D-1308: «Speichern» in the toolbar above the form.
        $html   = $this->formStart('lager') . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . ListPage::toolbar([[self::saveButton()]])
            . '<table class="form-table" role="presentation"><tbody>';

        if ($s->hasField('lager_location')) {
            // D-1360, his word 2026-11-14 «einmal im Erdgeschoss und einmal im Obergeschoss … das erste erstmal angezeigt»: every
            // Standard-Lagerort in its order, the first is the proposal, and one free chooser for another.
            $places = $record === null ? [] : array_values(array_filter(array_map('intval', $lager->all($record, $s->field('lager_location')))));
            $html  .= '<tr><th>' . esc_html__('Standard-Lagerorte', 'taxmod-stock') . '</th><td>';

            foreach ([...$places, 0] as $i => $place) {
                $html .= '<div style="margin-bottom:4px">' . $this->stock->placeChooser('locations[' . $i . ']', $place) . '</div>';
            }

            $html .= '<p class="description">' . esc_html__('Der erste ist der Vorschlag im Wareneingang und beim Einbuchen; leer lassen entfernt einen.', 'taxmod-stock') . '</p></td></tr>';
        }

        $html .= '<tr><th>' . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><td>' . ListPage::quantity('minimum', $this->stock->unitWords([$thing])[$thing] ?? '', (string) $value('lager_min'), ['pattern' => '[0-9]*']) . '</td></tr>'
            . '<tr><th>' . esc_html__('Einzelstück', 'taxmod-stock') . '</th><td>' . ListPage::field(InputKind::Tick, 'single', '1', ['checked' => (string) $value('lager_single') === '1']) . '</td></tr>';

        if ($s->hasField('lager_use') && $this->stock->uses() !== []) {
            $html .= '<tr><th>' . esc_html__('Verwendung', 'taxmod-stock') . '</th><td>' . SelectMarkup::of('use', $this->stock->uses(), $value('lager_use'), nothingWord: '—') . '</td></tr>';
        }

        if ($s->hasField('lager_unit') && $s->hasField('lager_prefix')) {
            $units = $this->stock->baseUnits();
            $html .= '<tr><th>' . esc_html__('Basiseinheit', 'taxmod-stock') . '</th><td>' . SelectMarkup::of('prefix', $units['prefixes'], $value('lager_prefix'), nothingWord: '—') . ' '
                . SelectMarkup::of('unit', $units['units'], $value('lager_unit'), nothingWord: '—')
                . '<p class="description">' . esc_html__('Worin das Lager zählt, z. B. milli + Liter für ml. Ohne Angabe: Stück.', 'taxmod-stock') . '</p></td></tr>';
        }

        return $html . '</tbody></table></form>';
    }

    /**
     * D-997, tab «Bestand»: quantity, value and minimum on top, then where the lots lie — one row per lot and place, since
     * an Umbuchung may move part of a lot (D-1039) — with «Umbuchen» and «Ausbuchen», and the Vorgänge of each lot below.
     */
    private function stockView(int $thing): string
    {
        $s       = $this->stock->structure();
        $ledger  = $this->stock->ledger();
        $lots    = $this->stock->table('lot');
        $money   = $this->stock->table('money');
        $places  = $this->stock->places();
        $record  = $this->stock->things()->lagerOf($thing);
        $min     = $record === null ? null : $this->stock->table('lager')->first($record, $s->field('lager_min'));
        $stock   = $ledger->stockOf($thing);
        $moves   = $this->movements($ledger->lotsOf($thing), $thing);
        $flags   = $this->stock->reservedPlaces();
        $held    = $this->stock->reservedByThing()[$thing] ?? 0.0;
        $worth   = 0.0;
        $rows    = '';
        $charges = $this->charges($ledger->lotsOf($thing));
        $name    = (string) ($this->stock->recordLabels([$thing])[$thing] ?? '');
        $orderOf = $this->ordersOfPurchases();
        // D-1342, his wish «ein Kreis mit einem Strich durch in Rot … Tooltip … muss noch getestet werden».
        $blocked = $this->stock->blockedLots($ledger->lotsOf($thing));

        foreach ($ledger->lotsOf($thing) as $lot) {
            $value    = $lots->first($lot, $s->field('lot_value'));
            $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
            $purchase = $lots->first($lot, $s->field('lot_purchase'));
            $worth   += $amount === null ? 0.0 : $ledger->restOf($lot) * (float) $amount;
            $where    = $ledger->placesOf($lot) ?: [$ledger->homeOf($lot) => 0.0];
            $first    = true;

            foreach ($where as $place => $quantity) {
                // D-1227: a lot's further places and its «Vorgänge» move with its first row when the list is sorted or grouped.
                $rows .= '<tr' . ($first ? ' id="taxmod-stock-lot-' . esc_attr((string) $lot) . '"' : ' class="taxmod-list-more"') . '><th scope="row" class="check-column"></th><td>' . ($first ? '#' . esc_html((string) $lot) . (in_array($lot, $blocked, true) ? self::blockedMark() : '') . $this->fromSetMark($this->stock->setHeadOf($lot)) : '') . '</td>'
                    // His wish 2026-11-08: «Bitte auch das Material anzeigen hier in der Liste».
                    . '<td>' . ($first ? esc_html($name) : '') . '</td><td>' . esc_html($places[$place] ?? '—') . $this->reservedMark($place, $flags) . '</td><td>' . esc_html($this->number($quantity)) . '</td>'
                    . '<td>' . ($first ? esc_html($amount ?? '—') : '') . '</td>'
                    // D-1310, his wishes 2026-11-11 «Absprung zum Kauf … in einem neuen Tab», «link zur internen bestellung»: the purchase opens
                    // its order's view in the Wareneingang; without an order, its own view in «Einkauf».
                    . '<td>' . ($first ? ($purchase === null ? esc_html__('Inventur', 'taxmod-stock')
                        : '<a href="' . esc_url(isset($orderOf[(int) $purchase]) ? InboundPage::url(['order' => $orderOf[(int) $purchase]]) . '#taxmod-stock-purchase-' . (int) $purchase
                            : PurchasePage::url(['kauf' => (string) (int) $purchase])) . '" target="_blank" rel="noopener">#' . esc_html((string) $purchase) . '</a>') : '') . '</td>'
                    . '<td>' . ($first ? ListPage::dateCell(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10)) : '') . '</td>'
                    . '<td style="text-align:right;white-space:nowrap">' . ($quantity > 0.0 ? $this->transferDialog($thing, $lot, $place, $quantity, $places) : '')
                    . ' ' . $this->bookOutDialog($thing, $lot, $place, $quantity)
                    . ($first ? ' ' . $this->lotLabel($thing, $lot, $name, $charges[$lot] ?? '') : '') . '</td></tr>';
                $first = false;
            }

            $rows .= '<tr class="taxmod-list-more"><th scope="row" class="check-column"></th><td></td><td colspan="7"><details><summary>' . esc_html__('Vorgänge', 'taxmod-stock') . '</summary>' . ($moves[$lot] ?? '—') . '</details></td></tr>';
        }

        // *The modeller's shared tiles (D-1149): nothing reserved is the shrunk tile, a stock below its minimum the red one.*
        $tile = static fn (string $label, string $value, TileTone $tone = TileTone::Normal): Tile => new Tile($label, '', $value, [], $tone);
        $low  = $min !== null && $stock < (float) $min;

        return Tiles::grid([
            $tile(__('Menge', 'taxmod-stock'), $this->number($stock)),
            $tile(__('Reserviert', 'taxmod-stock'), $this->number($held), $held > 0.0 ? TileTone::Normal : TileTone::Empty),
            $tile(__('Verfügbar', 'taxmod-stock'), $this->number($stock - $held)),
            $tile(__('Wert', 'taxmod-stock'), $this->number($worth)),
            $tile(__('Mindestbestand', 'taxmod-stock'), $min === null ? '—' : $min . ' ' . ($low ? '✗' : '✓'), $min === null ? TileTone::Empty : ($low ? TileTone::Alert : TileTone::Normal)),
        ])
            . $this->reservedOf($thing, $ledger, $flags)
            . $this->misplacedNotice(array_values(array_intersect($ledger->misplaced(), $ledger->lotsOf($thing))))
            . ($rows === '' ? '<p>' . esc_html__('Kein Bestandsposten.', 'taxmod-stock') . '</p>' : ListPage::table([
                [__('Posten', 'taxmod-stock'), '6em'], [__('Material', 'taxmod-stock'), ''], [__('Ort', 'taxmod-stock'), ''], [__('Menge', 'taxmod-stock'), '6em'], [__('Wert je Stück', 'taxmod-stock'), '8em'],
                [__('Kauf', 'taxmod-stock'), '7em'], [__('Buchungsdatum', 'taxmod-stock'), '8em'],
            ], $rows))
            . $this->setSection($thing, $ledger->lotsOf($thing))
            . $this->costSection($ledger->lotsOf($thing));
    }

    /**
     * D-1425, his word 2026-11-17 «der presi soll transparent sein so das man das späte beim verkauf angeben kann»: per
     * completion that brought these lots in, how the price per board came about — parts, set-up, work — frozen at the receipt.
     *
     * @param list<int> $lots this material's lots
     */
    private function costSection(array $lots): string
    {
        $byDeal = [];

        foreach ($this->stock->costBreakdown($lots) as $lot => $breakdown) {
            if ($breakdown !== null) {
                $byDeal[$breakdown['deal']] ??= ['breakdown' => $breakdown, 'lots' => []];
                $byDeal[$breakdown['deal']]['lots'][] = $lot;
            }
        }

        if ($byDeal === []) {
            return '';
        }

        $things  = [];

        foreach ($byDeal as $deal) {
            $things = [...$things, ...array_column($deal['breakdown']['parts'], 'thing'), ...array_column($deal['breakdown']['scrap'], 'thing')];
        }

        $names   = $this->stock->recordLabels(array_values(array_unique($things)));
        $euro    = static fn (?float $amount): string => $amount === null ? '—' : esc_html(ListPage::number($amount, 4) . ' €');
        $name    = static fn (int $thing): string => '<a href="' . esc_url(self::thingUrl($thing, 'bestand')) . '">' . esc_html(($names[$thing] ?? '') !== '' ? $names[$thing] : '#' . $thing) . '</a>';
        $sources = ['lot' => __('Posten', 'taxmod-stock'), 'last_purchase' => __('letzter Kauf', 'taxmod-stock'), 'none' => __('ohne Preis', 'taxmod-stock')];
        $html    = '';

        foreach ($byDeal as $deal) {
            $b    = $deal['breakdown'];
            $rows = [];

            foreach ($b['parts'] as $part) {
                $rows[] = [
                    $name($part['thing']),
                    $part['lot'] > 0 ? '<a href="' . esc_url(self::lotUrl($part['thing'], $part['lot'])) . '">P-' . esc_html((string) $part['lot']) . '</a>' : '—',
                    esc_html(ListPage::number($part['quantity'])),
                    $euro($part['unit_price']),
                    esc_html($sources[$part['source']] ?? $part['source']),
                    $part['unit_price'] === null ? '—' : $euro($part['quantity'] * $part['unit_price']),
                ];
            }

            $rows[] = ['<strong>' . esc_html__('Teile', 'taxmod-stock') . '</strong>', '', '', '', $b['complete'] ? '' : esc_html__('unvollständig', 'taxmod-stock'), $euro($b['parts_total'])];
            /* translators: 1: hours, 2: wage, 3: divisor */
            $rows[] = [esc_html__('Bereitstellung', 'taxmod-stock'), '', '', '', esc_html(sprintf(__('%1$s h × %2$s €/h ÷ %3$s', 'taxmod-stock'), ListPage::number($b['setup']['hours']), ListPage::number($b['setup']['wage'], 2), ListPage::number($b['setup']['divisor']))), $euro($b['setup']['amount'])];
            /* translators: 1: minutes, 2: wage */
            $rows[] = [esc_html__('Arbeit', 'taxmod-stock'), '', '', '', $b['work']['minutes'] === null ? esc_html__('keine Zeit eingegeben', 'taxmod-stock') : esc_html(sprintf(__('%1$s min × %2$s €/h', 'taxmod-stock'), ListPage::number($b['work']['minutes']), ListPage::number($b['work']['wage'], 2))), $euro($b['work']['amount'])];
            $rows[] = ['<strong>' . esc_html__('Summe je Platine', 'taxmod-stock') . '</strong>', '', '', '', '', '<strong>' . $euro($b['total']) . '</strong>'];

            $scrap = array_map(static fn (array $piece): string => esc_html(ListPage::number($piece['quantity'])) . ' × ' . $name($piece['thing']), $b['scrap']);
            $lotsShown = implode(', ', array_map(static fn (int $lot): string => 'P-' . $lot, $deal['lots']));
            /* translators: 1: the order's record, 2: date, 3: the lots */
            $title = sprintf(__('Kalkulation Auftrag #%1$d vom %2$s (%3$s)', 'taxmod-stock'), $b['order'], ListPage::date(substr((string) $b['date'], 0, 10)), $lotsShown);

            $html .= '<section class="taxmod-record-section"><h3>' . esc_html($title) . '</h3>'
                . ListPage::grid([[__('Material', 'taxmod-stock')], [__('Posten', 'taxmod-stock')], [__('Menge je Platine', 'taxmod-stock'), GridMarkup::NUMBER],
                    [__('Stückpreis', 'taxmod-stock'), GridMarkup::NUMBER], [__('Quelle', 'taxmod-stock')], [__('Betrag', 'taxmod-stock'), GridMarkup::NUMBER]], $rows)
                . ($scrap === [] ? '' : '<p class="description">' . esc_html__('Ausschuss, nicht eingerechnet:', 'taxmod-stock') . ' ' . implode(', ', $scrap) . '</p>')
                . '</section>';
        }

        return $html;
    }

    /** D-1381: on a sub-lot, the set lot it came from, linked to that lot's row. */
    private function fromSetMark(int $head): string
    {
        if ($head <= 0) {
            return '';
        }

        $thing = $this->stock->thingOfLot($head);

        return '<br><span class="description">' . esc_html__('aus Set', 'taxmod-stock') . ' <a href="' . esc_url(self::lotUrl($thing, $head)) . '">P-' . esc_html((string) $head) . '</a></span>';
    }

    /**
     * D-1381, his words 2026-11-15 «ein bestand der unterbestände hat»: below a set's lots, what each set lot holds against
     * what the set should contain — a section per set lot in the record's layout (D-1376); the set's wanted content alone
     * while no lot of it holds anything yet.
     *
     * @param list<int> $lots this material's lots
     */
    private function setSection(int $thing, array $lots): string
    {
        $wanted   = $this->stock->setContentOf($thing);
        $holdings = $this->stock->setHoldings($lots);
        $mine     = array_keys($holdings);

        if ($wanted === [] && $mine === []) {
            return '';
        }

        $places = $this->stock->places();
        $names  = $this->stock->recordLabels(array_values(array_unique(array_merge(array_keys($wanted), ...array_map(static fn (array $held): array => array_column($held, 'thing'), array_values($holdings))))));
        $words  = ['ok' => __('vollständig', 'taxmod-stock'), 'weniger' => __('weniger', 'taxmod-stock'), 'mehr' => __('mehr', 'taxmod-stock'),
            'fehlt' => __('fehlt', 'taxmod-stock'), 'nicht im Set' => __('nicht im Set', 'taxmod-stock')];
        $html   = '';

        foreach ($mine === [] ? [0] : $mine as $head) {
            $held   = $holdings[$head] ?? [];
            $where  = array_column($held, 'places', 'lot');

            $rows = '';

            foreach (\TaxmodStock\Core\SetContent::rows($wanted, array_map(static fn (array $one): array => ['lot' => $one['lot'], 'thing' => $one['thing'], 'quantity' => $one['quantity']], $held)) as $row) {
                $at = [];

                foreach ($row['lots'] as $sub) {
                    foreach (array_keys(array_filter($where[$sub] ?? [], static fn (float $q): bool => $q > 0.0)) as $place) {
                        $at[] = '<a href="' . esc_url(self::lotUrl($row['thing'], $sub)) . '">P-' . esc_html((string) $sub) . '</a> ' . esc_html($places[$place] ?? '#' . $place);
                    }
                }

                $rows .= '<tr><th scope="row" class="check-column"></th><td><a href="' . esc_url(self::thingUrl($row['thing'], 'bestand')) . '">' . esc_html(($names[$row['thing']] ?? '') !== '' ? $names[$row['thing']] : '#' . $row['thing']) . '</a></td>'
                    . '<td>' . ($at === [] ? '—' : implode('<br>', $at)) . '</td>'
                    . '<td>' . esc_html($this->number($row['held'])) . '</td><td>' . esc_html($row['wanted'] === null ? '—' : $this->number($row['wanted'])) . '</td>'
                    . '<td>' . esc_html($words[$row['state']] ?? $row['state']) . '</td><td></td></tr>';
            }

            $title = $head > 0
                /* translators: %d: the set lot */
                ? sprintf(__('Inhalt von Set-Posten P-%d', 'taxmod-stock'), $head)
                : __('Set-Inhalt (Soll)', 'taxmod-stock');
            $html .= '<section class="taxmod-record-section"><h3>' . esc_html($title) . '</h3>' . ListPage::table([
                [__('Material', 'taxmod-stock'), ''], [__('Posten und Ort', 'taxmod-stock'), ''], [__('Ist', 'taxmod-stock'), '6em'], [__('Soll', 'taxmod-stock'), '6em'], [__('Stand', 'taxmod-stock'), '8em'],
            ], $rows) . '</section>';
        }

        return $html;
    }

    /**
     * D-1438: an «Umbuchen» without the tick that leaves its material's place empty in the system is held back — the posted
     * fields wait per user and the page comes back with the question open. Nothing is booked by this submit.
     */
    private function askIfSourceEmpty(): void
    {
        $lots     = isset($_POST['lots']) ? array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) $_POST['lots'])))))) : [];
        $lot      = $lots[0] ?? absint($_POST['lot'] ?? 0);
        $from     = absint($_POST['from'] ?? 0);
        $quantity = sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? '')));
        $amount   = (float) str_replace(',', '.', $quantity);

        if ($lot <= 0 || $from <= 0 || $amount <= 0.0 || $amount < $this->stock->restOfThingAt($lot, $from) - 1e-9) {
            return;
        }

        set_transient(self::EMPTY_QUESTION . get_current_user_id(), [
            'thing'      => absint($_POST['thing'] ?? 0),
            'lot'        => $lot,
            'lots'       => count($lots) > 1 ? implode(',', $lots) : '',
            'from'       => $from,
            'location'   => absint($_POST['location'] ?? 0),
            'quantity'   => $quantity,
            'return_tab' => sanitize_key((string) ($_POST['return_tab'] ?? '')),
            'return_ort' => absint($_POST['return_ort'] ?? 0),
        ], HOUR_IN_SECONDS);

        wp_safe_redirect(wp_get_referer() ?: add_query_arg(['page' => self::PAGE, 'tab' => 'material'], admin_url('admin.php')));
        exit;
    }

    /**
     * D-1438: the question «Platz leer?» for the held-back Umbuchung, open as the page arrives — «Platz ist leer» moves
     * only, «Noch dort» moves and books that much back in at the old place, as «Inventur», in the same act.
     */
    private function sourceEmptyQuestion(): string
    {
        $key  = self::EMPTY_QUESTION . get_current_user_id();
        $held = get_transient($key);

        if (! is_array($held)) {
            return '';
        }

        delete_transient($key);

        $thing  = (int) $held['thing'];
        $lot    = (int) $held['lot'];
        $from   = (int) $held['from'];
        $this->prefetchRowFacts([$thing]);
        $name   = $this->rowFacts['name'][$thing] ?? '';
        $place  = $this->stock->places()[$from] ?? '#' . $from;
        $hidden = '';

        foreach (['thing', 'lot', 'lots', 'from', 'location', 'quantity', 'return_tab', 'return_ort'] as $field) {
            if ((string) $held[$field] !== '' && (string) $held[$field] !== '0') {
                $hidden .= '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr((string) $held[$field]) . '">';
            }
        }

        $switch = 'taxmod-stock-source-empty-' . $lot . '-' . $from;
        $body   = '<p>' . esc_html(sprintf(
            /* translators: 1: material, 2: place */
            __('Nach dem Umbuchen liegt laut System nichts mehr von %1$s an %2$s. Ist dort noch Bestand?', 'taxmod-stock'),
            $name === '' ? '#' . $thing : $name,
            $place
        )) . '</p>'
            . $hidden . '<input type="hidden" name="source_checked" value="1">'
            . self::actForm([['label' => __('Noch dort', 'taxmod-stock'), 'control' => $this->stock->consumptionControls()['quantity'],
                'hint' => __('Was am alten Platz noch liegt: wird mit der Umbuchung als Inventur dort eingebucht. Leer lassen, wenn der Platz leer ist.', 'taxmod-stock')]]);
        $foot   = '<input type="hidden" name="booking" value="umbuchen">'
            . '<button type="submit" class="button button-primary" name="source_answer" value="leer">' . esc_html__('Platz ist leer', 'taxmod-stock') . '</button>'
            . '<button type="submit" class="button" name="source_answer" value="noch">' . esc_html__('Noch dort einbuchen', 'taxmod-stock') . '</button>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . DialogMarkup::of(
                $switch,
                '',
                esc_html(sprintf(
                    /* translators: 1: material, 2: place */
                    __('Platz leer? %1$s an %2$s', 'taxmod-stock'),
                    $name === '' ? '#' . $thing : $name,
                    $place
                )),
                $body,
                $foot,
                'screen-reader-text',
                '',
                __('Abbrechen', 'taxmod-stock'),
                true
            )
            . '</form>';
    }

    /**
     * D-1039: «Umbuchen» in the shared dialog — how much (all of it unless he says less) and to which place.
     *
     * @param array<int, string> $places
     */
    private function transferDialog(int $thing, int $lot, int $from, float $quantity, array $places, int $returnPlace = 0, bool $toList = false, array $merged = []): string
    {
        $body = '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '"><input type="hidden" name="lot" value="' . esc_attr((string) $lot) . '">'
            // D-1181: a line standing for several lots of the same data moves from them oldest first.
            . (count($merged) > 1 ? '<input type="hidden" name="lots" value="' . esc_attr(implode(',', array_map('intval', $merged))) . '">' : '')
            . '<input type="hidden" name="from" value="' . esc_attr((string) $from) . '">'
            . self::actForm([
                ['label' => __('Menge', 'taxmod-stock'), 'control' => ListPage::quantity('quantity', $this->stock->unitWords([$thing])[$thing] ?? '', DecimalText::stored($quantity, 3)),
                    'hint' => __('Weniger als alles teilt den Posten auf zwei Orte.', 'taxmod-stock')],
                ['label' => __('Nach', 'taxmod-stock'), 'control' => $this->stock->placeChooser('location', 0, '', [$from]),
                    'hint' => __('Bucht die Menge vom bisherigen Ort ab und am neuen zu, als Vorgang «Umbuchung»; «Stornieren» bei den Vorgängen nimmt es zurück.', 'taxmod-stock')],
                // D-1414, changed by his word 2026-11-18 08:08 «eher … so eine Checkbox … Wenn der Benutzer sagt, ach, jetzt ist der
                // Platz leer, dann kann er das ticken. Ansonsten wird nichts Inventur gebucht»: off by default; the tick is the consent.
                ['label' => __('Quelle leer', 'taxmod-stock'), 'control' => '<label>' . ListPage::field(InputKind::Tick, 'source_empty', '1') . ' '
                    . esc_html(sprintf(
                        /* translators: %s: quantity at the source now */
                        __('Quelle danach leer: was am alten Ort übrig bleibt (jetzt %s), per Inventur auf 0 ausbuchen', 'taxmod-stock'),
                        ListPage::number($quantity)
                    )) . '</label>',
                    'hint' => __('Nur ankreuzen, wenn am alten Ort wirklich nichts mehr liegt. Ein Undo nimmt Umbuchung und Inventur zusammen zurück.', 'taxmod-stock')],
            ]);

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ($returnPlace > 0 ? $this->returnFields('lagerorte', $returnPlace) : '')
            . ($toList ? '<input type="hidden" name="return_tab" value="' . esc_attr(self::BACK_TO_LIST) . '">' : '')
            . ListPage::dialogAction('taxmod-stock-transfer-' . $lot . '-' . $from . ($toList ? '-list' : ''), __('Umbuchen', 'taxmod-stock'), $this->dialogTitle(__('Umbuchen', 'taxmod-stock'), $thing, $lot), $body, 'booking', 'umbuchen', __('Umbuchen', 'taxmod-stock'), '', self::icon('ICON_TRANSFER'))
            . '</form>';
    }

    /**
     * D8 (D-1320): «Verschieben nach …» for the rows ticked — into another group, as «Verschieben» does for one material.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function bulkGroupAsk(): array
    {
        // His wish 2026-11-18 19:52: «wenn die Gruppe, in der es gerade ist, also der Ast zumindest vorausgewählt wäre».
        $chooser = $this->stock->groupChooser('group', absint($_GET['group'] ?? 0), self::BULK_FORM);
        $body    = self::actForm([['label' => __('Gruppe', 'taxmod-stock'), 'control' => $chooser,
                'hint' => __('Felder, die die neue Gruppe zusätzlich hat, kommen leer dazu. Gingen bei einem Material Werte verloren, wird keines verschoben.', 'taxmod-stock')]])
            . '<input type="hidden" name="return_tab" value="' . esc_attr(self::BACK_TO_LIST) . '" form="' . esc_attr(self::BULK_FORM) . '">';

        // His report 2026-11-18 19:52: «umbuchen nach, verschieben nach sind nicht weit auseinander» — each names what it moves.
        return ['taxmod-stock-bulk-group' => [__('Material in andere Gruppe …', 'taxmod-stock'), '<div class="alignleft actions">' . ListPage::dialogAction('taxmod-stock-bulk-group', __('Material in andere Gruppe …', 'taxmod-stock'), __('Material in andere Gruppe verschieben', 'taxmod-stock'), $body, 'booking', 'sammelverschieben', __('Verschieben', 'taxmod-stock'), self::BULK_FORM) . '</div>']];
    }

    /**
     * D-1478, his question 2026-11-19 «wie legt man eine variante an, über eigenen button?»: no button of its own — the ticked
     * materials become the variants of a new material in the bulk choice; nothing while the modeller does not know variants.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function bulkVariantsAsk(): array
    {
        if (! $this->stock->knowsVariants()) {
            return [];
        }

        $body = '<p class="description" style="margin-top:0">' . esc_html__('Ein neues Material übernimmt, was die angehakten gemeinsam haben; sie werden seine Varianten und behalten Nummer, Bestand, Etiketten und ihre Felder «Je Variante» (Farbe, mit/ohne Pins …). Weicht ein anderes Feld ab, wird nichts zusammengefasst und das Feld genannt. Rückgängig machbar.', 'taxmod-stock') . '</p>'
            . '<input type="hidden" name="return_tab" value="' . esc_attr(self::BACK_TO_LIST) . '" form="' . esc_attr(self::BULK_FORM) . '">';

        return ['taxmod-stock-bulk-variants' => [__('Als Varianten zusammenfassen …', 'taxmod-stock'), '<div class="alignleft actions">' . ListPage::dialogAction('taxmod-stock-bulk-variants', __('Als Varianten zusammenfassen …', 'taxmod-stock'), __('Als Varianten zusammenfassen', 'taxmod-stock'), $body, 'booking', 'sammelvarianten', __('Zusammenfassen', 'taxmod-stock'), self::BULK_FORM) . '</div>']];
    }

    /** His wish 2026-11-11: no link out into the modeller; without its record editor the page says the modeller is too old. */
    public static function editorMissing(): string
    {
        return '<div class="notice notice-warning inline"><p>' . esc_html__('Der Taxonomy Modeller ist zu alt: ihm fehlt der Satz-Editor für diese Seite. Bitte den Modeller aktualisieren.', 'taxmod-stock') . '</p></div>';
    }

    /** D8, D-1331: «+ Neue Untergruppe» of the group chosen in «Gruppenbestand»; nothing while the modeller cannot make one. */
    private function newGroupDialog(int $parent): string
    {
        if (! $this->stock->canCreateGroup()) {
            return '';
        }

        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['required' => true, 'size' => 40]) . '</label>'
            . '<input type="hidden" name="group" value="' . esc_attr((string) $parent) . '">';

        return $this->formStart('gruppeanlegen') . ListPage::dialogAction('taxmod-stock-new-group', __('+ Neue Untergruppe', 'taxmod-stock'), __('Neue Untergruppe', 'taxmod-stock'), $body, 'booking', 'gruppeanlegen', __('Anlegen', 'taxmod-stock')) . '</form>';
    }

    /**
     * «Umbuchen nach …» for the rows ticked: the target in the shared dialog; the fields join the bulk form.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function bulkTransferAsk(): array
    {
        $body = self::actForm([['label' => __('Nach', 'taxmod-stock'), 'control' => $this->stock->placeChooser('location', 0, self::BULK_FORM),
                'hint' => __('Bucht alles von den angehakten Materialien, jeden Posten von jedem Ort, an den gewählten Ort um: ein Vorgang «Umbuchung», «Stornieren» bei den Vorgängen nimmt ihn zurück.', 'taxmod-stock')]])
            . '<input type="hidden" name="return_tab" value="' . esc_attr(self::BACK_TO_LIST) . '" form="' . esc_attr(self::BULK_FORM) . '">';

        return ['taxmod-stock-bulk-transfer' => [__('Bestand an anderen Lagerort …', 'taxmod-stock'), '<div class="alignleft actions">' . ListPage::dialogAction('taxmod-stock-bulk-transfer', __('Bestand an anderen Lagerort …', 'taxmod-stock'), __('Bestand an anderen Lagerort umbuchen', 'taxmod-stock'), $body, 'booking', 'sammelumbuchen', __('Umbuchen', 'taxmod-stock'), self::BULK_FORM) . '</div>']];
    }

    /**
     * D-1202: «Etiketten drucken …» for the ticked materials — a label of «Materialetiketten», a printer its roll allows, and
     * how many each; every label reads its material's own fields when it prints (D-1160).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function materialLabelsAsk(string $formId = self::BULK_FORM, string $per = ''): array
    {
        $s = $this->stock->structure();

        if (! $s->hasNode('label_materials')) {
            return [];
        }

        $templates = $this->stock->labelTemplates();
        $node      = $s->node('label_materials');
        $first     = $templates->firstIn($node);
        $labels    = '';

        foreach ($templates->all() as $id => $template) {
            if (($template['node'] ?? 0) === $node) {
                $labels .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $first, false) . '>' . esc_html($template['name']) . '</option>';
            }
        }

        if ($labels === '') {
            return [];
        }

        $printers = [];

        foreach ($templates->printerList() as $id => $printer) {
            $printers[$id] = (string) ($printer['name'] ?? '#' . $id);
        }

        $service = PrintService::isSetUp();
        $form    = ' form="' . esc_attr($formId) . '"';
        $field   = static fn (string $title, string $control): string => '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html($title) . '</span>' . $control . '</label>';
        $body    = $field(__('Etikett', 'taxmod-stock'), '<select name="label_choice"' . $form . '>' . $labels . '</select>')
            . ($service ? $field(__('Drucker', 'taxmod-stock'), SelectMarkup::of('label_printer', $printers, formId: $formId, nothingWord: __('Wie beim Etikett hinterlegt', 'taxmod-stock'))) : '')
            . $field(__('Anzahl je Material', 'taxmod-stock'), self::copiesInput($formId, __('leer: wie beim Etikett', 'taxmod-stock')))
            . '<span class="description" style="display:block">' . esc_html($per !== '' ? $per : __('Ein Etikett je angehaktem Material, beschriftet mit seinen eigenen Feldern.', 'taxmod-stock'))
            . ($service ? '' : ' ' . esc_html__('Kein Druckdienst eingerichtet: die Etiketten öffnen sich als PDF.', 'taxmod-stock')) . '</span>';
        $foot    = ($service ? '<button type="submit" class="button button-primary" name="booking" value="materialetiketten-service"' . $form . '>' . esc_html__('Drucken', 'taxmod-stock') . '</button>' : '')
            . '<button type="submit" class="button' . ($service ? '' : ' button-primary') . '" name="booking" value="materialetiketten-pdf" formtarget="_blank"' . $form . '>' . esc_html__('Als PDF öffnen', 'taxmod-stock') . '</button>';

        $dialog = $formId === self::BULK_FORM ? 'taxmod-stock-material-labels' : $formId . '-labels';

        return [$dialog => [__('Etiketten drucken …', 'taxmod-stock'), '<div class="alignleft actions">' . DialogMarkup::of(
            $dialog,
            esc_html__('Etiketten drucken …', 'taxmod-stock'),
            esc_html__('Etiketten drucken', 'taxmod-stock'),
            '<span class="taxmod-dialog-body" style="display:block;padding:.75em 1em;white-space:normal;text-align:left">' . $body . '</span>',
            $foot,
            'button taxmod-dialog-open',
            '',
            __('Abbrechen', 'taxmod-stock')
        ) . '</div>']];
    }

    /**
     * D-1225: what the bulk print got ticked — a material id, or «lot:place:thing» for a lot; each lot once, each material once.
     *
     * @param list<string> $ticked
     * @return list<array{0: int, 1: int}> material, lot (0 for a material label)
     */
    private static function labelItems(array $ticked): array
    {
        $items = [];
        foreach ($ticked as $one) {
            $parts = explode(':', $one);
            $thing = absint((string) end($parts));
            $lot   = count($parts) === 3 ? absint($parts[0]) : 0;
            if ($thing > 0) {
                $items[$lot > 0 ? 'p' . $lot : 'm' . $thing] = [$thing, $lot];
            }
        }

        return array_values($items);
    }

    /**
     * The labels of the ticked materials or lots: queued for the print service, or one PDF with a page each. Ends the request.
     * D-1225: a lot prints as P-<lot> with its «Charge» (D-1223).
     *
     * @param list<array{0: int, 1: int}> $items material, lot (0 for a material label)
     */
    private function printMaterialLabels(array $items, int $chosen, int $printer, int $asked, bool $toService): void
    {
        $things = array_values(array_unique(array_map(static fn (array $item): int => $item[0], $items)));
        $last      = get_transient(self::LAST_LIST . get_current_user_id());
        $url       = is_string($last) && $last !== '' ? $last : $this->lastList();
        $url       = sanitize_key((string) ($_POST['return_tab'] ?? '')) === 'lagerorte' && absint($_POST['return_ort'] ?? 0) > 0
            ? add_query_arg(['page' => self::PAGE, 'tab' => 'lagerorte', 'ort' => absint($_POST['return_ort'])], admin_url('admin.php')) : $url;
        $s         = $this->stock->structure();
        $templates = $this->stock->labelTemplates();
        $template  = $templates->get($chosen);
        $printers  = method_exists($templates, 'printerList') ? $templates->printerList() : [];
        $fail      = function (string $message) use ($url): never {
            Notice::remember('error', $message);
            wp_safe_redirect($url);
            exit;
        };

        if ($things === []) {
            $fail(__('Kein Material angehakt.', 'taxmod-stock'));
        }

        if ($template === null || ! $s->hasNode('label_materials') || ($template['node'] ?? 0) !== $s->node('label_materials')) {
            $fail(__('Unbekanntes Material-Etikett.', 'taxmod-stock'));
        }

        if ($printer > 0 && ! in_array($chosen, $printers[$printer]['allows'] ?? [], true)) {
            $fail(__('In diesem Drucker liegt keine Rolle für dieses Etikett.', 'taxmod-stock'));
        }

        $names  = $this->stock->recordLabels($things);
        $count  = min(50, $asked > 0 ? $asked : max(1, (int) ($template['copies'] ?? 1)));
        $labels = [];

        $charges = $this->charges(array_values(array_filter(array_map(static fn (array $item): int => $item[1], $items))));

        foreach ($items as [$thing, $lot]) {
            $asLot  = $lot > 0;
            $dialog = $templates->dialog($chosen, ['record' => $thing] + ($asLot ? ['values' => [self::CHARGE_FIELD => sprintf(/* translators: %s: batch number */ __('Charge: %s', 'taxmod-stock'), $charges[$lot] ?? 'P-' . $lot)]] : []));
            if ($printer > 0) {
                $dialog['printer'] = array_diff_key($printers[$printer], ['allows' => 1, 'roll_name' => 1]);
            }
            // D-1162: the copies of one material travel together, as one job with that many pages.
            $labels[] = [
                'code'     => LabelPrefix::from($asLot ? 'P' : 'M')->code($asLot ? $lot : $thing),
                'title'    => (string) ($names[$thing] ?? '#' . $thing),
                'copies'   => $count,
                'template' => array_diff_key($dialog, ['choices' => 1, 'chosen' => 1, 'printers' => 1]),
            ];
        }

        $this->printLabels($labels, $toService, $url, 'material-etiketten');
    }

    /**
     * D-1439: the labels drawn by the labels module — its one render path, a list of labels; the stock draws none itself. Ends
     * the request: back to $url with a notice, or the PDF. Without the labels module it says so and nothing breaks.
     *
     * @param list<array{code: string, title: string, subtitle?: string, copies: int, template?: array<string, mixed>, format?: string}> $labels
     */
    private function printLabels(array $labels, bool $toService, string $url, string $file): never
    {
        $toService = $toService && class_exists(PrintService::class) && PrintService::isSetUp();
        $answer    = $labels === [] ? null : apply_filters('taxmod_label_render', null, $labels, $toService ? 'service' : 'pdf');

        if (! is_array($answer)) {
            Notice::remember('error', $answer instanceof \WP_Error ? $answer->get_error_message() : ($labels === []
                ? __('Kein Ort angehakt.', 'taxmod-stock')
                : __('Etiketten brauchen das Modul «Taxmod Etiketten».', 'taxmod-stock')));
            wp_safe_redirect($url);
            exit;
        }

        if ($toService) {
            $total = (int) ($answer['labels'] ?? 0);
            Notice::remember('success', sprintf(/* translators: %d: number of labels */ _n('%d Etikett wartet auf den Druckdienst.', '%d Etiketten warten auf den Druckdienst.', $total, 'taxmod-stock'), $total));
            wp_safe_redirect($url);
            exit;
        }

        $pdf = (string) ($answer['pdf'] ?? '');
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '-' . gmdate('Ymd-His') . '.pdf"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
        exit;
    }

    /**
     * D-1083: «n reserviert → Reservierung …» — each reservation holding some of the thing, and each reserved place where
     * some of it lies; and «Reservieren» for this material.
     */
    private function reservedOf(int $thing, Ledger $ledger, \TaxmodStock\Core\ReservedPlaces $flags): string
    {
        $s        = $this->stock->structure();
        $list     = $this->stock->reservations();
        $places   = $this->stock->places();
        $subjects = $this->stock->recordLabels(array_values(array_filter(array_map(static fn (array $h): int => $h['subject'], $list->all()))));
        $items    = [];

        foreach ($list->ofThing($thing) as $deal => $quantity) {
            $subject = $list->all()[$deal]['subject'];
            $items[] = '<li>' . esc_html($this->number($quantity)) . ' ' . esc_html__('reserviert', 'taxmod-stock') . ' → <a href="' . esc_url($this->reservationUrl('r' . $deal)) . '">'
                . esc_html(sprintf(/* translators: %s: the subject of the reservation */ __('Reservierung für %s', 'taxmod-stock'), $subjects[$subject] ?? '#' . $subject)) . '</a></li>';
        }

        foreach ($flags->holdings($ledger) as $holder => $lots) {
            $quantity = 0.0;

            foreach ($lots as $lot => $at) {
                $quantity += $ledger->thingOf($lot) === $thing ? array_sum($at) : 0.0;
            }

            if ($quantity > 0.0) {
                $items[] = '<li>' . esc_html($this->number($quantity)) . ' ' . esc_html__('reserviert', 'taxmod-stock') . ' → <a href="' . esc_url($this->reservationUrl('o' . $holder)) . '">'
                    . esc_html(sprintf(/* translators: %s: the reserved place, by its path */ __('Reservierung des Ortes %s', 'taxmod-stock'), $places[$holder] ?? $this->stock->locationTree()->pathOf($holder))) . '</a></li>';
            }
        }

        // D-1308: the act as the toolbar above what it is about.
        $button = $s->hasValue('kind_reservation') ? ListPage::toolbar([[$this->reserveFormDialog($thing, 'bestand')]]) : '';

        return $button . ($items === [] ? '' : '<ul style="margin:0 0 12px">' . implode('', $items) . '</ul>');
    }

    private function reservationUrl(string $anchor): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => 'reservierungen'], admin_url('admin.php')) . '#taxmod-stock-' . $anchor;
    }

    /** A form around one shared dialog, posting to the page's one handler (CD-5 there). */
    private function dialogForm(string $dialog): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . $dialog . '</form>';
    }

    /**
     * D-1083: «Reservieren» in the shared dialog — subject, deadline, material, quantity, lot 0..1. A subject that has an
     * open reservation gets the position there (one per subject).
     *
     * @param int $thing the material already known (Bestand tab), 0 to pick one
     * @param int $subject the subject already known (a reservation's row), 0 to pick one
     */
    private function reserveFormDialog(int $thing, string $back, int $subject = 0): string
    {
        $ledger  = $this->stock->ledger();
        $lots    = $this->stock->table('lot');
        $date    = $this->stock->structure()->field('lot_date');
        $choices = [];

        foreach ($this->stock->subjects() as $group => $records) {
            foreach ($records as $id => $word) {
                $choices[$id] = $group . ' · ' . $word;
            }
        }

        $lotChoice = [];

        foreach ($thing > 0 ? $ledger->lotsOf($thing) : [] as $lot) {
            if ($ledger->restOf($lot) > 0.0) {
                $lotChoice[$lot] = '#' . $lot . ' · ' . substr((string) $lots->first($lot, $date), 0, 10) . ' · ' . __('Rest', 'taxmod-stock') . ' ' . $this->number($ledger->restOf($lot));
            }
        }

        $id   = 'taxmod-stock-reserve-' . $thing . '-' . $subject . '-' . $back;
        $body = '<input type="hidden" name="back" value="' . esc_attr($back) . '">'
            . ($subject > 0 ? '<input type="hidden" name="subject" value="' . esc_attr((string) $subject) . '">'
                : '<label style="display:block">' . esc_html__('Bezug', 'taxmod-stock') . '<br>' . SelectMarkup::of('subject', $choices, attributes: ['style' => 'width:100%', 'required' => 'required'], nothingWord: '—') . '</label>')
            . ($thing > 0 ? '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
                : '<span style="display:block;margin-top:.5em">' . esc_html__('Material', 'taxmod-stock') . '<br>' . $this->thingField('thing', $id . '-thing', null) . '</span>')
            . '<label style="display:block;margin-top:.5em">' . esc_html__('Menge', 'taxmod-stock') . '<br>' . ListPage::quantity('quantity', $thing > 0 ? ($this->stock->unitWords([$thing])[$thing] ?? '') : '', '', ['required' => 'required']) . '</label>'
            . ($thing > 0 ? '<label style="display:block;margin-top:.5em">' . esc_html__('Bestandsposten', 'taxmod-stock') . '<br>' . SelectMarkup::of('lot', $lotChoice, attributes: ['style' => 'width:100%'], nothingWord: __('— kein bestimmter Posten —', 'taxmod-stock')) . '</label>'
                : '<label style="display:block;margin-top:.5em">' . esc_html__('Bestandsposten (Nr., optional)', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Number, 'lot', '', ['min' => 1]) . '</label>')
            . ($this->stock->structure()->hasField('transaction_deadline') ? '<label style="display:block;margin-top:.5em">' . esc_html__('Frist (optional)', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Moment, 'deadline', '', ['note' => 'date']) . '</label>' : '')
            . '<p class="description">' . esc_html__('Senkt den verfügbaren Bestand, nicht den Bestand. Hat der Bezug schon eine offene Reservierung, kommt die Position dorthin.', 'taxmod-stock') . '</p>';

        return $this->dialogForm(ListPage::dialogAction($id, $subject > 0 ? __('Position hinzufügen', 'taxmod-stock') : __('Reservieren', 'taxmod-stock'), $this->reserveTitle($thing), $body, 'booking', 'reservieren', __('Reservieren', 'taxmod-stock')));
    }

    /** D-1436: the reservation dialog names its material when it has one, otherwise what it reserves. */
    private function reserveTitle(int $thing): string
    {
        if ($thing <= 0) {
            return __('Material reservieren', 'taxmod-stock');
        }

        $this->prefetchRowFacts([$thing]);
        $name = $this->rowFacts['name'][$thing];

        return sprintf(__('Reservieren: %s', 'taxmod-stock'), $name === '' ? '#' . $thing : $name);
    }

    /** D-1036: the Storno of a reservation, whole or one position, asked in the shared dialog. */
    /**
     * D-1127: each ticked reservation cancelled whole, one Gegenbuchung each — the same act as its row's «Stornieren».
     *
     * @param list<int> $deals
     * @return array<string, mixed>
     */
    private function cancelReservations(array $deals): array
    {
        if ($deals === []) {
            throw StockError::nothingToBook();
        }

        $result = [];

        foreach ($deals as $deal) {
            $result = $this->stock->cancelReservation($deal, null, current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name);
        }

        return $result;
    }

    private function reservationStornoDialog(int $deal, ?int $line, string $what): string
    {
        $body = '<input type="hidden" name="transaction" value="' . esc_attr((string) $deal) . '">'
            . ($line === null ? '' : '<input type="hidden" name="line" value="' . esc_attr((string) $line) . '">')
            . '<p style="margin:0">' . esc_html($what) . '</p><p class="description">' . esc_html__('Eine Gegenbuchung gibt die Menge wieder frei; beide bleiben stehen und werden in den Listen ausgeblendet.', 'taxmod-stock') . '</p>';

        return $this->dialogForm(ListPage::dialogAction(
            'taxmod-stock-rstorno-' . $deal . '-' . ($line ?? 0),
            __('Stornieren', 'taxmod-stock'),
            $line === null ? __('Reservierung stornieren', 'taxmod-stock') : __('Position stornieren', 'taxmod-stock'),
            $body,
            'booking',
            'reservierungstorno',
            __('Stornieren', 'taxmod-stock')
        ));
    }

    /**
     * D-1083, the tab «Reservierungen»: every open reservation of a subject with its positions, and the one reservation of
     * each reserved place (D-1082), whose positions are computed from what lies there and can only be read.
     */
    private function reservationList(): string
    {
        $s = $this->stock->structure();

        if (! $s->hasValue('kind_reservation')) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Reservierungen brauchen die Vorgangsart «Reservierung» und die Felder «Bezug», «Frist» und «Material» (values.kind_reservation, fields.transaction_reference, fields.transaction_deadline, fields.line_thing in der Lager-Struktur).', 'taxmod-stock') . '</p></div>';
        }

        $ledger   = $this->stock->ledger();
        $list     = $this->stock->reservations();
        $flags    = $this->stock->reservedPlaces();
        $tree     = $this->stock->locationTree();
        $places   = $this->stock->places();
        $things   = $this->stock->things()->all();
        $open     = $list->open();
        $subjects = $this->stock->recordLabels(array_values(array_unique(array_filter(array_map(static fn (array $h): int => $h['subject'], $open)))));
        $name     = fn (int $thing): string => '<a href="' . esc_url($this->thingUrl($thing, 'bestand')) . '">' . esc_html($things[$thing] ?? '#' . $thing) . '</a>';
        $body     = '';

        foreach ($open as $deal => $head) {
            $subject = $subjects[$head['subject']] ?? '#' . $head['subject'];
            $lines   = '';

            foreach ($head['positions'] as $line => $position) {
                $lines .= '<li>' . $name($position['thing']) . ' · ' . esc_html($this->number($position['quantity']))
                    . ($position['lot'] === null ? '' : ' · ' . esc_html__('Posten', 'taxmod-stock') . ' #' . esc_html((string) $position['lot']))
                    . ' ' . $this->reservationStornoDialog($deal, $line, sprintf(/* translators: 1: material, 2: quantity */ __('%1$s, %2$s', 'taxmod-stock'), $things[$position['thing']] ?? '#' . $position['thing'], $this->number($position['quantity']))) . '</li>';
            }

            $body .= str_replace('<tr>', '<tr id="taxmod-stock-r' . esc_attr((string) $deal) . '">', ListPage::row(
                self::BULK_FORM,
                'r' . $deal,
                [esc_html($head['label']), esc_html($subject), ListPage::dateCell($head['date']), $head['deadline'] === '' ? '—' : ListPage::dateCell($head['deadline']), esc_html((string) count($head['positions']))],
                $this->reserveFormDialog(0, 'reservierungen', $head['subject']) . ' ' . $this->reservationStornoDialog($deal, null, $head['label'] . ' · ' . $subject),
                '<ul style="margin:0">' . $lines . '</ul>'
            ));
        }

        foreach ($flags->holdings($ledger) + array_fill_keys($flags->flagged(), []) as $holder => $lots) {
            $lines = '';

            foreach ($lots as $lot => $at) {
                foreach ($at as $place => $quantity) {
                    $lines .= '<li>' . $name((int) $ledger->thingOf($lot)) . ' · ' . esc_html($this->number($quantity)) . ' · ' . esc_html__('Posten', 'taxmod-stock') . ' #' . esc_html((string) $lot)
                        . ' · ' . esc_html($places[$place] ?? $this->stock->locationTree()->pathOf($place)) . '</li>';
                }
            }

            $body .= str_replace('<tr>', '<tr id="taxmod-stock-o' . esc_attr((string) $holder) . '">', ListPage::row(
                self::BULK_FORM,
                'o' . $holder,
                [esc_html(sprintf(/* translators: %s: the reserved place, by its path */ __('Ort %s', 'taxmod-stock'), $tree->pathOf($holder))), esc_html($tree->projectOf($holder) ?? __('Produktion', 'taxmod-stock')), '', '—', esc_html((string) count($lots))],
                '<span class="description">' . esc_html__('nur lesbar, aus dem Ort gerechnet', 'taxmod-stock') . '</span>',
                $lines === '' ? '<p class="description">' . esc_html__('Dort liegt nichts.', 'taxmod-stock') . '</p>' : '<ul style="margin:0">' . $lines . '</ul>'
            ));
        }

        // D-1127: the row's «Stornieren» for every ticked reservation; a reserved place is freed only by moving its goods out.
        $storno = ['taxmod-stock-reservations-storno' => [__('Stornieren …', 'taxmod-stock'), '<div class="alignleft actions">'
            . ListPage::dialogAction('taxmod-stock-reservations-storno', __('Stornieren …', 'taxmod-stock'), __('Ausgewählte Reservierungen stornieren', 'taxmod-stock'),
                '<p class="description" style="margin-top:0">' . esc_html__('Je angehakter Reservierung eine Gegenbuchung, die ihre Mengen wieder freigibt; reservierte Orte bleiben, wie sie sind.', 'taxmod-stock') . '</p>',
                'booking', 'reservierungensammelstorno', __('Stornieren', 'taxmod-stock'), self::BULK_FORM) . '</div>']];
        $count  = sprintf(/* translators: %d: number of reservations */ _n('%d Reservierung', '%d Reservierungen', count($open), 'taxmod-stock'), count($open));
        $bar    = str_replace(
            ['name="action" id="' . self::BULK_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::BULK_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"></form>'],
            // D-1403, his word 2026-11-16 «was ist aus der einen toolbar geworden»: «Neue Reservierung» stands in the bulk bar's row.
            self::bulkBar(self::BULK_FORM, $count, '<div class="alignleft actions">' . ListPage::toolbar([[$this->reserveFormDialog(0, 'reservierungen')]]) . '</div>', $storno)
        );

        return '<p>' . esc_html__('Eine Reservierung je Bezug: Projekt oder Verkauf. Ein für die Produktion reservierter Lagerort hat genau eine Reservierung, gerechnet aus dem, was dort liegt.', 'taxmod-stock') . '</p>'
            . $bar
            . ListPage::table([[__('Reservierung', 'taxmod-stock'), ''], [__('Bezug', 'taxmod-stock'), '14em'], [__('Datum', 'taxmod-stock'), '7em'], [__('Frist', 'taxmod-stock'), '7em'], [__('Positionen', 'taxmod-stock'), '6em']],
                $body === '' ? $this->noItems(5) : $body);
    }

    /** D-1301: where the manufacturing module lists the project boxes; null while that module is not there. */
    public static function boxesInModule(): ?string
    {
        return isset(ModulePage::all()[self::MANUFACTURING]) ? add_query_arg('tab', 'boxes', ModulePage::url(self::MANUFACTURING)) : null;
    }

    /**
     * D-1139, his word «projekt gebinde … haben die ja ein bom und auch evtl einen bestand … ob der übereinstimmt bzw was
     * fehlt»: each project box against its project's parts list, read only.
     */
    private function projectBoxList(): string
    {
        $boxes = $this->stock->projectBoxes();

        if ($this->stock->projectBill(0) === null) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Der Abgleich braucht den Weg vom Projekt zur Stückliste: nodes.project, nodes.board, nodes.board_revision, nodes.bom_position und fields.project_boards, board_revisions, revision_positions, position_part, position_count in der Lager-Struktur.', 'taxmod-stock') . '</p></div>';
        }

        $tree     = $this->stock->locationTree();
        $things   = $this->stock->things()->all();
        $bills    = [];
        $wanted   = [];

        foreach ($boxes as $place => $project) {
            $bill           = $this->stock->projectBill($project);
            $rows           = $bill === null ? [] : $bill->compare($this->stock->presentIn($place));
            $bills[$place]  = [$bill, $rows];
            $wanted         = [...$wanted, $project, ...($bill?->revisions ?? []), ...array_column($rows, 'thing')];
        }

        $names = $this->stock->recordLabels(array_values(array_unique(array_filter($wanted, static fn (int $id): bool => $id > 0))));
        $name  = static fn (int $id): string => $things[$id] ?? $names[$id] ?? '#' . $id;
        $body  = '';

        foreach ($bills as $place => [$bill, $rows]) {
            $missing = count(array_filter($rows, static fn (array $row): bool => $row['missing'] > 0.0));
            $surplus = count(array_filter($rows, static fn (array $row): bool => $row['surplus'] > 0.0));
            $lines   = '';

            foreach ($rows as $row) {
                $lines .= '<tr><td><a href="' . esc_url(self::thingUrl($row['thing'], 'bestand')) . '">' . esc_html($name($row['thing'])) . '</a></td>'
                    . '<td style="text-align:right">' . esc_html($this->number($row['needed'])) . '</td><td style="text-align:right">' . esc_html($this->number($row['present'])) . '</td>'
                    . '<td style="text-align:right">' . ($row['missing'] > 0.0 ? '<strong>' . esc_html($this->number($row['missing'])) . '</strong>' : '') . '</td>'
                    . '<td style="text-align:right">' . ($row['surplus'] > 0.0 ? esc_html($this->number($row['surplus'])) : '') . '</td></tr>';
            }

            $revisions = $bill === null || $bill->revisions === [] ? '' : '<p class="description">' . esc_html(sprintf(
                /* translators: %s: the revisions the parts list was read from */
                __('Stückliste aus: %s (neueste Revision je Platine, für 1 Stück)', 'taxmod-stock'),
                implode(', ', array_map($name, $bill->revisions))
            )) . '</p>';
            $details = $bill === null || $bill->needed === []
                ? '<p class="description">' . esc_html__('Das Projekt hat keine Stückliste (Platine › Revision › Positionen).', 'taxmod-stock') . '</p>'
                : $revisions . '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__('Teil', 'taxmod-stock') . '</th><th style="text-align:right">' . esc_html__('Soll', 'taxmod-stock')
                    . '</th><th style="text-align:right">' . esc_html__('Im Gebinde', 'taxmod-stock') . '</th><th style="text-align:right">' . esc_html__('Fehlt', 'taxmod-stock')
                    . '</th><th style="text-align:right">' . esc_html__('Zu viel', 'taxmod-stock') . '</th></tr></thead><tbody>' . $lines . '</tbody></table>';
            $state   = $bill === null || $bill->needed === [] ? __('keine Stückliste', 'taxmod-stock') : ($missing === 0 ? __('vollständig', 'taxmod-stock') : sprintf(
                /* translators: %d: number of parts that are short */
                _n('%d Teil fehlt', '%d Teile fehlen', $missing, 'taxmod-stock'),
                $missing
            ));

            $body .= ListPage::row(
                self::BULK_FORM,
                'g' . $place,
                [esc_html($tree->pathOf($place)), esc_html($name($boxes[$place])), esc_html((string) count($bill?->needed ?? [])), esc_html($state), esc_html($surplus === 0 ? '—' : (string) $surplus)],
                ListPage::iconButton('location', __('Zum Ort', 'taxmod-stock'), add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place], admin_url('admin.php'))),
                $details
            );
        }

        return '<h2>' . esc_html__('Projekt-Gebinde', 'taxmod-stock') . '</h2><p>' . esc_html__('Jeder Lagerort mit Projekt gegen die Stückliste seines Projekts: was da ist, was fehlt, was zu viel ist. Gezählt wird alles im Gebinde und in den Orten darin.', 'taxmod-stock') . '</p>'
            . ListPage::listBar(sprintf(/* translators: %d: boxes */ _n('%d Gebinde', '%d Gebinde', count($boxes), 'taxmod-stock'), count($boxes)), '', '', true)
            . ListPage::table([[__('Gebinde', 'taxmod-stock'), ''], [__('Projekt', 'taxmod-stock'), '14em'], [__('Teile', 'taxmod-stock'), '5em'], [__('Stand', 'taxmod-stock'), '10em'], [__('Zu viel', 'taxmod-stock'), '6em']],
                $body === '' ? $this->noItems(5) : $body);
    }

    /** D-1036: «Stornieren» of a Vorgang, asked in the shared dialog; the Storno names who and when. */
    private function stornoDialog(int $thing, int $deal, string $what, string $booking = 'storno', ?int $line = null): string
    {
        $body = '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '"><input type="hidden" name="transaction" value="' . esc_attr((string) $deal) . '">'
            . ($line === null ? '' : '<input type="hidden" name="line" value="' . esc_attr((string) $line) . '">')
            . '<p style="margin:0">' . esc_html($what) . '</p><p class="description">' . esc_html__('Eine Gegenbuchung hebt den Vorgang auf; beide bleiben stehen und werden in den Listen ausgeblendet.', 'taxmod-stock') . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-stock-storno-' . $deal . '-' . ($line ?? 0), __('Stornieren', 'taxmod-stock'), $line === null ? __('Vorgang stornieren', 'taxmod-stock') : __('Position stornieren', 'taxmod-stock'), $body, 'booking', $booking, __('Stornieren', 'taxmod-stock'))
            . '</form>';
    }

    /**
     * The movements per lot: date, kind, place and quantity of each line, read from the Vorgänge in one go (CD-7). A Storno
     * and what it cancels are left out (D-1036); an Umbuchung and a consumption offer their own Storno, a count one per lot.
     *
     * @param list<int> $lots
     * @return array<int, string> lot → its movements as a list
     */
    private function movements(array $lots, int $thing): array
    {
        $s       = $this->stock->structureWithKinds();
        $lines   = $this->stock->table('line');
        $deals   = $this->stock->table('transaction');
        $places  = $this->stock->places();
        $kinds   = [
            $s->value('kind_receipt')     => __('Wareneingang', 'taxmod-stock'),
            $s->value('kind_consumption') => __('Verbrauch', 'taxmod-stock'),
            $s->value('kind_count')       => __('Inventur', 'taxmod-stock'),
        ] + ($s->hasValue('kind_transfer') ? [$s->value('kind_transfer') => __('Umbuchung', 'taxmod-stock')] : [])
            + ($s->hasValue('kind_sale') ? [$s->value('kind_sale') => __('Verkauf', 'taxmod-stock')] : []);
        // D-1297: a kind below «Verbrauch» is shown with its own name from the model.
        foreach ($this->stock->consumptionKindTree() as $art => $node) {
            $kinds[$art] = __('Verbrauch', 'taxmod-stock') . ' · ' . $node['name'];
        }
        $wanted  = array_flip($lots);
        $hidden  = (new Storno($s))->pairedLines($lines) + Ledger::reservationLines($s, $deals);
        $where   = $s->hasField('line_location') ? $s->field('line_location') : null;
        $dealOf  = [];
        $offered = [];
        $out     = [];

        foreach ($deals->ids() as $deal) {
            foreach ($deals->all($deal, $s->field('transaction_lines')) as $line) {
                $dealOf[(int) $line] = $deal;
            }
        }

        foreach ($lines->ids() as $line) {
            $lot = (int) $lines->first($line, $s->field('line_lot'));

            if (! isset($wanted[$lot]) || isset($hidden[$line])) {
                continue;
            }

            $deal     = $dealOf[$line] ?? null;
            $kind     = $deal === null ? 0 : (int) $deals->first($deal, $s->field('transaction_kind'));
            $place    = $where === null ? 0 : (int) $lines->first($line, $where);
            $transfer = $deal !== null && $s->hasValue('kind_transfer') && $kind === $s->value('kind_transfer');
            $consumed = $deal !== null && in_array($kind, $s->consumptionKinds(), true) && $s->hasField('line_reverses');
            $counted  = $deal !== null && $kind === $s->value('kind_count') && $s->hasField('line_reverses');
            // One Storno per Umbuchung or consumption, at its first line on this lot; a count only for this lot's position.
            $storno   = match (true) {
                $counted                                         => ' ' . $this->stornoDialog($thing, $deal, sprintf(/* translators: 1: the Inventur, 2: record id of the lot */ __('%1$s · Posten #%2$s', 'taxmod-stock'), (string) $deals->first($deal, $s->field('label')), $lot), 'inventurstorno', $line),
                ($transfer || $consumed) && ! isset($offered[$deal]) => ' ' . $this->stornoDialog($thing, $deal, (string) $deals->first($deal, $s->field('label')), $transfer ? 'storno' : 'verbrauchstorno'),
                default                                          => '',
            };

            if ($transfer || $consumed) {
                $offered[$deal] = true;
            }

            $out[$lot]  = ($out[$lot] ?? '') . '<li>' . esc_html($deal === null ? '—' : substr((string) $deals->first($deal, $s->field('transaction_date')), 0, 10))
                . ' · ' . esc_html($kinds[$kind] ?? '')
                . ($place > 0 ? ' · ' . esc_html($places[$place] ?? $this->stock->locationTree()->pathOf($place)) : '')
                . ' · ' . esc_html((string) $lines->first($line, $s->field('line_quantity'))) . $storno . '</li>';
        }

        return array_map(static fn (string $html): string => '<ul style="margin:4px 0 0">' . $html . '</ul>', $out);
    }

    public static function thingUrl(int $thing, string $view): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => 'material', 'thing' => $thing, 'view' => $view], admin_url('admin.php'));
    }

    /**
     * D-1230, his word «generell drucken knopf durch icon drucker ersetzten … umbuchen zwei gegenläufige pfeile … ein icon für
     * ausbuchen»: the modeller's icon for an act, or none on a modeller without them (the word stays then).
     */
    private static function icon(string $constant): string
    {
        return defined(ListPage::class . '::' . $constant) ? (string) constant(ListPage::class . '::' . $constant) : '';
    }

    /** D-1451: a button of its own drawn as the modeller's icon with the word as tooltip, or the word where the icon is missing. */
    private function iconOrWord(string $constant, string $word): string
    {
        $icon = self::icon($constant);

        return $icon === '' ? esc_html($word) : ListPage::icon($icon, $word);
    }

    /** @var array{quantity: string, kind: string}|null the consumption controls, drawn once for all «Ausbuchen» dialogs of a page */
    private ?array $bookOutControls = null;

    /** @var array<string, array<int, string>>|null the purposes, read once for all «Ausbuchen» forms of a page */
    private ?array $purposes = null;

    /** @var array{name: array<int, string>, unit: array<int, string>, pack: array<int, bool>} what the row dialogs need per material */
    private array $rowFacts = ['name' => [], 'unit' => [], 'pack' => []];

    /**
     * CD-7: the names, units and packs of the materials whose rows get dialogs, one read each for all of them.
     *
     * @param list<int> $things
     */
    private function prefetchRowFacts(array $things): void
    {
        $missing = array_values(array_diff(array_unique(array_map('intval', $things)), array_keys($this->rowFacts['name'])));

        if ($missing === []) {
            return;
        }

        // The label each row's print button starts with is read from the material's Bauform — for all rows at once.
        $this->stock->rememberForms($missing);
        $names = $this->stock->recordLabels($missing);
        $units = $this->stock->unitWords($missing);
        $packs = $this->stock->packsOf($missing);

        foreach ($missing as $thing) {
            $this->rowFacts['name'][$thing] = (string) ($names[$thing] ?? '');
            $this->rowFacts['unit'][$thing] = (string) ($units[$thing] ?? '');
            $this->rowFacts['pack'][$thing] = ($packs[$thing] ?? null) !== null;
        }
    }

    /** D-1436: a dialog's title names the act and its subject — «Umbuchen: <material> (P-<lot>)». */
    private function dialogTitle(string $verb, int $thing, int $lot): string
    {
        $this->prefetchRowFacts([$thing]);
        $name = $this->rowFacts['name'][$thing];

        return sprintf('%s: %s (P-%d)', $verb, $name === '' ? '#' . $thing : $name, $lot);
    }

    /**
     * «Ausbuchen» of one row as a dialog (his finding 2026-11-18 08:03: the link to the page «Verbrauch buchen» read as the
     * wrong dialog): the lot and its place are fixed, the rest is the consumption form. A material counted in pins
     * (D-1398) still goes to the page, which cuts whole pieces.
     */
    private function bookOutDialog(int $thing, int $lot, int $place, float $quantity): string
    {
        $page = add_query_arg(['page' => self::PAGE, 'tab' => 'verbrauch', 'thing' => $thing, 'lot' => $lot, 'place' => $place], admin_url('admin.php'));

        $this->prefetchRowFacts([$thing]);

        if ($quantity <= 0.0 || $this->rowFacts['pack'][$thing]) {
            return $this->bookOutButton($page);
        }

        $switch   = 'taxmod-stock-bookout-' . $lot . '-' . $place;
        $form     = $switch . '-form';
        // CD-7: the modeller's controls are drawn once per page; they stand inside the row's form, so they need no form id.
        $controls = $this->bookOutControls ??= $this->stock->consumptionControls();
        $unit     = $this->rowFacts['unit'][$thing];
        $rows     = [
            ['label' => __('Menge', 'taxmod-stock'), 'control' => $this->consumptionQuantity($controls['quantity'], $unit),
                /* translators: %s: quantity at this place */
                'hint' => sprintf(__('An diesem Ort liegen %s.', 'taxmod-stock'), ListPage::number($quantity))],
            $this->purposeRow($place, $switch, true),
        ];

        if ($controls['kind'] !== '') {
            $rows[] = ['label' => __('Art', 'taxmod-stock'), 'control' => $controls['kind'], 'hint' => __('Pflicht: wofür die Ware das Lager verlässt.', 'taxmod-stock')];
        }

        $rows[] = ['label' => __('Grund (optional)', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Line, 'reason', '', ['list' => 'taxmod-stock-consumption-reasons', 'size' => 32])];
        $body   = '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '"><input type="hidden" name="lot" value="' . esc_attr((string) $lot) . '">'
            . '<input type="hidden" name="place" value="' . esc_attr((string) $place) . '">'
            . (self::here() === '' ? '' : '<input type="hidden" name="return" value="' . esc_attr(self::returnUrl(rawurlencode(self::here()))) . '">')
            . self::actForm($rows) . self::consumptionReasonList();

        return '<form id="' . esc_attr($form) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction($switch, __('Ausbuchen', 'taxmod-stock'), $this->dialogTitle(__('Ausbuchen', 'taxmod-stock'), $thing, $lot), $body, 'booking', 'verbrauch', __('Ausbuchen', 'taxmod-stock'), '', self::icon('ICON_BOOK_OUT'))
            . '</form>';
    }

    /** The reasons offered for a consumption, once per page (the dialogs and the page share it). */
    private static function consumptionReasonList(): string
    {
        static $drawn = false;

        if ($drawn) {
            return '';
        }

        $drawn = true;

        return '<datalist id="taxmod-stock-consumption-reasons">' . implode('', array_map(static fn (string $r): string => '<option value="' . esc_attr($r) . '">', self::consumptionReasons())) . '</datalist>';
    }

    private function bookOutButton(string $url): string
    {
        // D-1377: the page it was pressed on travels along.
        $here = self::here();
        $url  = $here === '' ? $url : add_query_arg('return', rawurlencode($here), $url);

        return self::icon('ICON_BOOK_OUT') === ''
            ? '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Ausbuchen', 'taxmod-stock') . '</a>'
            : ListPage::iconLink(self::icon('ICON_BOOK_OUT'), __('Ausbuchen', 'taxmod-stock'), $url);
    }

    /**
     * D-1233: a lot moved by Umbuchung and then given another «Lagerort» by hand counts twice at the new place.
     *
     * @param list<int> $lots
     */
    private function misplacedNotice(array $lots): string
    {
        return $lots === [] ? '' : '<div class="notice notice-error inline"><p>' . esc_html(sprintf(
            /* translators: %s: lot numbers */
            __('Posten %s: der Lagerort wurde nach einer Umbuchung direkt geändert, darum ist ein Platz negativ und ein anderer doppelt gezählt. Bitte den Lagerort des Postens zurücksetzen und nur per «Umbuchen» bewegen.', 'taxmod-stock'),
            implode(', ', array_map(static fn (int $lot): string => '#' . $lot, $lots))
        )) . '</p></div>';
    }

    /** A lot's own line in its material's Bestand. */
    public static function lotUrl(int $thing, int $lot): string
    {
        return self::thingUrl($thing, 'bestand') . '#taxmod-stock-lot-' . $lot;
    }

    /**
     * Concept R3: a lot purchase («noch nicht näher bestimmt») told apart. The first row rewrites the purchase, every
     * further row becomes a purchase of the same order; nothing is in stock until the receipt books them.
     */
    private function splitForm(int $order, int $purchase, string $back): string
    {
        $labels = $this->stock->labels('purchase');
        $about  = $this->purchaseAbout([$purchase]);
        $cancel = $back === InboundPage::BACK
            ? InboundPage::url(['order' => $order])
            : add_query_arg(['page' => self::PAGE, 'tab' => 'wareneingang', 'order' => $order], admin_url('admin.php'));
        $html   = $this->formStart('aufteilen')
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . ($back === InboundPage::BACK ? '<input type="hidden" name="back" value="' . esc_attr($back) . '">' : '')
            // D-1308: the acts in the toolbar above the form.
            . ListPage::toolbar([[get_submit_button(__('Aufteilen', 'taxmod-stock'), 'primary', 'submit', false)], ['<a class="button" href="' . esc_url($cancel) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a>']])
            . '<h2>' . esc_html(sprintf(
                /* translators: %s: the lot purchase */
                __('Konvolut aufteilen: %s', 'taxmod-stock'),
                $labels[$purchase] ?? '#' . $purchase
            )) . '</h2>'
            . (($about[$purchase] ?? '') === '' ? '' : '<p class="description">' . esc_html($about[$purchase]) . '</p>')
            . '<p>' . esc_html__('Je Teil eine Zeile: Material suchen oder neu in einer Materialgruppe anlegen, Anzahl, wahlweise Preis je Stück. Die erste Zeile übernimmt den Konvolut-Kauf (ohne Preise bleibt der ganze Preis dort), jede weitere wird ein eigener Kauf der Bestellung. Eingelagert wird erst mit dem Wareneingang.', 'taxmod-stock') . '</p>'
            . '<table class="wp-list-table widefat striped table-view-list"><thead><tr><th scope="col" class="manage-column">' . esc_html__('Material', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('oder neu: Gruppe', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column">' . esc_html__('Bezeichnung', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Anzahl', 'taxmod-stock') . '</th><th scope="col" class="manage-column">'
            . esc_html__('Preis je Stück', 'taxmod-stock') . '</th></tr></thead><tbody>';

        for ($i = 0; $i < self::ROWS; $i++) {
            $html .= '<tr><td>' . $this->thingField('rows[' . $i . '][thing]', 'taxmod-stock-split-' . $i, null) . '</td>'
                . '<td>' . $this->stock->groupChooser('rows[' . $i . '][group]', 0, '', '', true) . '</td>'
                . '<td>' . ListPage::field(InputKind::Line, 'rows[' . $i . '][name]') . '</td>'
                . '<td>' . ListPage::field(InputKind::Number, 'rows[' . $i . '][quantity]', '', ['min' => 1]) . '</td>'
                . '<td>' . ListPage::field(InputKind::Decimal, 'rows[' . $i . '][price]', '', ['size' => 6]) . '</td></tr>';
        }

        return $html . '</tbody></table></form>';
    }

    /** @return list<SplitRow> the filled rows; a row without a material and without a name is no row */
    private function splitRows(): array
    {
        $rows = [];

        foreach ((array) ($_POST['rows'] ?? []) as $row) {
            $row   = is_array($row) ? wp_unslash($row) : [];
            $thing = absint($row['thing'] ?? 0) ?: null;
            $name  = sanitize_text_field((string) ($row['name'] ?? ''));

            if ($thing === null && $name === '') {
                continue;
            }

            if ($name === '' && $thing !== null) {
                $name = $this->stock->recordLabels([$thing])[$thing] ?? '#' . $thing;
            }

            $rows[] = new SplitRow(
                $thing,
                $thing === null ? new NewThing(absint($row['group'] ?? 0), $name) : null,
                $name,
                sanitize_text_field((string) ($row['quantity'] ?? '')) ?: '1',
                sanitize_text_field((string) ($row['price'] ?? '')) ?: null
            );
        }

        return $rows;
    }

    /**
     * His answer 2026-11-09 (Ja to the simpler form): per row the material in the tree chooser with search, quantity, value
     * and note; a new material through «+ Neues Material» in the row, created when the count is booked. The value is per
     * piece or, his word, «als Gesamtwert … Es wird dann umgerechnet».
     */
    private function inventoryForm(): string
    {
        $rows   = '';

        for ($i = 0; $i < self::ROWS; $i++) {
            $rows .= '<tr><th scope="row" class="check-column"></th><td>' . $this->recordPicker('rows[' . $i . '][thing]', null, '') . '</td>'
                . '<td>' . ListPage::quantity('rows[' . $i . '][quantity]', '') . '</td>'
                . '<td>' . ListPage::field(InputKind::Decimal, 'rows[' . $i . '][value]', '', ['size' => 6]) . '</td>'
                . '<td>' . ListPage::field(InputKind::Decimal, 'rows[' . $i . '][total]', '', ['size' => 6]) . '</td>'
                . '<td>' . ListPage::field(InputKind::Line, 'rows[' . $i . '][note]', '', ['size' => 30]) . '</td>'
                . '<td style="text-align:right">' . $this->inventoryNewThing($i) . '</td></tr>';
        }

        $intro  = '<p>' . esc_html__('Gezählter Bestand ohne Kauf. Jede Zeile wird ein Bestandsposten. Wert je Stück oder Gesamtwert; der Gesamtwert wird auf die Menge umgerechnet.', 'taxmod-stock') . '</p>';
        $submit = get_submit_button(__('Inventur buchen', 'taxmod-stock'), 'primary', 'submit', false);
        $table  = ListPage::table([[__('Material', 'taxmod-stock'), ''], [__('Menge', 'taxmod-stock'), '7em'], [__('Wert je Stück', 'taxmod-stock'), '7em'],
            [__('oder Gesamtwert', 'taxmod-stock'), '7em'], [__('Hinweis', 'taxmod-stock'), '']], $rows);

        // The architecture thread 2026-11-09: the repeated rows in the list table, the act's button through the shared form block (modeller 0.2.33).
        // D-1308: the act in the toolbar above the rows.
        // D-1500: the one bar over the rows, as over every list.
        return $this->formStart('inventur') . ListPage::toolbar([[$submit]]) . $intro
            . ListPage::listBar(sprintf(/* translators: %d: rows */ _n('%d Zeile', '%d Zeilen', self::ROWS, 'taxmod-stock'), self::ROWS)) . $table . '</form>';
    }

    /**
     * D-1256: an empty place for the manufacturer; once a group is chosen in the same dialog, the page asks for that group's
     * own manufacturer control ({@see maker()}) and hides the row where the group has none.
     */
    private function manufacturerControl(string $prefix, string $groupField): string
    {
        return $this->stock->structure()->hasNode('manufacturer')
            ? '<span class="taxmod-stock-maker" data-group-field="' . esc_attr($groupField) . '" data-prefix="' . esc_attr($prefix) . '"></span>'
            : '';
    }

    /** ajax: the manufacturer control of one group (D-1256), or nothing. */
    public function maker(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['html' => ''], 403);
        }

        Acts::ajaxNonce(self::MAKER);
        $prefix = (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_GET['prefix'] ?? ''));
        wp_send_json_success(['html' => $prefix === '' ? '' : $this->stock->manufacturerControl(absint($_GET['group'] ?? 0), $prefix)]);
    }

    /** D-1072 in the count: group and name asked in the row's dialog; the material is made when the count is booked. */
    private function inventoryNewThing(int $row): string
    {
        $chooser = $this->stock->groupChooser('rows[' . $row . '][group]', 0, '', '', true);
        $body    = self::actForm([
            ['label' => __('Gruppe', 'taxmod-stock'), 'control' => $chooser],
            ['label' => __('Bezeichnung', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Line, 'rows[' . $row . '][name]', '', ['size' => 40]),
                'hint' => __('Angelegt wird mit «Inventur buchen».', 'taxmod-stock')],
            ...(($maker = $this->manufacturerControl('countfields_' . $row, 'rows[' . $row . '][group]')) === '' ? [] : [['label' => __('Hersteller', 'taxmod-stock'), 'control' => $maker]]),
        ]);

        return \Taxmod\Core\Renderer\DialogMarkup::of(
            'taxmod-stock-count-new-' . $row,
            esc_html__('+ Neues Material', 'taxmod-stock'),
            esc_html__('Neues Material anlegen', 'taxmod-stock'),
            $body,
            '',
            'button button-small taxmod-dialog-open',
            __('Übernehmen', 'taxmod-stock'),
            __('Abbrechen', 'taxmod-stock')
        );
    }

    /** D-1130: «Kopieren» — the material beside itself, asked in the shared dialog because it says what does not come along. */
    private function copyDialog(int $thing): string
    {
        $body = '<p>' . esc_html__('Die Kopie bekommt alle Daten und die Lagersicht dieses Materials und öffnet sich gleich zum Bearbeiten.', 'taxmod-stock') . '</p>'
            . '<p class="description">' . esc_html__('Nicht mitkopiert: Bestand, Reservierungen und die Bestellnummern der Bezugsquellen.', 'taxmod-stock') . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-stock-copy-' . $thing, __('Kopieren', 'taxmod-stock'), __('Material kopieren', 'taxmod-stock'), $body, 'booking', 'kopieren', __('Kopieren', 'taxmod-stock'), '', $this->copyIcon())
            . '</form>';
    }

    /** D-1073: «Verschieben» — the material into another group of the tree, asked in the shared dialog. */
    private function moveDialog(int $thing, int $node): string
    {
        $chooser = $this->stock->groupChooser('group', $node);
        $field   = '<span style="display:block">' . esc_html__('Neue Gruppe', 'taxmod-stock') . '<br>' . $chooser . '</span>'
            . '<p class="description">' . esc_html__('Felder, die die neue Gruppe zusätzlich hat, kommen leer dazu. Gingen dabei Werte verloren, wird nicht verschoben.', 'taxmod-stock') . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-stock-move-' . $thing, __('Verschieben', 'taxmod-stock'), __('Material verschieben', 'taxmod-stock'), $field, 'booking', 'verschieben', __('Verschieben', 'taxmod-stock'))
            . '</form>';
    }

    /**
     * What tells a purchase apart beyond its short label: the seller's full title and the quantity.
     *
     * A label from a mail is often only the variant («DR15 Female»); the description holds the article's title.
     *
     * @param list<int> $purchases
     * @return array<int, string>
     */
    private function purchaseAbout(array $purchases): array
    {
        $s     = $this->stock->structure();
        $table = $this->stock->table('purchase');
        $out   = [];

        foreach ($purchases as $id) {
            $label       = (string) $table->first($id, $s->field('label'));
            $description = $s->hasField('description') ? trim((string) $table->first($id, $s->field('description'))) : '';
            $quantity    = (int) $table->first($id, $s->field('purchase_quantity'));
            $parts       = array_filter([
                $description === $label ? '' : $description,
                $quantity > 1 ? sprintf(/* translators: %d: quantity bought */ __('%d Stück', 'taxmod-stock'), $quantity) : '',
            ]);
            $out[$id] = implode(' · ', $parts);
        }

        return $out;
    }

    /** admin-post handler for the material search of {@see self::recordPicker()}: capability → nonce → validate → act. */
    public function thingSearch(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::THING_SEARCH);

        try {
            $term = sanitize_text_field(wp_unslash((string) ($_GET['term'] ?? '')));
            $hits = empty($_GET['stock']) ? $this->stock->searchThings($term, 30, ! empty($_GET['groups'])) : $this->stock->stockHits($term, 12);

            // D-1387: materials a module prefers (compatible replacements) stand first, as their own group.
            $preferred = array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) ($_GET['preferred'] ?? '')))))));
            $first     = $preferred === [] ? [] : array_map(static fn (array $hit): array => $hit + ['preferred' => true], $this->stock->thingHits($preferred));
            $hits      = [...$first, ...array_values(array_filter($hits, static fn (array $hit): bool => ! in_array($hit['id'], $preferred, true)))];

            // D-1500: a hit with stock says its quantity in the material's own unit — shown only; bookings take the unit of the material.
            $units = $this->stock->unitWords(array_map(static fn (array $hit): int => (int) $hit['id'], $hits));

            wp_send_json_success(empty($_GET['stock']) ? array_map(static fn (array $hit): array => $hit + ['unit' => $units[(int) $hit['id']] ?? ''], $hits)
                : array_map(static fn (array $hit): array => $hit + ['url' => self::thingUrl($hit['id'], 'bestand'), 'unit' => $units[(int) $hit['id']] ?? ''], [...$this->stock->withStock(array_values(array_filter($hits, static fn (array $hit): bool => ! isset($hit['quantity'])))), ...array_values(array_filter($hits, static fn (array $hit): bool => isset($hit['quantity'])))]));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** D-1504: admin-post handler for the unit word of one material: capability → nonce → validate → act. */
    public function thingUnit(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::THING_UNIT);
        $picked = absint($_GET['thing'] ?? 0);

        if ($picked <= 0) {
            wp_send_json_error([], 400);
        }

        // The Verbrauch chooser picks a lot (D-1299); its unit is its material's.
        $thing = $this->stock->ledger()->thingOf($picked) ?? $picked;

        wp_send_json_success(['unit' => $this->stock->unitWords([$thing])[$thing] ?? '']);
    }

    /** admin-post handler for the groups of the tree beside the material search (D-1011): capability → nonce → act. */
    public function thingNodes(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::THING_NODES);

        try {
            wp_send_json_success($this->stock->treeRows());
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** admin-post handler for the things of one group in that tree: capability → nonce → validate → act. */
    public function thingBranch(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::THING_BRANCH);

        try {
            wp_send_json_success($this->stock->branchThings(absint($_GET['node'] ?? 0)));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** D-1299: admin-post handler for the lot search of the Ausbuchen chooser: capability → nonce → validate → act. */
    public function lotSearch(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::LOT_SEARCH);

        try {
            wp_send_json_success($this->stock->searchLots(sanitize_text_field(wp_unslash((string) ($_GET['term'] ?? '')))));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** D-1299: admin-post handler for the lots of one group in the Ausbuchen tree: capability → nonce → validate → act. */
    public function lotBranch(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::LOT_BRANCH);

        try {
            wp_send_json_success($this->stock->branchLots(absint($_GET['node'] ?? 0)));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** admin-post handler for one order's purchases, fetched when he opens the order: capability → nonce → validate → act. */
    public function positions(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['html' => esc_html__('Keine Berechtigung.', 'taxmod-stock')], 403);
        }

        Acts::ajaxNonce(self::POSITIONS);
        $order  = absint($_GET['order'] ?? 0);
        $back   = sanitize_key((string) ($_GET['back'] ?? ''));
        $assign = ! empty($_GET['assign']);
        // Stefan 16.11. «nicht möglich zu sagen dass einzelne teile angekommen sind»: only these purchases ticked.
        $pick   = array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) ($_GET['pick'] ?? '')))))));

        try {
            wp_send_json_success(['html' => $this->receiptPositions($order, $back === InboundPage::BACK ? $back : '', $assign, $pick, sanitize_key((string) ($_GET['stage'] ?? '')))]);
        } catch (StockError $e) {
            wp_send_json_error(['html' => '<div class="notice notice-error inline"><p>' . esc_html(Messages::of($e)) . '</p></div>']);
        }
    }

    /**
     * INF-116, his choice 2026-11-09 «Material schon am Kauf wählen»: the material and place of an order's purchases, kept before it
     * arrives — the receipt's «zuordnen», answered as the message and the form drawn again (D-1203: no reload).
     */
    public function assign(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'taxmod-stock')], 403);
        }

        Acts::ajaxNonce(self::ACTION);
        $order = absint($_POST['order'] ?? 0);

        try {
            $this->stock->assignPurchases($order, $this->assignedThings(), $this->assignedPlaces(), $this->newThings(), $this->receiptQuantities());
            wp_send_json_success([
                'message' => __('Zuordnung gespeichert: Material und Lagerort bleiben stehen, noch nichts eingebucht.', 'taxmod-stock'),
                'html'    => $this->receiptPositions($order, InboundPage::BACK, true, [], sanitize_key((string) ($_POST['stage'] ?? ''))),
            ]);
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /**
     * D-1403: the one bar of a receipt — the bulk choice (place for all, the acts on ticked purchases, taking the receipt back),
     * then saving, booking and the order's own acts, all in one row. The bar stands before the form; its buttons join the form
     * through `form=`, its dialogs' fields too.
     */
    private function receiptBar(int $order, int $count, string $formId, bool $assign, bool $canClose, bool $canUnbook, string $back, bool $unbookOnly = false, string $search = ''): string
    {
        $place = 'taxmod-stock-place-all-' . $order;
        $body  = self::actForm([['label' => __('Lagerort', 'taxmod-stock'), 'control' => $this->stock->placeChooser('fill_location'),
            'hint' => __('Setzt den Lagerort bei allen angehakten Käufen; gespeichert wird er mit «Wareneingang buchen» oder «Zuordnung speichern».', 'taxmod-stock')]]);
        $asks  = [];
        $ask   = static fn (string $switch, string $label, string $title, string $text, string $booking, string $submit): array => [$label,
            '<div class="alignleft actions">' . ListPage::dialogAction($switch, $label, $title, $text, 'booking', $booking, $submit, $formId) . '</div>', true];

        if (! $assign && ! $unbookOnly) {
            // D-1411, his word 2026-11-16 «aber keine bulk aktion für we von angeharkten positionen?»: booking is a choice of the bulk bar.
            // D-1440: on «Angekommen» the purchases of all orders; one without a place goes to «Lagerort Wareneingang».
            $asks['taxmod-stock-book-' . $order] = $order > 0
                ? $ask('taxmod-stock-book-' . $order, __('Wareneingang buchen …', 'taxmod-stock'), __('Angehakte einbuchen', 'taxmod-stock'),
                    '<p>' . esc_html__('Die angehakten Käufe werden mit Material, Menge und Lagerort eingebucht.', 'taxmod-stock') . '</p>', 'wareneingang', __('Einbuchen', 'taxmod-stock'))
                : $ask('taxmod-stock-book-' . $order, __('Angehakte einlagern …', 'taxmod-stock'), __('Angehakte einlagern', 'taxmod-stock'),
                    '<p>' . esc_html__('Die angehakten Käufe werden mit Material, Menge und Lagerort eingebucht; ohne Lagerort an den «Lagerort Wareneingang», von dort per Umbuchen weiter.', 'taxmod-stock') . '</p>', 'wareneingang', __('Einlagern', 'taxmod-stock'));
        }

        if (! $unbookOnly) {
            $asks[$place] = [__('Lagerort für alle …', 'taxmod-stock'), '<div class="alignleft actions">'
                . ListPage::fillAction($place, __('Lagerort für alle angehakten', 'taxmod-stock'), $body, 'location', 'fill_location', __('Übernehmen', 'taxmod-stock')) . '</div>'];

            if ($assign) {
                // Stefan 16.11. «nicht möglich zu sagen dass einzelne teile angekommen sind».
                $asks['taxmod-stock-arrive-' . $order] = $ask('taxmod-stock-arrive-' . $order, __('Angekommen …', 'taxmod-stock'), __('Angehakte Käufe angekommen', 'taxmod-stock'),
                    '<p>' . esc_html__('Die Zuordnung wird gespeichert, und genau die angehakten Käufe stehen danach unter «Angekommen»; die anderen bleiben, wo sie sind.', 'taxmod-stock') . '</p>', 'angekommen', __('Angekommen', 'taxmod-stock'));
            } else {
                // D-1411: a purchase ticked as arrived by mistake steps back; the others stay. His word 2026-11-18 17:13: the choice says where it goes.
                $asks['taxmod-stock-notarrived-' . $order] = $ask('taxmod-stock-notarrived-' . $order, __('Auf «Versendet» zurückstellen …', 'taxmod-stock'), __('Angehakte Käufe auf «Versendet» zurückstellen', 'taxmod-stock'),
                    '<p>' . esc_html__('Die angehakten Käufe gehen zurück unter «Versendet» bzw. «Bestellt»; die anderen bleiben unter «Angekommen».', 'taxmod-stock') . '</p>', 'nichtangekommen', __('Zurückstellen', 'taxmod-stock'));
            }
        }

        if ($canClose) {
            $asks['taxmod-stock-close-all-' . $order] = $ask('taxmod-stock-close-all-' . $order, __('Nicht einlagern …', 'taxmod-stock'), __('Angehakte ohne Lager abschließen', 'taxmod-stock'),
                self::actForm([['label' => __('Grund', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Line, 'bulk_reason', '', ['form' => $formId, 'list' => 'taxmod-stock-reasons', 'size' => 40]),
                    'hint' => __('Gilt für alle angehakten Käufe.', 'taxmod-stock')]]), 'sammelnichteinlagern', __('Nicht einlagern', 'taxmod-stock'));
        }

        if ($canUnbook) {
            // D-1009 per purchase (D-1400): a purchase whose lot was moved on or used stays and is named.
            $asks['taxmod-stock-unbook-' . $order] = $ask('taxmod-stock-unbook-' . $order, __('Wareneingang rückgängig …', 'taxmod-stock'), __('Wareneingang rückgängig machen', 'taxmod-stock'),
                '<p>' . esc_html($order > 0
                    ? __('Nimmt die gebuchten Käufe dieser Bestellung wieder aus dem Bestand; ein Kauf, dessen Posten schon umgebucht oder verbraucht ist, bleibt und wird genannt.', 'taxmod-stock')
                    : __('Nimmt die gebuchten Käufe der Bestellungen der angehakten Käufe wieder aus dem Bestand; ein Kauf, dessen Posten schon umgebucht oder verbraucht ist, bleibt und wird genannt.', 'taxmod-stock')) . '</p>', 'wareneingangzurueck', __('Rückgängig', 'taxmod-stock'));
        }

        $buttons = '<div class="alignleft actions">' . ListPage::toolbar([
            [\Taxmod\Core\Renderer\ControlMarkup::button(\Taxmod\Core\Renderer\Control::saving('booking', 'zuordnen', __('Zuordnung speichern', 'taxmod-stock'), __('Zuordnung speichern', 'taxmod-stock'), true, $formId))],
            [$back === InboundPage::BACK && $order > 0 ? (new InboundPage(new \TaxmodStock\WordPress\InboundService($this->stock)))->orderActs($order) : ''],
        ]) . '</div>';

        return ListPage::bulkBar(
            'taxmod-stock-receipt-bulk-' . $order,
            self::ACTION,
            [],
            /* translators: %d: number of purchases */
            sprintf(_n('%d Kauf', '%d Käufe', $count, 'taxmod-stock'), $count),
            $buttons,
            false,
            $asks,
            $search
        );
    }

    /**
     * @param array<string, array<int, string>> $prices the modeller's words for the prices, per field and purchase
     * @param array<int, array<string, mixed>>  $warned D-1375 warnings per purchase
     */
    private function priceLine(int $purchase, array $prices, array $warned): string
    {
        $parts = array_filter([
            ($prices['purchase_unit_price'][$purchase] ?? '') === '' ? '' : __('je Stück', 'taxmod-stock') . ' ' . $prices['purchase_unit_price'][$purchase],
            ($prices['purchase_total_price'][$purchase] ?? '') === '' ? '' : __('gesamt', 'taxmod-stock') . ' ' . $prices['purchase_total_price'][$purchase],
        ]);

        return ($parts === [] ? '' : '<br><span class="description">' . esc_html(implode(' · ', $parts)) . '</span>') . InboundPage::priceWarning($warned[$purchase] ?? null);
    }

    /**
     * D-1264: the purchases whose picture their material does not hold yet, in one read.
     *
     * @param list<int> $purchases
     * @param array<int, array{model: ?int}> $plan
     * @return array<int, true>
     */
    private function takablePictures(array $purchases, array $plan): array
    {
        $models = array_filter(array_map(static fn (array $row): int => (int) ($row['model'] ?? 0), $plan));
        $held   = $this->stock->picturesOf([...$purchases, ...array_values($models)]);
        $out    = [];

        foreach ($purchases as $purchase) {
            $picture = \TaxmodStock\Core\PurchasePictures::titleOf($held[$purchase] ?? null);

            if ($picture > 0 && isset($models[$purchase]) && ! \TaxmodStock\Core\PurchasePictures::holds($held[$models[$purchase]] ?? ['title' => null, 'pictures' => []], $picture)) {
                $out[$purchase] = true;
            }
        }

        return $out;
    }

    /**
     * The purchases of one order with their thing and place, as one booking form (D-995).
     *
     * @param string $back where the booking returns to: '' the Lager page, {@see InboundPage::BACK} the cockpit (D-1001)
     * @param bool   $assign an order not yet arrived (INF-116): the choice is kept, nothing can be booked
     * @param list<int> $pick the purchases that arrived, ticked alone; empty ticks every purchase with a material
     * @param string    $stage D-1411: the cockpit tab the form stands in — only its purchases; '' every purchase
     */
    private function receiptPositions(int $order, string $back = '', bool $assign = false, array $pick = [], string $stage = ''): string
    {
        $orders = $this->stock->labels('order');

        if (! isset($orders[$order])) {
            throw StockError::nothingToBook();
        }

        $plan   = $this->stock->receiptPlan($order);
        $tab    = \TaxmodStock\Core\InboundStage::tryFrom($stage);
        $one    = $tab === null ? null : ((new \TaxmodStock\WordPress\InboundService($this->stock))->orders()[$order] ?? null);
        $plan   = $one === null ? $plan : array_intersect_key($plan, array_flip($one->purchasesIn($tab, array_keys($plan))));
        $look   = $this->receiptLookups($plan, $assign);
        $first  = $assign ? 'zuordnen' : 'wareneingang';
        $open   = count(array_filter($plan, static fn (array $row): bool => $row['lock'] === null));
        $booked = count(array_filter($plan, static fn (array $row): bool => $row['lock'] === 'received'));
        // His wish 2026-11-09: «bulk funktion platz für alle wählen» — the bar stands before the form, a form holds no form.
        // D-1403, his words 2026-11-16 «wir haben bulk funktionen und du machst zwei buttons dahin», «alles in eine zeile»: every
        // act on ticked purchases is a choice of the bulk bar, and saving, booking and the order's own acts stand beside it.
        $formId = self::receiptFormId($order);
        $html   = $this->receiptBar($order, count($plan), $formId, $assign, $look['closing'] && $open > 0, ! $assign && $booked > 0, $back)
            . $this->receiptFormStart($formId, $first, $assign)
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . ($back === '' ? '' : '<input type="hidden" name="back" value="' . esc_attr($back) . '">')
            . ($tab === null ? '' : '<input type="hidden" name="stage" value="' . esc_attr($tab->value) . '">')
            . $this->receiptHelp($assign, $look['closing'])
            . '<table class="wp-list-table widefat fixed striped table-view-list"><thead>' . $this->receiptHead() . '</thead><tbody>' . ($plan === [] ? $this->noItems(5) : '');

        foreach ($plan as $purchase => $row) {
            $html .= $this->receiptRow($order, (int) $purchase, $row, $look, $assign, $pick, $back, false);
        }

        return $html . '</tbody></table></form>' . $this->stock->sharedTrees();
    }

    /**
     * D-1440, his word 2026-11-18 11:33: «entweder würde ich alle Positionen unter eine Bestellung erwarten … oder ich zeige gleich
     * die Position … und dann habe ich aber auch oben einmal Mehrfachaktionen über alle Positionen»: the purchases of every order
     * shown, under a line naming their order, in one form with one bar — no second level to open.
     *
     * @param array<int, string> $heads order id → its line, drawn by the caller (escaped)
     * @param string             $stage the cockpit tab: only the purchases standing in it
     * @param \Closure|null      $went  D-1452, on «Eingebucht»: purchase → its escaped cells material, place, date
     * @param string             $search D-1500: the page's search box, on the right of the list's one bar
     */
    public function receiptTable(array $heads, string $back, string $stage, ?\Closure $went = null, string $search = ''): string
    {
        $tab    = \TaxmodStock\Core\InboundStage::tryFrom($stage);
        $orders = $tab === null ? [] : (new \TaxmodStock\WordPress\InboundService($this->stock))->orders();
        $plans  = [];

        foreach (array_keys($heads) as $order) {
            $plan           = $this->stock->receiptPlan($order);
            $plans[$order]  = isset($orders[$order]) ? array_intersect_key($plan, array_flip($orders[$order]->purchasesIn($tab, array_keys($plan)))) : $plan;

            // D-1461, his word 2026-11-18 19:00 «bei eingebucht möchte ich diese Position eigentlich nicht mehr sehen»: what was decided
            // on «Angekommen» (privat, nicht eingelagert) is done and stays off «Eingebucht».
            if ($tab === \TaxmodStock\Core\InboundStage::Booked) {
                $plans[$order] = array_filter($plans[$order], static fn (array $row): bool => $row['lock'] === 'received');
            }
        }

        $all    = array_replace([], ...array_values($plans));
        $look   = $this->receiptLookups($all, false);
        $open   = count(array_filter($all, static fn (array $row): bool => $row['lock'] === null));
        $booked = count(array_filter($all, static fn (array $row): bool => $row['lock'] === 'received'));
        $formId = self::receiptFormId(0);
        // D-1452: on «Eingebucht» the booked purchases are ticked for «Wareneingang rückgängig …», the only act there.
        $look['booked_stage'] = $tab === \TaxmodStock\Core\InboundStage::Booked;
        $look['went']         = $went;
        $html   = ($look['booked_stage'] ? $this->receiptBar(0, count($all), $formId, false, false, $booked > 0, $back, true, $search)
                : $this->receiptBar(0, count($all), $formId, false, $look['closing'] && $open > 0, $booked > 0, $back, false, $search))
            . $this->receiptFormStart($formId, 'wareneingang', false)
            . '<input type="hidden" name="multi" value="1">'
            . ($back === '' ? '' : '<input type="hidden" name="back" value="' . esc_attr($back) . '">')
            . ($tab === null ? '' : '<input type="hidden" name="stage" value="' . esc_attr($tab->value) . '">')
            . ($look['booked_stage'] ? '' : $this->receiptHelp(false, $look['closing']))
            . '<table class="wp-list-table widefat fixed striped table-view-list taxmod-stock-receipt-all"><thead>' . $this->receiptHead(true, $look['booked_stage']) . '</thead><tbody>' . ($all === [] ? $this->noItems(6) : '');

        // D-1448, his word 2026-11-18 17:29: no line per order — every purchase a row, its order in its own column, in the order of the orders.
        $look['heads'] = $heads;

        foreach (array_keys($heads) as $order) {
            foreach ($plans[$order] as $purchase => $row) {
                $html .= $this->receiptRow($order, (int) $purchase, $row, $look, false, [], $back, true);
            }
        }

        return $html . '</tbody></table></form>' . $this->stock->sharedTrees();
    }

    /** The id of a receipt form: one order's, or with 0 the one of «Angekommen» over all orders (D-1440). */
    private static function receiptFormId(int $order): string
    {
        return $order > 0 ? 'taxmod-stock-receipt-' . $order : 'taxmod-stock-receipt-all';
    }

    /** The receipt form's opening: its id, the booking a plain Enter sends, and the shared reasons for «Nicht einlagern». */
    private function receiptFormStart(string $formId, string $first, bool $assign): string
    {
        // The bar's buttons stand before the form, so a hidden «booking» after them would win: the form takes it from its submitter.
        return str_replace(['<form ', '<input type="hidden" name="booking" value="' . esc_attr($first) . '">'], ['<form id="' . esc_attr($formId) . '"' . ($assign ? ' class="taxmod-stock-assign"' : '') . ' ', ''], $this->formStart($first))
            // ⚠️ *Enter in a reason field must book, not close the first row: the browser presses the first submit button.*
            . '<button type="submit" name="booking" value="' . esc_attr($first) . '" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px"></button>';
    }

    /** The receipt's explanation, once per page (his finding 2026-11-18: it stood again under every order). */
    private function receiptHelp(bool $assign, bool $closing): string
    {
        return ($closing ? '<datalist id="taxmod-stock-reasons">' . implode('', array_map(static fn (string $r): string => '<option value="' . esc_attr($r) . '">', self::notStockedReasons())) . '</datalist>' : '')
            . ($assign ? '<p>' . esc_html__('Schon vor dem Eintreffen je Kauf das Material wählen oder als «Neues Material» in seiner Gruppe anlegen; die Felder kommen aus der Gruppe. Gespeichert wird nur die Zuordnung, eingebucht wird erst beim Wareneingang. Was angekommen ist: anhaken, dann in der Sammelauswahl «Angekommen …».', 'taxmod-stock') . '</p>' : '')
            . ($assign ? '' : '<p>' . esc_html__('Je Kauf das Material: vorgeschlagen ist das «Modell» des Kaufs; «Zurücksetzen» (Pfeil) gibt die Suche frei; «Neues Material» (oder eine Gruppe in der Suche) legt ein neues Material in der Gruppe an. Der Lagerort ist vorgeschlagen: der Standard-Lagerort des Materials, sonst der Ort seiner Gruppe; Gebrauchtware aus einem Zweig mit Test nach dem Wareneingang geht an den «Lagerort für Test», wenn einer eingestellt ist. Der Materialtext öffnet das Material wie der Knopf «Material». Die Menge ist die gekaufte Anzahl (bei Ware in Packungen: Packungen); geändert wird sie beim Buchen oder Speichern in den Kauf geschrieben.', 'taxmod-stock') . '</p>');
    }

    /** With `$withOrder` (D-1448, «Angekommen» over all orders) the first column names each row's order. */
    /** With `$booked` (D-1452, «Eingebucht») the last column is the day it was booked, linked to its lot, instead of the acts. */
    private function receiptHead(bool $withOrder = false, bool $booked = false): string
    {
        // D-1448, his word 2026-11-18 17:57: «Material ist ja jetzt relativ breit … könnte ja auch ruhig umbrechen» — the purchase gets the room.
        $buy  = $withOrder ? '30%' : '34%';
        $wide = $withOrder ? '18%' : '22%';

        return '<tr><td class="manage-column column-cb check-column">' . ListPage::field(InputKind::Tick, '', '', ['label' => __('Alle wählen', 'taxmod-stock')]) . '</td>'
            . ($withOrder ? '<th scope="col" class="manage-column" style="width:14%">' . esc_html__('Bestellung', 'taxmod-stock') . '</th>' : '')
            . '<th scope="col" class="manage-column" style="width:' . $buy . '">' . esc_html__('Kauf', 'taxmod-stock') . '</th><th scope="col" class="manage-column taxmod-stock-receipt-material" style="width:' . $wide . '">' . esc_html__('Material', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column" style="width:6em">' . esc_html__('Menge', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Lagerort', 'taxmod-stock') . '</th>'
            // His word 2026-11-01: «buttons immer nach rechts».
            . ($booked ? '<th scope="col" class="manage-column" style="width:8em">' . esc_html__('Eingebucht am', 'taxmod-stock') . '</th></tr>'
                : '<th scope="col" class="manage-column" style="width:10em;text-align:right">' . esc_html__('Aktionen', 'taxmod-stock') . '</th></tr>');
    }

    /**
     * What every row of a receipt reads, for all its purchases at once (CD-7) — also across orders on «Wareneingang».
     *
     * @param array<int, array{model: ?int, lock: ?string}> $plan
     * @return array<string, mixed>
     */
    private function receiptLookups(array $plan, bool $assign): array
    {
        $inbound = new \TaxmodStock\WordPress\InboundService($this->stock);

        return [
            'labels'    => $this->stock->labels('purchase'),
            'about'     => $this->purchaseAbout(array_keys($plan)),
            'models'    => $this->stock->recordLabels(array_values(array_filter(array_column($plan, 'model')))),
            'where'     => $this->stock->receiptLocations($plan),
            // D-1360: each row's chooser shows its material's Standard-Lagerorte on top.
            'preferred' => $this->stock->standardPlaces(array_values(array_filter(array_column($plan, 'model')))),
            // His wish 2026-11-14: «wenn es ein Bild zu der Position gibt, dann würde ich das gerne sehen. Vielleicht ganz vorne» —
            // the purchase's picture in front of its name, all of them in one read (CD-7).
            'thumbs'    => ListPage::thumbnails($this->stock->titleImages(array_keys($plan))),
            'closing'   => $this->stock->canCloseWithoutStock(),
            // Stefan 16.11. «keine einstellmöglichkeiten?»: a booked purchase says where its lots lie.
            'lying'     => $this->stock->placesOfPurchases(array_keys(array_filter($plan, static fn (array $row): bool => $row['lock'] === 'received'))),
            'bought'    => $this->stock->table('purchase'),
            'amount'    => $this->stock->structure()->field('purchase_quantity'),
            // Stefan 16.11. «bei versendet sind wieder zwei tabellen … eine bitte nur mit beiden funktionen»: before arrival this
            // one table also carries what the purchase list showed — the prices with their warning (D-1375) and «Bild übernehmen» (D-1264).
            'prices'    => $assign ? $this->stock->fieldWords(array_keys($plan), ['purchase_unit_price', 'purchase_total_price']) : [],
            'inbound'   => $inbound,
            'warned'    => $assign ? $inbound->priceWarnings(array_keys($plan)) : [],
            'takable'   => $assign ? $this->takablePictures(array_keys($plan), $plan) : [],
            // D-1440: where «Einlagern» of a row without a place puts it.
            'arrival'   => $this->stock->receiptInbound(),
        ];
    }

    /**
     * One purchase of a receipt as a row. On «Wareneingang» ($multi, D-1440) the row names its order for the handler and
     * has its own «Einlagern».
     *
     * @param array{model: ?int, lock: ?string} $row
     * @param array<string, mixed>               $look {@see receiptLookups()}
     * @param list<int>                          $pick
     */
    private function receiptRow(int $order, int $purchase, array $row, array $look, bool $assign, array $pick, string $back, bool $multi): string
    {
        $lock     = $row['lock'];
        $model    = $row['model'];
        $models   = $look['models'];
        $quantity = (string) $look['bought']->first($purchase, $look['amount']);
        // His finding 2026-11-18 17:35 «machen wir jetzt button gößen bingo»: one size, one per line, right-aligned.
        // D-1451, his word 17:57 «Icons … für Einlagern, Material, nicht einlagern … 3 Quadrate»: equal squares in one line, the word as tooltip.
        // His finding 18:24 «wieder unterschiedliche Größen»: framed squares of one size, the modeller's row-act container (D-1451).
        $actions  = static fn (string $buttons): string => '<td><span class="taxmod-stock-row-actions taxmod-row-actions" style="display:flex;flex-wrap:wrap;justify-content:flex-end;gap:4px">' . $buttons . '</span></td>';
        // The row's choosers name their form, so they can share their tree with the other rows ({@see StockService::sharedTrees()}).
        $formId   = self::receiptFormId($multi ? 0 : $order);
        $html     = '<tr id="taxmod-stock-purchase-' . esc_attr((string) $purchase) . '"' . ($multi ? ' data-order="' . esc_attr((string) $order) . '"' : '') . '><th scope="row" class="check-column"><input type="checkbox" name="purchases[]" value="' . esc_attr((string) $purchase) . '"'
            . ($lock === null && $model !== null && ! $assign && ! $multi && ($pick === [] || in_array($purchase, $pick, true)) ? ' checked' : '') . ($lock === null || ($lock === 'received' && ($look['booked_stage'] ?? false)) ? '' : ' disabled') . '>'
            . ($multi ? '<input type="hidden" name="order_of[' . esc_attr((string) $purchase) . ']" value="' . esc_attr((string) $order) . '">' : '') . '</th>'
            . ($multi ? '<td>' . ($look['heads'][$order] ?? esc_html('#' . $order)) . '</td>' : '')
            . '<td>' . (($look['thumbs'][$purchase] ?? '') === '' ? '' : '<span style="float:left;margin:0 8px 4px 0">' . $look['thumbs'][$purchase] . '</span>')
            . esc_html($look['labels'][$purchase] ?? '#' . $purchase) . ($lock === null ? '' : ' <em>(' . esc_html(Messages::lockReason($lock)) . ')</em>')
            . ($assign ? InboundPage::purchaseFacts($look['inbound'], $look['bought'], $order, $purchase) . $this->priceLine($purchase, $look['prices'], $look['warned'])
                : (($look['about'][$purchase] ?? '') === '' ? '' : '<br><span class="description">' . esc_html($look['about'][$purchase]) . '</span>')) . '</td>';

        if ($lock !== null) {
            // Concept R3: a lot purchase is told apart first; its parts are then received like any purchase.
            $split = $lock === 'purchase_undetermined'
                // His finding 2026-11-19 20:51 «buttons aufteilen braucht ein button»: a square of the row's size, the word as tooltip (D-1451).
                ? ListPage::iconLink('screenoptions', __('Aufteilen', 'taxmod-stock'), add_query_arg(array_filter(['page' => self::PAGE, 'tab' => 'wareneingang', 'order' => $order, 'split' => $purchase, 'back' => $back]), admin_url('admin.php')))
                : '';
            // D-1058: a purchase closed without stock shows its reason and can be taken back.
            $again = match (true) {
                // His word 2026-11-18 18:41 «der Button zurücknehmen und dafür lässt sich bestimmt ein Icon finden» (D-1451 squares).
                $lock === 'not_stocked' => '<button type="submit" class="button" title="' . esc_attr__('Zurücknehmen', 'taxmod-stock') . '" name="booking" value="einlagern-' . esc_attr((string) $purchase) . '">' . $this->iconOrWord('ICON_UNDO', __('Zurücknehmen', 'taxmod-stock')) . '</button>',
                // A lot purchase that never goes into stock needs no splitting first.
                $lock === 'purchase_undetermined' && $look['closing'] => $this->closeControls($purchase),
                default => '',
            };

            // D-1452, his wish 2026-11-09: once booked, where it went — material, place and day, each linked (as in the order view before).
            if ($lock === 'received' && ($look['went'] ?? null) instanceof \Closure) {
                $cells = ($look['went'])($purchase);

                return $html . '<td>' . $cells['material'] . '</td><td>' . esc_html($quantity) . '</td><td>' . $cells['place'] . '</td><td>' . $cells['date'] . '</td></tr>';
            }

            return $html . '<td>' . ($model === null ? esc_html('—') : $this->materialLink($purchase, $model, '#' . $model . ' · ' . ($models[$model] ?? ''))) . '</td>'
                . '<td>' . esc_html($quantity) . '</td>'
                . '<td>' . ($lock === 'not_stocked' ? esc_html($this->stock->notStockedReason($purchase)) : esc_html(implode(', ', $look['lying'][$purchase] ?? []))) . '</td>'
                . $actions(($model === null ? '' : $this->materialDialog($purchase, $model, (string) ($models[$model] ?? ''))) . $split . ' ' . $again) . '</tr>';
        }

        // D-1440, his word 2026-11-18 11:33: «Und hier müsst ihr dann einen Einlagern geben. Und wenn kein Lagerort angegeben ist,
        // dann wird es in den Wareneingang gelegt.» Without that place set and none proposed, the button says what is missing.
        $proposed = (int) ($look['where'][$purchase] ?? 0);
        // His finding 2026-11-18 17:19: «Einlagern» per row in the order's own view too, not only in the overview; never while assigning («Versendet»).
        $store    = $assign ? '' : ($look['arrival'] > 0 || $proposed > 0
            ? '<button type="submit" class="button button-primary" title="' . esc_attr__('Einlagern', 'taxmod-stock') . '" name="booking" value="positioneinlagern-' . esc_attr((string) $purchase) . '">' . $this->iconOrWord('ICON_STORE', __('Einlagern', 'taxmod-stock')) . '</button> '
            : '<button type="button" class="button" disabled title="' . esc_attr__('Ohne Lagerort braucht «Einlagern» den «Lagerort Wareneingang» in den Einstellungen.', 'taxmod-stock') . '">' . $this->iconOrWord('ICON_STORE', __('Einlagern', 'taxmod-stock')) . '</button> ');

        // The modeller's own record chooser on the purchase's «Modell» (his word 2026-10-28: «eigentlich haben wir doch den tax chooser»).
        return $html . '<td><input type="hidden" name="model[' . esc_attr((string) $purchase) . ']" value="' . esc_attr((string) ($model ?? '')) . '">'
            . $this->recordPicker('thing[' . $purchase . ']', $model, $model === null ? '' : ($models[$model] ?? '#' . $model), $purchase, '', true) . '</td>'
            // His wish 2026-11-05: «Menge 1, geliefert wurden 40» — the quantity is corrected here, not in the purchase.
            . '<td>' . ListPage::field(InputKind::Number, 'quantity[' . (string) $purchase . ']', $quantity, ['min' => '1', 'step' => '1']) . '</td>'
            // The place the server proposed, so the page can tell it from one he chose in the row (D-1361).
            . '<td class="taxmod-stock-receipt-place" data-drawn="' . esc_attr((string) $proposed) . '">' . $this->stock->placeChooser('location[' . $purchase . ']', $proposed, $formId, [], $model === null ? [] : ($look['preferred'][$model] ?? []), false, true) . '</td>'
            . $actions($store . ($model === null ? $this->newThingDialog($purchase, $formId) : $this->materialDialog($purchase, $model, (string) ($models[$model] ?? ''))) . ($look['closing'] ? $this->closeControls($purchase) : '')
                . (isset($look['takable'][$purchase]) ? '<button type="submit" class="button" name="booking" value="bilduebernehmen-' . esc_attr((string) $purchase) . '">' . esc_html__('Bild übernehmen', 'taxmod-stock') . '</button>' : '')) . '</tr>';
    }

    /**
     * Design 2 and D-1030: every place in its tree with what lies there; with `$edit` the structure page, where places
     * are created and hung elsewhere — a box moves with its content.
     */
    private function locationList(bool $edit): string
    {
        $s      = $this->stock->structure();
        $tree   = $this->stock->locationTree();
        $things = $this->stock->things()->all();
        $rows   = LocationStock::of($s, $this->stock->table('lot'), $this->stock->ledger(), $this->stock->places());
        $flags  = $this->stock->reservedPlaces();
        $body   = '';

        foreach ($tree->ordered() as [$place, $depth]) {
            $row   = $rows[$place] ?? ['lots' => 0, 'things' => []];
            $all   = array_sum(array_map(static fn (int $p): int => $rows[$p]['lots'] ?? 0, $tree->withAllBelow($place)));
            $links = array_map(fn (int $thing): string => '<a href="' . esc_url($this->thingUrl($thing, 'bestand')) . '">' . esc_html($things[$thing] ?? '#' . $thing) . '</a>', $row['things']);
            $name  = str_repeat('— ', $depth) . '<strong>' . esc_html((string) (array_reverse(explode(' › ', $tree->pathOf($place)))[0])) . '</strong>'
                . ($tree->isMovable($place) ? ' <span class="description">(' . esc_html__('beweglich', 'taxmod-stock') . ')</span>' : '')
                . $this->reservedMark($place, $flags);
            $project = $tree->projectOf($place);
            $extra   = $edit
                ? $this->stock->placeChooser('parent[' . $place . ']', $tree->parentOf($place), '', [$place]) . ' '
                    . ListPage::iconButton('randomize', __('Umhängen', 'taxmod-stock'), '', 'booking', 'umhaengen-' . $place) . ' '
                    // His wish 2026-11-11: no way out of the Logistik into the modeller — the place's own view has the record editor.
                    . ListPage::iconButton(ListPage::ICON_EDIT, __('Bearbeiten', 'taxmod-stock'), add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place], admin_url('admin.php'))) . ' '
                    . $this->reserveDialog($place, $flags->isFlagged($place), $all)
                : implode(', ', $links);
            $body .= '<tr><th scope="row" class="check-column"></th><td>' . $name . '</td><td>' . esc_html($project ?? '') . '</td>'
                . '<td>' . esc_html((string) $row['lots']) . ($all > $row['lots'] ? ' <span class="description">(' . esc_html(sprintf(
                    /* translators: %d: lots in this place and everything inside it */
                    __('mit Inhalt %d', 'taxmod-stock'),
                    $all
                )) . ')</span>' : '') . '</td><td>' . $extra . '</td><td></td></tr>';
        }

        if (isset($rows[0])) {
            $body .= '<tr><th scope="row" class="check-column"></th><td><em>' . esc_html__('ohne Lagerort', 'taxmod-stock') . '</em></td><td></td><td>' . esc_html((string) $rows[0]['lots']) . '</td><td>'
                . implode(', ', array_map(fn (int $thing): string => '<a href="' . esc_url($this->thingUrl($thing, 'bestand')) . '">' . esc_html($things[$thing] ?? '#' . $thing) . '</a>', $rows[0]['things'])) . '</td><td></td></tr>';
        }

        $table = ListPage::table([[__('Lagerort', 'taxmod-stock'), '22em'], [__('Projekt', 'taxmod-stock'), '9em'], [__('Posten', 'taxmod-stock'), '8em'],
            [$edit ? __('Liegt in', 'taxmod-stock') : __('Was dort liegt', 'taxmod-stock'), '']], $body === '' ? $this->noItems(5) : $body);

        if (! $edit) {
            $structure = add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur'], admin_url('admin.php'));

            // His word 2026-11-14: the bar above the list as on every other list (count, filter, group by).
            $count = sprintf(/* translators: %d: number of places */ _n('%d Lagerort', '%d Lagerorte', count($rows), 'taxmod-stock'), count($rows));

            return '<p>' . esc_html__('Jeder Lagerort mit den Posten, die dort Bestand haben; eine Kiste gehört mit allem darin zu ihrem Projekt.', 'taxmod-stock')
                . ' <a href="' . esc_url($structure) . '">' . esc_html__('Lagerstruktur bearbeiten', 'taxmod-stock') . '</a></p>'
                . ListPage::bulkBar(self::PLACE_FORM, self::ACTION, [], $count, '', false) . $table;
        }

        if (! $s->hasField('location_parent')) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Der Ortsbaum braucht das Feld «Übergeordneter Ort» an den Lagerorten (fields.location_parent in der Lager-Struktur).', 'taxmod-stock') . '</p></div>' . $table;
        }

        $projects = $this->stock->projects();

        return $this->formStart('lagerstruktur')
            // Enter in the name field creates the place: the first submit button of a form is its default, and the rows'
            // buttons (Umhängen, the flag's dialog) come before the one of «Neuer Lagerort».
            . '<button type="submit" name="booking" value="ort" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px"></button>'
            . '<p>' . esc_html__('Orte sind ein Baum: Lager → Bereich → Platz. Kisten und Kartons sind bewegliche Orte; «Umhängen» stellt sie samt Inhalt um.', 'taxmod-stock') . '</p>'
            . $table
            . '<h2>' . esc_html__('Neuer Lagerort', 'taxmod-stock') . '</h2><div style="margin:1em 0">'
            . '<label>' . esc_html__('Name', 'taxmod-stock') . ' ' . ListPage::field(InputKind::Line, 'name', '', ['size' => 20]) . '</label> '
            . '<span>' . esc_html__('liegt in', 'taxmod-stock') . ' ' . $this->stock->placeChooser('new_parent') . ' <span class="description">' . esc_html__('leer: oberste Ebene', 'taxmod-stock') . '</span></span> '
            . '<label>' . ListPage::field(InputKind::Tick, 'movable', '1') . ' ' . esc_html__('beweglich (Kiste, Karton)', 'taxmod-stock') . '</label> '
            . ($s->hasField('location_reserved') ? '<label>' . ListPage::field(InputKind::Tick, 'reserved', '1') . ' ' . esc_html__('reserviert für Produktion', 'taxmod-stock') . '</label> ' : '')
            . ($projects === [] ? '' : '<label>' . esc_html__('Projekt', 'taxmod-stock') . ' ' . SelectMarkup::of('project', $projects, nothingWord: '—') . '</label> ')
            . '<button type="submit" class="button button-primary" name="booking" value="ort">' . esc_html__('Anlegen', 'taxmod-stock') . '</button></div></form>';
    }

    /**
     * D-1090: the places as the modeller's tree on the left, the chosen place on the right — in the structure its data and
     * actions, in «Lagerorte» what lies there and below. A modeller without the tree on the page keeps the table.
     */
    private function locationSplit(bool $edit): string
    {
        $tab   = $edit ? 'lagerstruktur' : 'lagerorte';
        $tree  = $this->stock->locationTree();
        $rows  = LocationStock::of($this->stock->structure(), $this->stock->table('lot'), $this->stock->ledger(), $this->stock->places());
        $place = $this->sideChosen(self::PLACE_TREE, absint($_GET['ort'] ?? 0));
        $place = $this->stock->table('location')->has($place) ? $place : 0;
        $left  = $this->placeTree($tab, $place, $tree, $rows);

        if ($left === '') {
            return $this->locationList($edit);
        }

        $right = match (true) {
            $place === 0 => '<p class="description">' . esc_html__('Links einen Ort wählen. Orte und Posten lassen sich auf einen Ort im Baum ziehen.', 'taxmod-stock') . '</p>'
                . ($edit ? '' : $this->unplacedStock($rows)),
            $edit        => $this->placeDetails($place, $tree, $rows),
            default      => $this->placeStock($place, $tree),
        };

        // D-1092: the tree is the left column's second box, under the navigation; the right side is only the place.
        $newPlace = $edit ? '<label class="button button-small" for="taxmod-stock-new-place-0">' . esc_html__('+ Neuer Lagerort', 'taxmod-stock') . '</label>' : '';
        $top      = $edit ? '<div class="taxmod-stock-places-top" data-taxmod-drop-record="0">' . esc_html__('— oberste Ebene —', 'taxmod-stock') . '</div>' : '';
        // The architecture thread 2026-11-09: the side tree is the modeller's one building block (expand/collapse all, kept state).
        $this->side = ListPage::sideTree(self::PLACE_TREE, __('Lagerorte', 'taxmod-stock'), $top . $left, $newPlace, 'taxmod-stock-places');

        return '<div class="taxmod-stock-split">' . $right . '</div>'
            // The opener of «Neuer Lagerort» stands in the tree's box; its dialog stays here, outside the sticky column.
            . ($edit ? '<div class="taxmod-stock-drop-dialogs">' . $this->newPlaceDialog(0, $tab) . '</div>' : '')
            . $this->dropDialogs($tab, $place, $tree);
    }

    /**
     * His word 2026-11-09: «hier fange ich immer neu an» — the branch named by the request, else the one he left (modeller
     * 0.2.34); an older modeller only knows the request.
     */
    private function sideChosen(string $tree, int $chosen): int
    {
        return ListPage::treeChosen($tree, $chosen);
    }

    /**
     * D-1092: in the narrow column a long name ends in «…» and shows whole on hover; the chosen place is scrolled into view
     * inside the tree's box, not the page. Only for a modeller before `ListPage::sideTree()`, which does the same.
     */
    private function placeTreeScript(): string
    {
        return '<script>(function(){function run(){var box=document.querySelector(".taxmod-stock-places");if(!box){return;}'
            . 'box.querySelectorAll(".taxmod-tree-label").forEach(function(l){var row=l.closest(".taxmod-tree-row");if(row){row.title=row.textContent.trim();}});'
            . 'var on=box.querySelector(".taxmod-chooser-row input[type=radio]:checked"),list=box.querySelector(".taxmod-chooser-tree");if(!on||!list){return;}'
            . 'var row=on.closest(".taxmod-tree-row"),r=row.getBoundingClientRect(),l=list.getBoundingClientRect(),head=box.querySelector(".taxmod-tree-search"),top=l.top+(head?head.offsetHeight:0);'
            . 'if(r.top<top||r.bottom>l.bottom){list.scrollTop+=r.top-top-(l.bottom-top)/3;}}'
            . 'document.addEventListener("taxmod:replaced",run);'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}})();</script>';
    }

    /**
     * D-1090: the tree itself — the modeller's record tree on the page; a click opens a place, places and lots drop on it.
     *
     * @param array<int, array{name: string, lots: int, things: list<int>}> $rows
     */
    private function placeTree(string $tab, int $chosen, LocationTree $tree, array $rows): string
    {
        $s = $this->stock->structure();

        if (! $s->hasField('location_parent')) {
            return '';
        }

        $suffix = [];

        foreach (array_keys($this->stock->places()) as $place) {
            $all = array_sum(array_map(static fn (int $p): int => $rows[$p]['lots'] ?? 0, $tree->withAllBelow($place)));

            if ($all > 0) {
                $suffix[$place] = '(' . $all . ')';
            }
        }

        return (string) apply_filters('taxmod_record_chooser', '', [
            'name'         => 'taxmod_stock_place',
            'node'         => $s->node('location'),
            'parent_field' => $s->field('location_parent'),
            'label_field'  => $this->stock->placeWords(),
            'preselected'  => $chosen > 0 ? $chosen : null,
            'inline'       => true,
            // His report 2026-11-16 «er scrollt nur ganz nach unten»: the modeller puts a page back at the offset of the page
            // before; an anchor goes first (D-788), so a chosen place opens at the top.
            'pick_url'     => add_query_arg(['page' => self::PAGE, 'tab' => $tab, 'ort' => '{id}'], admin_url('admin.php')) . '#wpbody',
            'drag'         => true,
            'suffix'       => $suffix,
            // D-1095: the places' own order among their siblings; with the field a drop above or below a row reorders.
            'rank'         => array_filter(array_map(static fn (int $id): ?int => $tree->orderOf($id), array_combine(array_keys($this->stock->places()), array_keys($this->stock->places()))), static fn (?int $rank): bool => $rank !== null),
            'rank_drop'    => $s->hasField('location_order'),
        ]);
    }

    /**
     * The head of the right side: D-1526 recordHead «← Lagerorte | Lagerort – <Name>», where it lies and its marks as the line
     * beneath (Prüftor 2026-11-20 on his screenshot 20:25).
     */
    private function placeHead(int $place, LocationTree $tree, string $tab): string
    {
        $parent  = $tree->parentOf($place);
        $project = $tree->projectOf($place);
        $marks   = ($tree->isMovable($place) ? ' <span class="description">(' . esc_html__('beweglich', 'taxmod-stock') . ')</span>' : '')
            . $this->reservedMark($place, $this->stock->reservedPlaces());

        $layout  = $tab === 'lagerstruktur';

        return ListPage::recordHead(__('Lagerort', 'taxmod-stock'), (string) (array_reverse(explode(' › ', $tree->pathOf($place)))[0]),
            add_query_arg(['page' => self::PAGE, 'tab' => $tab], admin_url('admin.php')), $layout ? __('Lagerstruktur', 'taxmod-stock') : __('Lagerorte', 'taxmod-stock'),
            $layout ? __('Die Daten und der Aufbau des Lagerorts: bearbeiten, Unterorte anlegen, verschieben, reservieren, Etikett.', 'taxmod-stock')
                : __('Was an diesem Lagerort und darunter liegt, je Posten: umbuchen, ausbuchen, Etikett.', 'taxmod-stock'))
            . '<p>' . ltrim($marks) . ($marks === '' ? '' : ' · ') . esc_html__('Liegt in', 'taxmod-stock') . ': ' . esc_html($parent > 0 ? $tree->pathOf($parent) : __('— oberste Ebene —', 'taxmod-stock'))
            . ($project === null ? '' : ' · ' . esc_html__('Projekt', 'taxmod-stock') . ': ' . esc_html($project))
            // D-1150, his word 2026-11-04: «würde dir auch die id nennen aber die sehe ich nicht im lager layout detail sicht des platzes».
            . ' · ' . esc_html__('Id', 'taxmod-stock') . ': ' . (int) $place . '</p>';
    }

    /**
     * D-1090, structure: the place's data and actions — Verschieben, Neuer Unterort, Reservieren, Bearbeiten — each in the
     * shared dialog; a box shows where it moved (D-1091).
     *
     * @param array<int, array{name: string, lots: int, things: list<int>}> $rows
     */
    private function placeDetails(int $place, LocationTree $tree, array $rows): string
    {
        $back = add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place], admin_url('admin.php'));

        $below = $tree->withAllBelow($place);
        $all   = array_sum(array_map(static fn (int $p): int => $rows[$p]['lots'] ?? 0, $below));
        $flags = $this->stock->reservedPlaces();
        // His word 2026-11-03 (D-1122): «lager struktur unter einstellung gleich die einstellungen/details des platzes zeigt nicht
        // den bestand» — the place's own fields open at once; what lies there belongs to «Lagerorte».
        // «projekt steht aber nicht auf dem etikett» (2026-11-03): the second line names the project before the path.
        // D-1141, his word 2026-11-04: «den übergeordneten ort sollten wir bei beweglichen pläzen nicht andrucken und wenn ein Projekt
        // zugeordnet ist sollten wir Projekt: dann Projekt name» — a box moves, so its parent would be wrong tomorrow.
        $label   = (string) apply_filters('taxmod_label_button', '', ...$this->placeLabel($place, $tree));
        $facts = [
            [__('Beweglich (Kiste, Karton)', 'taxmod-stock'), $tree->isMovable($place) ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')],
            [__('Unterorte', 'taxmod-stock'), (string) (count($below) - 1)],
        ];
        // D-1338: the place's own colour, else its kind's — said which.
        $colours = $this->stock->coloursOf($place);

        if ($colours['colours'] !== []) {
            $facts[] = [__('Farbe', 'taxmod-stock'), implode(', ', $colours['colours']) . ($colours['inherited'] ? ' ' . __('(von der Platzart)', 'taxmod-stock') : '')];
        }

        // D-1337: what «Kurzzeichen erzeugen» would write here and below; a typed short mark stays.
        $proposals = $this->stock->shortProposals($place);

        if (isset($proposals[$place])) {
            $facts[] = [__('Kurzzeichen-Vorschlag', 'taxmod-stock'), $proposals[$place]];
        }
        $table = '';

        foreach ($facts as [$name, $value]) {
            $table .= '<tr><th scope="row" style="width:14em">' . esc_html($name) . '</th><td>' . esc_html($value) . '</td></tr>';
        }

        // D-1308: the place's acts are the editor's toolbar after «Speichern», grouped by what they do.
        $groups = [
            // D-1224, his word 2026-11-07: «zur bestandsansicht wechseln» — what lies here and below, in «Platzbestand».
            ['<a class="button" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'lagerorte', 'ort' => $place], admin_url('admin.php'))) . '">' . esc_html__('Zur Bestandsansicht', 'taxmod-stock') . '</a>'],
            [$this->newPlaceDialog($place, 'lagerstruktur'), ...($this->stock->canCreateSubPlaces($place) ? [$this->subPlacesButton($place)] : []), $this->kindChangeDialog($place, $tree),
                ...($proposals === [] ? [] : [$this->formStart('kurzzeichen-' . $place) . $this->returnFields('lagerstruktur', $place) . '<button type="submit" class="button" title="'
                    . esc_attr(sprintf(/* translators: %d: number of places */ _n('%d Platz ohne Kurzzeichen bekommt seins', '%d Plätze ohne Kurzzeichen bekommen ihrs', count($proposals), 'taxmod-stock'), count($proposals))) . '">' . esc_html__('Kurzzeichen erzeugen', 'taxmod-stock') . '</button></form>']), $this->copyPlaceDialog($place, $tree, count($below) - 1), $this->movePlaceDialog($place, $tree), $this->deletePlaceDialog($place, $tree)],
            [$this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place) . $this->reserveDialog($place, $flags->isFlagged($place), $all) . '</form>'],
            [$label],
        ];
        $form = (string) apply_filters('taxmod_record_editor', '', $place, $back, $groups);
        $html = $this->placeHead($place, $tree, 'lagerstruktur')
            . ($form === '' ? ListPage::toolbar([[self::editorMissing()], ...$groups])
                . '<table class="widefat striped" style="max-width:40em">' . $table . '</table>' : $form)
            . $this->childList($place, $tree, $rows);

        if ($tree->isMovable($place) && $this->stock->relocates()) {
            $moves = $this->stock->relocationsOf($place);
            $where = static fn (int $p): string => $p > 0 ? $tree->pathOf($p) : __('— oberste Ebene —', 'taxmod-stock');
            $html .= '<h3>' . esc_html__('Umlagerungen', 'taxmod-stock') . '</h3>' . ($moves === []
                ? '<p class="description">' . esc_html__('Noch keine.', 'taxmod-stock') . '</p>'
                : '<ul>' . implode('', array_map(static fn (array $m): string => '<li>' . esc_html(ListPage::date(substr($m['date'], 0, 10)) . ': ' . $where($m['from']) . ' → ' . $where($m['to'])) . '</li>', $moves)) . '</ul>');
        }

        return $html;
    }

    /**
     * What a place's label says (D-1032): kind (O fixed, K box), id, its own name, and a second line — «Projekt: …» and, for
     * a fixed place only, where it sits (D-1141: a box moves, so its parent would be wrong tomorrow).
     *
     * @return array{0: string, 1: int, 2: string, 3: string, 4: int, 5: array<string, mixed>}
     */
    /**
     * D-1199: what lies on these places, read once for all of them and kept for the request.
     *
     * @param list<int> $places
     * @return array<int, array{categories: list<string>, materials: list<string>}>
     */
    private function contentsOf(array $places): array
    {
        $missing = array_values(array_diff($places, array_keys($this->contents)));

        if ($missing !== []) {
            $this->contents += $this->stock->placeContents($missing);
        }

        return $this->contents;
    }

    private function placeLabel(int $place, LocationTree $tree, int $instead = 0): array
    {
        $movable = $tree->isMovable($place);
        $project = (string) $tree->projectOf($place);

        // D-1150, his word 2026-11-04: «nee bezeichnung oben» — the Bezeichnung is the title across the top, the short mark leads the
        // lines beside the QR code.
        // D-1152, his word 2026-11-04: «bei beweglichen plätzen 2 sonst 1 vorbelegen» — the fifth item is how many to print.
        // D-1153: the place type's template brings size and layout; its fields beyond code, title and subtitle are named here.
        $s      = $this->stock->structure();
        $type   = $s->hasField('location_place_type') ? (int) $this->stock->table('location')->first($place, $s->field('location_place_type')) : 0;
        $parent = ! $movable && $tree->parentOf($place) > 0 ? $tree->pathOf($tree->parentOf($place)) : '';
        $labels   = $this->stock->labelTemplates();
        $chosen   = $instead;
        $template = $instead > 0 ? $labels->get($instead) : null;
        $copies   = (int) ($template['copies'] ?? ($movable ? 2 : 1));
        $origin   = [];
        // D-1519: the print rule for the place type chooses; the type's own label is no preselection any more (INF-137).
        $rule = $instead > 0 ? null : $this->stock->labelRule('location', $place, $type);
        if ($rule !== null && $labels->get($rule['label']) !== null) {
            $labels   = $labels->preferring($rule['printer']);
            $chosen   = $rule['label'];
            $template = $labels->get($chosen);
            $copies   = $rule['copies'] ?? (int) ($template['copies'] ?? ($movable ? 2 : 1));
            $origin   = self::ruleOrigin($rule);
        }

        return [$movable ? 'K' : 'O', $place, $tree->titleOf($place), implode(' · ', array_filter([
            $tree->shortOf($place),
            $project === '' ? '' : __('Projekt', 'taxmod-stock') . ': ' . $project,
            $parent,
        ])), $copies, $template === null && $instead === 0 ? ['no_rule' => true] : $labels->dialog($chosen, $origin + [
            // D-1160: the modeller reads the fields the layout names from the place itself; these fill what it has no field for.
            'record' => $place,
            'values' => ['bezeichnung' => $tree->titleOf($place), 'kurzzeichen' => $tree->shortOf($place), 'projekt' => $project, 'ort' => $parent],
        ], $this->contentsOf([$place])[$place] + ['words' => [
            'label' => __('Inhalt andrucken', 'taxmod-stock'), '' => __('Nichts', 'taxmod-stock'), 'categories' => __('Kategorien', 'taxmod-stock'),
            'materials' => __('Materialien', 'taxmod-stock'), /* translators: %d: names left out */ 'more' => __('+%d weitere', 'taxmod-stock'),
        ]])];
    }

    /**
     * His wish 2026-11-04: «unter der detail ansicht liste mit kindern und massen funktion bsp alle etiketten drucken /
     * verschieben» — the places right inside this one in the shared table template, ticked rows reach «Etiketten drucken …»
     * and «Verschieben nach …», each in the shared dialog joining the list's bulk form.
     *
     * @param array<int, array{name: string, lots: int, things: list<int>}> $rows
     */
    private function childList(int $place, LocationTree $tree, array $rows): string
    {
        $children = $tree->childrenOf($place);

        if ($children === []) {
            return '';
        }

        $s     = $this->stock->structure();
        $types = $s->hasField('location_place_type') ? $this->stock->placeTypes() : null;
        $table = $this->stock->table('location');
        $flags = $this->stock->reservedPlaces();
        $body  = '';

        $this->contentsOf($children);

        foreach ($children as $child) {
            $below = $tree->withAllBelow($child);
            $type  = $types === null ? 0 : (int) $table->first($child, $s->field('location_place_type'));
            $link  = esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $child], admin_url('admin.php')));
            $body .= ListPage::row(self::CHILD_FORM, (string) $child, [
                '<strong><a href="' . $link . '">' . esc_html((string) (array_reverse(explode(' › ', $tree->pathOf($child)))[0])) . '</a></strong>'
                    . $this->reservedMark($child, $flags),
                esc_html($type > 0 && $types !== null && $types->has($type) ? $types->nameOf($type) : '—'),
                esc_html($tree->isMovable($child) ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')),
                esc_html((string) (count($below) - 1)),
                esc_html((string) array_sum(array_map(static fn (int $p): int => $rows[$p]['lots'] ?? 0, $below))),
            ], ListPage::iconButton('visibility', __('Öffnen', 'taxmod-stock'), html_entity_decode($link)), $this->childDetails($child, $tree));
        }

        $count = sprintf(/* translators: %d: number of places */ _n('%d Unterort', '%d Unterorte', count($children), 'taxmod-stock'), count($children));
        $bar   = str_replace(
            ['name="action" id="' . self::CHILD_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::CHILD_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
                . '<input type="hidden" name="return_tab" value="lagerstruktur"><input type="hidden" name="return_ort" value="' . esc_attr((string) $place) . '"></form>'],
            self::bulkBar(self::CHILD_FORM, $count, '', $this->childLabelDialog($children, $tree) + $this->childMoveDialog($place))
        );

        return '<h3 style="margin-top:1.5em">' . esc_html__('Unterorte', 'taxmod-stock') . '</h3>' . $bar
            . ListPage::table([[__('Bezeichnung', 'taxmod-stock')], [__('Platzart', 'taxmod-stock'), '12em'], [__('Beweglich', 'taxmod-stock'), '7em'],
                [__('Unterorte', 'taxmod-stock'), '7em'], [__('Posten', 'taxmod-stock'), '6em']], $body);
    }

    /**
     * D-1224, his word 2026-11-07: «wo sind denn die massen aktionen und das drucken» — the ticked lots of «Platzbestand»
     * reach «Etiketten drucken …» (their materials' labels) and «Umbuchen nach …» (each lot whole), in the shared bulk bar.
     */
    private function placeLotsBar(int $place, int $count, string $beside = ''): string
    {
        $body  = self::actForm([['label' => __('Nach', 'taxmod-stock'), 'control' => $this->stock->placeChooser('location', 0, self::PLACE_FORM),
            'hint' => __('Bucht jeden angehakten Posten ganz vom Ort, an dem er liegt, an den gewählten Ort um: ein Vorgang «Umbuchung», «Stornieren» bei den Vorgängen nimmt ihn zurück.', 'taxmod-stock')]]);
        $move  = ['taxmod-stock-place-lots-move' => [__('Umbuchen nach …', 'taxmod-stock'), '<div class="alignleft actions">' . ListPage::dialogAction('taxmod-stock-place-lots-move', __('Umbuchen nach …', 'taxmod-stock'), __('Ausgewählte umbuchen', 'taxmod-stock'),
            $body, 'booking', 'postenumbuchen', __('Umbuchen', 'taxmod-stock'), self::PLACE_FORM) . '</div>']];
        $label = $this->materialLabelsAsk(self::PLACE_FORM, __('Ein Etikett je angehaktem Posten: mit den Feldern seines Materials, seiner Charge und dem Code P-…', 'taxmod-stock'));
        $text  = sprintf(/* translators: %d: number of lots */ _n('%d Posten', '%d Posten', $count, 'taxmod-stock'), $count);

        return str_replace(
            ['name="action" id="' . self::PLACE_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::PLACE_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
                . '<input type="hidden" name="return_tab" value="lagerorte"><input type="hidden" name="return_ort" value="' . esc_attr((string) $place) . '"></form>'],
            self::bulkBar(self::PLACE_FORM, $text, $beside, $label + $move)
        );
    }

    /** A child's row details: what its label would say, so the bulk print holds no surprise. */
    private function childDetails(int $child, LocationTree $tree): string
    {
        [$prefix, $id, $title, $subtitle] = $this->placeLabel($child, $tree);

        return '<p class="description" style="margin:.5em 0 0">' . esc_html__('Etikett', 'taxmod-stock') . ': '
            . esc_html($prefix . '-' . $id . ' · ' . $title . ($subtitle === '' ? '' : ' · ' . $subtitle)) . '</p>';
    }

    /**
     * «Etiketten drucken …» for the ticked Unterorte: the label, then the print service or one PDF with a page per label.
     * D-1380, his report 2026-11-15 «62x20 wird nicht angeboten … das sollte hier auch angezeigt werden»: the label templates
     * come first and the one the print rules choose for the places' kinds is chosen; the count shows the rules' number (INF-137).
     *
     * @param list<int> $children
     * @return array<string, array{0: string, 1: string}>
     */
    private function childLabelDialog(array $children, LocationTree $tree): array
    {
        $s         = $this->stock->structure();
        $templates = $s->hasNode('label_template') ? $this->stock->labelTemplates() : null;
        $types     = $s->hasField('location_place_type') ? $this->stock->placeTypes() : null;
        $table     = $this->stock->table('location');
        $used      = [];
        $copies    = [];
        $rules     = [];

        foreach ($children as $child) {
            $type = $types === null ? 0 : (int) $table->first($child, $s->field('location_place_type'));
            if ($templates !== null && ! array_key_exists($type, $rules)) {
                $rules[$type] = $this->stock->labelRule('location', $type);
            }
            $rule  = $rules[$type] ?? null;
            $id    = $rule !== null && $templates?->get($rule['label']) !== null ? $rule['label'] : 0;
            $count = $id > 0 && $rule['copies'] !== null ? $rule['copies'] : (int) ($id > 0 ? ($templates->get($id)['copies'] ?? 0) : 0);
            $used[$id] = ($used[$id] ?? 0) + 1;
            $copies[$count > 0 ? $count : ($tree->isMovable($child) ? 2 : 1)] = true;
        }

        arsort($used);
        $chosen  = (int) array_key_first($used);
        $options = '';

        foreach ($templates === null ? [] : $templates->all() as $id => $template) {
            $options .= '<option value="' . esc_attr('vorlage-' . $id) . '"' . selected($id, $chosen, false) . '>'
                . esc_html($template['name'] . ' · ' . $template['format']['w'] . ' × ' . $template['format']['h'] . ' mm') . '</option>';
        }

        // D-1439: the fixed formats live in the labels module only; it names them.
        foreach ((array) apply_filters('taxmod_label_formats', []) as $key => $name) {
            $options .= '<option value="' . esc_attr((string) $key) . '"' . selected($chosen === 0 && $key === 'holder62', true, false) . '>'
                . esc_html((string) $name) . '</option>';
        }

        // One number for all when the kinds agree; otherwise empty, and each place prints what its kind holds.
        $same = count($copies) === 1 ? (int) array_key_first($copies) : null;

        $service = PrintService::isSetUp();
        $form    = ' form="' . esc_attr(self::CHILD_FORM) . '"';
        $body    = '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Format', 'taxmod-stock') . '</span>'
            . '<select name="format"' . $form . '>' . $options . '</select></label>'
            // D-1152: empty means each place's own number — 2 for a box, 1 otherwise; a number holds for every ticked place.
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Anzahl je Ort', 'taxmod-stock') . '</span>'
            . self::copiesInput(self::CHILD_FORM, $same === null ? __('leer: je Ort die Anzahl seiner Druckregel', 'taxmod-stock') : __('wie in der Druckregel', 'taxmod-stock'), $same) . '</label>'
            . '<span class="description" style="display:block">' . esc_html__('Ein Etikett je angehaktem Ort, beschriftet wie sein eigenes.', 'taxmod-stock')
            . ($service ? '' : ' ' . esc_html__('Kein Druckdienst eingerichtet: die Etiketten öffnen sich als PDF.', 'taxmod-stock')) . '</span>';
        $foot    = ($service ? '<button type="submit" class="button button-primary" name="booking" value="etiketten-service"' . $form . '>' . esc_html__('Drucken', 'taxmod-stock') . '</button>' : '')
            . '<button type="submit" class="button' . ($service ? '' : ' button-primary') . '" name="booking" value="etiketten-pdf" formtarget="_blank"' . $form . '>' . esc_html__('Als PDF öffnen', 'taxmod-stock') . '</button>';

        return ['taxmod-stock-children-labels' => [__('Etiketten drucken …', 'taxmod-stock'), '<div class="alignleft actions">' . DialogMarkup::of(
            'taxmod-stock-children-labels',
            esc_html__('Etiketten drucken …', 'taxmod-stock'),
            esc_html__('Etiketten drucken', 'taxmod-stock'),
            '<span class="taxmod-dialog-body" style="display:block;padding:.75em 1em;white-space:normal;text-align:left">' . $body . '</span>',
            $foot,
            'button taxmod-dialog-open',
            '',
            __('Abbrechen', 'taxmod-stock')
        ) . '</div>']];
    }

    /** «Verschieben nach …» for the ticked Unterorte: the target in a list of every place outside this one's branch. */
    /** @return array<string, array{0: string, 1: string}> */
    private function childMoveDialog(int $place): array
    {
        $tree   = $this->stock->locationTree();
        $below  = array_values(array_filter(array_diff($tree->withAllBelow($place), [$place]), static fn (int $p): bool => $tree->parentOf($p) === $place));
        $body   = '<div>' . esc_html__('Nach', 'taxmod-stock') . '<br>' . $this->stock->placeChooser('children_parent', $place, self::CHILD_FORM, $below)
            . ' <span class="description">' . esc_html__('leer: oberste Ebene', 'taxmod-stock') . '</span></div>'
            . '<p class="description">' . esc_html($this->stock->relocates()
                ? __('Jeder gewählte Ort zieht mit allem, was darin liegt; eine bewegliche Kiste als Vorgang «Umlagerung».', 'taxmod-stock')
                : __('Jeder gewählte Ort zieht mit allem, was darin liegt.', 'taxmod-stock')) . '</p>';

        return ['taxmod-stock-children-move' => [__('Verschieben nach …', 'taxmod-stock'), '<div class="alignleft actions">' . ListPage::dialogAction('taxmod-stock-children-move', __('Verschieben nach …', 'taxmod-stock'), __('Ausgewählte verschieben', 'taxmod-stock'),
            $body, 'booking', 'orteverschieben', __('Verschieben', 'taxmod-stock'), self::CHILD_FORM) . '</div>']];
    }

    /**
     * The labels of the ticked places: queued for the print service, or one PDF with a page each. Ends the request.
     *
     * @param list<int> $places
     */
    private function printPlaceLabels(array $places, int $back, bool $toService): void
    {
        $url    = add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur'] + ($back > 0 ? ['ort' => $back] : []), admin_url('admin.php'));
        $picked = sanitize_key((string) ($_POST['format'] ?? ''));
        // D-1380: what is chosen in the dialog holds for every ticked place — a template or a shipped format.
        $use    = str_starts_with($picked, 'vorlage-') && $this->stock->labelTemplates()->has((int) substr($picked, 8)) ? (int) substr($picked, 8) : 0;
        $format = array_key_exists($picked, (array) apply_filters('taxmod_label_formats', [])) ? $picked : '';
        $tree   = $this->stock->locationTree();
        $table  = $this->stock->table('location');
        $labels = [];
        $asked  = absint($_POST['copies'] ?? 0);
        // D-1153, INF-137: a place whose print rule names a label prints from it; the chosen format holds for the rest.
        $this->contentsOf(array_values(array_filter($places, [$table, 'has'])));

        foreach ($places as $place) {
            if ($table->has($place)) {
                [$prefix, $id, $title, $subtitle, $copies, $template] = $this->placeLabel($place, $tree, $use);
                $label = ['code' => LabelPrefix::from($prefix)->code($id), 'title' => $title, 'subtitle' => $subtitle,
                    // D-1162: the copies travel together, as one job with that many pages.
                    'copies' => min(50, $asked > 0 ? $asked : $copies)];
                // D-1380, his report 2026-11-15 «trotz andre wahl 62x20»: a shipped format chosen in the dialog wins over the kind's template.
                $labels[] = ($use > 0 || $format === '') && $template !== [] ? $label + ['template' => $template] : $label + ['format' => $format];
            }
        }

        $this->printLabels($labels, $toService, $url, 'etiketten');
    }

    /**
     * D-1123, Einstellungen › Lagerstruktur › Platzarten: the kinds of place in a list, one opened in the modeller's record
     * editor; «+ Neue Platzart» asks only for the name.
     */
    private function placeTypesPage(int $chosen): string
    {
        $s     = $this->stock->structure();
        $types = $this->stock->placeTypes();
        $html  = '<h2>' . esc_html__('Platzarten', 'taxmod-stock') . '</h2>';

        if (! $s->hasNode('location_type')) {
            return $html . '<p class="description">' . esc_html__('Das Modell kennt noch keine Platzarten.', 'taxmod-stock') . '</p>';
        }

        $list = add_query_arg(['page' => self::PAGE, 'tab' => 'platzarten'], admin_url('admin.php'));

        if ($chosen > 0 && $types->has($chosen)) {
            $back = add_query_arg('typ', $chosen, $list);
            // D-1308: «Kopieren» in the toolbar; D-1521: the way back stands before the title in the one head.
            $copy = [[$this->copyTypeDialog($chosen, $types->nameOf($chosen))]];
            $form = (string) apply_filters('taxmod_record_editor', '', $chosen, $back, $copy);

            // D7, his word «Kopf in einer Zeile»: «Platzarten – Name».
            return ListPage::recordHead(__('Platzart', 'taxmod-stock'), $types->nameOf($chosen), $list, __('Alle Platzarten', 'taxmod-stock'))
                . ($form === '' ? ListPage::toolbar($copy) . self::editorMissing() : $form);
        }

        $places = $this->stock->table('location');
        $used   = [];

        if ($s->hasField('location_place_type')) {
            foreach ($places->ids() as $place) {
                $type = (int) $places->first($place, $s->field('location_place_type'));
                $used[$type] = ($used[$type] ?? 0) + 1;
            }
        }

        $body   = '';
        // D6: the place types' pictures as on every list, read in one go (CD-7).
        $images = ListPage::showsImages();
        $thumbs = $images ? ListPage::thumbnails($this->stock->titleImages(array_keys($types->names()))) : [];

        // D1: a type's parts stand indented below it, as the tree they make.
        foreach ($types->asTree() as ['type' => $type, 'depth' => $depth]) {
            $name  = $types->nameOf($type);
            $parts = implode(', ', array_map(static fn (array $part): string => $part[1] . ' × ' . $types->nameOf($part[0]), $types->partsOf($type)));
            $link  = esc_url(add_query_arg('typ', $type, $list));
            $body .= '<tr><th scope="row" class="check-column"></th>'
                . ($images ? '<td>' . (($thumbs[$type] ?? '') === '' ? '' : '<a href="' . $link . '">' . $thumbs[$type] . '</a>') . '</td>' : '')
                . '<td' . ($depth > 0 ? ' style="padding-left:' . esc_attr((string) (8 + 20 * $depth)) . 'px"' : '') . '>' . ($depth > 0 ? '└ ' : '') . '<a href="' . $link . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td>' . esc_html($types->isMovable($type) ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')) . '</td>'
                . '<td>' . esc_html($parts === '' ? '—' : $parts) . '</td>'
                . '<td>' . esc_html((string) ($used[$type] ?? 0)) . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $this->copyTypeDialog($type, $name) . ' <a class="button" href="' . esc_url(add_query_arg('typ', $type, $list)) . '">' . esc_html__('Bearbeiten', 'taxmod-stock') . '</a></td></tr>';
        }

        $dialog = $this->formStart('platzart') . ListPage::dialogAction(
                'taxmod-stock-new-place-type',
                __('+ Neue Platzart', 'taxmod-stock'),
                __('Neue Platzart', 'taxmod-stock'),
                '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['required' => true, 'size' => 40]) . '</label>',
                'booking',
                'platzart',
                __('Anlegen', 'taxmod-stock')
            ) . '</form>';

        return $html . ListPage::toolbar([[$dialog]])
            . ListPage::table([...($images ? [[__('Bild', 'taxmod-stock'), '52px']] : []), [__('Platzart', 'taxmod-stock'), ''], [__('Beweglich', 'taxmod-stock'), '7em'], [__('Teile', 'taxmod-stock'), ''], [__('Plätze', 'taxmod-stock'), '5em']], $body === '' ? $this->noItems($images ? 6 : 5) : $body);
    }

    /** D-1130: «Kopieren» of a kind of place — its name proposed with «(Kopie)», in the shared dialog. */
    private function copyTypeDialog(int $type, string $name): string
    {
        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br>'
            . ListPage::field(InputKind::Line, 'name', sprintf(/* translators: %s: the name of the original kind of place */ __('%s (Kopie)', 'taxmod-stock'), $name), ['required' => true, 'size' => 40]) . '</label>'
            . '<p class="description">' . esc_html__('Die Kopie trägt alle Felder und Teile dieser Platzart. Plätze dieser Art bleiben bei der alten.', 'taxmod-stock') . '</p>';

        return $this->formStart('platzarten')
            . ListPage::dialogAction('taxmod-stock-copy-type-' . $type, __('Kopieren', 'taxmod-stock'), __('Platzart kopieren', 'taxmod-stock'), $body, 'booking', 'artkopieren-' . $type, __('Kopieren', 'taxmod-stock'), '', $this->copyIcon())
            . '</form>';
    }

    /**
     * D-1090, «Lagerorte»: what lies at the place and below it, one row per lot and place; a row can be dragged onto a place
     * in the tree (an Umbuchung, D-1039).
     */
    private function placeStock(int $place, LocationTree $tree): string
    {
        $s      = $this->stock->structure();
        $ledger = $this->stock->ledger();
        $lots   = $this->stock->table('lot');
        $things = $this->stock->things()->all();
        $places = $this->stock->places();
        $flags  = $this->stock->reservedPlaces();
        $byPlace = [];

        foreach ($ledger->stockByPlace() as $lot => $at) {
            foreach ($at as $where => $quantity) {
                if ($quantity > 0.0) {
                    $byPlace[$where][$lot] = $quantity;
                }
            }
        }

        $body  = '';
        $count = 0;
        $depth = count($tree->lineOf($place));
        $charges = $this->charges(array_values(array_unique(array_merge([], ...array_map(static fn (int $where): array => array_keys($byPlace[$where] ?? []), $tree->withAllBelow($place))))));

        $rowThings = array_map(static fn (int $lot): int => (int) $lots->first($lot, $s->field('lot_thing')), array_merge([], ...array_map(static fn (int $where): array => array_keys($byPlace[$where] ?? []), $tree->withAllBelow($place))));
        $this->prefetchRowFacts($rowThings);
        // D-1504: the unit each row's quantity is in, for the Umbuchen dialog a drop opens — shown only, never read back.
        $rowUnits = $this->stock->unitWords(array_values(array_unique($rowThings)));

        foreach ($tree->withAllBelow($place) as $where) {
            foreach ($byPlace[$where] ?? [] as $lot => $quantity) {
                $thing = (int) $lots->first($lot, $s->field('lot_thing'));
                $inner = $where === $place ? '' : implode(' › ', array_slice(explode(' › ', $tree->pathOf($where)), $depth));
                $count++;
                $body .= '<tr draggable="true" data-taxmod-stock-lot="' . esc_attr(implode(':', [$lot, $where, $this->plain($quantity), $thing])) . '" data-taxmod-stock-unit="' . esc_attr($rowUnits[$thing] ?? '') . '" style="cursor:grab">'
                    . '<th scope="row" class="check-column">' . ListPage::field(InputKind::Tick, 'selected[]', $lot . ':' . $where . ':' . $thing, ['form' => self::PLACE_FORM, 'label' => __('Auswählen', 'taxmod-stock')]) . '</th>'
                    // His question 2026-11-08: «müsste dann nicht eigentlich der Bestand angezeigt werden, anstatt das Material?» —
                    // the row is a lot, so the lot stands first and leads to its own line in the material's Bestand.
                    . '<td><a href="' . esc_url($this->lotUrl($thing, $lot)) . '">#' . esc_html((string) $lot) . '</a></td>'
                    . '<td><a href="' . esc_url($this->lotUrl($thing, $lot)) . '">' . esc_html($things[$thing] ?? '#' . $thing) . '</a></td>'
                    . '<td>' . esc_html($inner) . $this->reservedMark($where, $flags) . '</td>'
                    . '<td>' . esc_html($this->number($quantity)) . '</td>'
                    . '<td>' . ListPage::dateCell(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10)) . '</td>'
                    . '<td style="text-align:right;white-space:nowrap">' . $this->transferDialog($thing, $lot, $where, $quantity, $places, $place)
                    . ' ' . $this->bookOutDialog($thing, $lot, $where, $quantity)
                    // His word 2026-11-08: «Massenfunktion ist da, aber das Drucken pro Posten».
                    . ' ' . $this->lotLabel($thing, $lot, (string) ($things[$thing] ?? ''), $charges[$lot] ?? '') . '</td></tr>';
            }
        }

        $structure = add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place], admin_url('admin.php'));

        // His wish 2026-11-11: «vom Platzbestand … nicht wieder zurück zur Layout-Sicht» — the counterpart of
        // «Zur Bestandsansicht» (D-1224), first in the toolbar (D-1308).
        // D-1526: «Zur Layout-Sicht» stands in the one bar, not in a row of its own.
        return $this->placeHead($place, $tree, 'lagerorte')
            // His word 2026-11-14: «in Lagerplatzbestand … sieht irgendwie die Leiste oben noch anders aus als in anderen» — the
            // count stands in the list's bar like everywhere, and the bar stands also when nothing lies here.
            . $this->placeLotsBar($place, $count, '<a class="button" href="' . esc_url($structure) . '">' . esc_html__('Zur Layout-Sicht', 'taxmod-stock') . '</a>')
            . ListPage::table([[__('Posten', 'taxmod-stock'), '6em'], [__('Material', 'taxmod-stock'), ''], [__('Unterort', 'taxmod-stock'), '12em'], [__('Menge', 'taxmod-stock'), '6em'], [__('Buchungsdatum', 'taxmod-stock'), '8em']], $body === '' ? $this->noItems(5) : $body);
    }

    /**
     * What lies at no known place, so nothing in stock goes missing from the overview.
     *
     * @param array<int, array{name: string, lots: int, things: list<int>}> $rows
     */
    private function unplacedStock(array $rows): string
    {
        if (! isset($rows[0])) {
            return '';
        }

        $things = $this->stock->things()->all();

        return '<h3>' . esc_html__('ohne Lagerort', 'taxmod-stock') . '</h3><p>'
            . implode(', ', array_map(fn (int $thing): string => '<a href="' . esc_url($this->thingUrl($thing, 'bestand')) . '">' . esc_html($things[$thing] ?? '#' . $thing) . '</a>', $rows[0]['things'])) . '</p>';
    }

    /** Where a form of the split page goes back to: the same tab with the same place open. */
    private function returnFields(string $tab, int $place): string
    {
        return '<input type="hidden" name="return_tab" value="' . esc_attr($tab) . '"><input type="hidden" name="return_ort" value="' . esc_attr((string) $place) . '">';
    }

    /** D-1090: «Neuer Lagerort» (on top) or «Neuer Unterort» of a place, in the shared dialog. */
    private function newPlaceDialog(int $parent, string $tab): string
    {
        $s        = $this->stock->structure();
        $projects = $this->stock->projects();
        $types    = $this->stock->placeTypes()->names();
        $body     = '<input type="hidden" name="new_parent" value="' . esc_attr((string) $parent) . '">'
            . '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['required' => true, 'size' => 40]) . '</label>'
            // D-1123: a kind decides whether the place moves and brings its parts along (Alex: nine drawers).
            . ($types === [] ? '' : '<div style="margin-top:.5em">' . esc_html__('Platzart', 'taxmod-stock') . '<br>' . $this->recordChoice('place_type', 'location_type') . '</div>')
            . '<label style="display:block;margin-top:.5em">' . ListPage::field(InputKind::Tick, 'movable', '1') . ' ' . esc_html($types === [] ? __('beweglich (Kiste, Karton)', 'taxmod-stock') : __('beweglich (Kiste, Karton) — ohne Platzart', 'taxmod-stock')) . '</label>'
            . ($s->hasField('location_reserved') ? '<label style="display:block">' . ListPage::field(InputKind::Tick, 'reserved', '1') . ' ' . esc_html__('reserviert für Produktion', 'taxmod-stock') . '</label>' : '')
            . ($projects === [] ? '' : '<div style="margin-top:.5em">' . esc_html__('Projekt', 'taxmod-stock') . '<br>' . $this->recordChoice('project', 'project') . '</div>');

        return $this->formStart('ort') . $this->returnFields($tab, $parent)
            . ListPage::dialogAction(
                'taxmod-stock-new-place-' . $parent,
                $parent > 0 ? __('Neuer Unterort', 'taxmod-stock') : __('+ Neuer Lagerort', 'taxmod-stock'),
                $parent > 0 ? sprintf(/* translators: %s: the place, by its path */ __('Neuer Unterort in %s', 'taxmod-stock'), $this->stock->locationTree()->pathOf($parent)) : __('Neuer Lagerort', 'taxmod-stock'),
                $body,
                'booking',
                'ort',
                __('Anlegen', 'taxmod-stock')
            ) . '</form>';
    }

    /**
     * D-1093, his word «löschen mit vorsicht dann muss bestand im ast hochwandern» (again 2026-11-09: «wenn wir den löschen muss
     * der gesamte bestand in den vater wechseln»): the bin of a place, its dialog saying what goes where first; refused ones
     * show it greyed with the reason.
     */
    private function deletePlaceDialog(int $place, LocationTree $tree): string
    {
        $plan = $this->stock->placeDeletion($place);
        $icon = defined(ListPage::class . '::ICON_DELETE') ? ListPage::ICON_DELETE : 'trash';

        if ($plan['refused'] !== '') {
            $why = sprintf(/* translators: %s: the reason */ __('Nicht löschbar: %s', 'taxmod-stock'), $plan['refused']);

            return '<button type="button" class="button" disabled>' . ListPage::icon($icon, $why) . '</button>';
        }

        $to   = $plan['parent'] > 0 ? $tree->pathOf($plan['parent']) : __('— oberste Ebene —', 'taxmod-stock');
        $body = '<p>' . esc_html(sprintf(/* translators: %s: the place, by its path */ __('«%s» löschen?', 'taxmod-stock'), $tree->pathOf($place))) . '</p>';

        if ($plan['children'] === []) {
            $body .= $this->deletionMoves($plan, $to);
        } else {
            // His word 2026-11-09 «unterort in den vater verschieben der andere ist unterot mit löschen»: both ways, each saying first what it does.
            $all   = $this->stock->placeDeletion($place, true);
            $body .= ListPage::field(InputKind::Choice, 'below', '0', ['choices' => ['0' => __('Unterorte in den übergeordneten Ort hängen', 'taxmod-stock')], 'lines' => true])
                . $this->deletionMoves($plan, $to)
                . ListPage::field(InputKind::Choice, 'below', '', ['choices' => ['1' => __('Unterorte mitlöschen', 'taxmod-stock')], 'locked' => $all['refused'] === '' ? [] : ['1'], 'lines' => true])
                . ($all['refused'] === ''
                    ? $this->deletionMoves($all, $to)
                    : '<p class="description">' . esc_html(sprintf(/* translators: %s: the reason */ __('Nicht löschbar: %s', 'taxmod-stock'), $all['refused'])) . '</p>');
        }

        $body .= '<p class="description">' . esc_html__('Alles in einem Schritt; rückgängig über die letzten Änderungen, die Umbuchung ist zusätzlich stornierbar.', 'taxmod-stock') . '</p>';

        return $this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place)
            . ListPage::dialogAction('taxmod-stock-delete-place-' . $place, __('Löschen', 'taxmod-stock'), __('Lagerort löschen', 'taxmod-stock'), $body, 'booking', 'ortloeschen-' . $place, __('Löschen', 'taxmod-stock'), '', $icon)
            . '</form>';
    }

    /**
     * D-1093: what one way of deleting a place does, as a list.
     *
     * @param array{parent: int, gone: list<int>, lots: list<\TaxmodStock\Core\TransferRow>, children: list<int>, lager: list<int>, groups: list<int>, refused: string} $plan
     */
    private function deletionMoves(array $plan, string $to): string
    {
        $below = count($plan['gone']) - 1;
        $moves = array_filter([
            /* translators: 1: count, 2: the parent place */
            count($plan['lots']) > 0 ? sprintf(_n('%1$d Posten wird nach %2$s umgebucht.', '%1$d Posten werden nach %2$s umgebucht.', count($plan['lots']), 'taxmod-stock'), count($plan['lots']), $to) : '',
            /* translators: 1: count, 2: the parent place */
            count($plan['children']) > 0 ? sprintf(_n('%1$d Unterort hängt danach an %2$s.', '%1$d Unterorte hängen danach an %2$s.', count($plan['children']), 'taxmod-stock'), count($plan['children']), $to) : '',
            /* translators: %d: count */
            $below > 0 ? sprintf(_n('%d Unterort wird mitgelöscht.', '%d Unterorte werden mitgelöscht.', $below, 'taxmod-stock'), $below) : '',
            /* translators: 1: count, 2: the parent place */
            count($plan['lager']) + count($plan['groups']) > 0 ? sprintf(__('%1$d Materialien und Gruppen nennen danach %2$s als Standard-Lagerort.', 'taxmod-stock'), count($plan['lager']) + count($plan['groups']), $to) : '',
        ]);

        return $moves === [] ? '<p>' . esc_html__('Der Ort ist leer.', 'taxmod-stock') . '</p>' : '<ul style="list-style:disc;margin-left:1.5em">' . implode('', array_map(static fn (string $m): string => '<li>' . esc_html($m) . '</li>', $moves)) . '</ul>';
    }

    /** D-1342: the red ⊘ of a blocked lot, its reason as tooltip. */
    public static function blockedMark(): string
    {
        $why = __('Gesperrt: muss noch getestet werden', 'taxmod-stock');

        return ' <span class="taxmod-stock-blocked" style="color:#d63638;font-weight:700" title="' . esc_attr($why) . '" aria-label="' . esc_attr($why) . '">⊘</span>';
    }

    /** His report 2026-11-09 «speichern knopf … immer noch text nicht icon»: the modeller's one save button, the diskette, the word its tooltip. */
    public static function saveButton(string $word = ''): string
    {
        $word = $word === '' ? __('Speichern', 'taxmod-stock') : $word;

        return \Taxmod\Core\Renderer\ControlMarkup::button(\Taxmod\Core\Renderer\Control::saving('submit', '1', $word, $word));
    }

    /** D-1273, his wish «icon für kopieren»: every «Kopieren» is the modeller's copy icon, the word its tooltip (modeller 0.2.49). */
    private function copyIcon(): string
    {
        return defined(ListPage::class . '::ICON_COPY') ? ListPage::ICON_COPY : '';
    }

    /** D-1130: «Kopieren» of a place — its name proposed with «(Kopie)», the places inside it on request. */
    /** D2 (D-1320): a place of a type with parts, still empty, gets the places its type brings along. */
    private function subPlacesButton(int $place): string
    {
        return $this->formStart('unterplaetze-' . $place) . $this->returnFields('lagerstruktur', $place)
            . '<button type="submit" class="button">' . esc_html__('Unterplätze anlegen', 'taxmod-stock') . '</button></form>';
    }

    /** D-1315: where the claimed field «Platzart» leads — the place's page with «Platzart ändern …» open. */
    public static function kindChangeUrl(int $place): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place, 'artwechsel' => 1], admin_url('admin.php'));
    }

    /**
     * D-1315 (Gesamtkonzept D3): «Platzart ändern …» — the new kind, and for a place with places inside where their stock goes;
     * no way is preselected. Moves only as an «Umbuchung» (D-1233).
     */
    private function kindChangeDialog(int $place, LocationTree $tree): string
    {
        $types = $this->stock->placeTypes();
        $now   = $this->stock->kindOf($place);
        $plan  = $this->stock->placeKindChange($place, $now);

        if ($types->names() === []) {
            return '';
        }

        if ($plan['refused'] !== '') {
            return '<button type="button" class="button" disabled title="' . esc_attr($plan['refused']) . '">' . esc_html__('Platzart ändern …', 'taxmod-stock') . '</button>';
        }

        $options = [];

        foreach ($types->names() as $type => $name) {
            $count    = count($types->brought($type));
            /* translators: 1: kind of place, 2: number of places it brings along */
            $options[$type] = sprintf(_n('%1$s (%2$d Unterplatz)', '%1$s (%2$d Unterplätze)', $count, 'taxmod-stock'), $name, $count);
        }

        $body = '<label style="display:block">' . esc_html__('Neue Platzart', 'taxmod-stock') . '<br>' . SelectMarkup::of('place_type', $options, null, true, '', ['required' => 'required', 'style' => 'width:100%'], true, __('— wählen —', 'taxmod-stock'), [$now]) . '</label>';

        if ($plan['ways'] === []) {
            $body .= '<p class="description">' . esc_html__('Der Platz hat keine Unterplätze; die neue Art bringt ihre mit.', 'taxmod-stock') . '</p>';
        } else {
            /* translators: %d: number of places inside so far */
            $body .= '<p>' . esc_html(sprintf(_n('Bisher %d Unterplatz. Wohin geht, was darin liegt?', 'Bisher %d Unterplätze. Wohin geht, was darin liegt?', $plan['old'], 'taxmod-stock'), $plan['old'])) . '</p>';
            $ways  = [
                \TaxmodStock\Core\PlaceKindChange::WAY_PARENT => __('Alles auf den Platz selbst', 'taxmod-stock'),
                \TaxmodStock\Core\PlaceKindChange::WAY_FIT    => __('Der Reihe nach: gleiche Anzahl 1:1, sonst so viel wie passt, der Rest auf den Platz selbst', 'taxmod-stock'),
                \TaxmodStock\Core\PlaceKindChange::WAY_SPREAD => __('Auf die neuen Unterplätze verteilen (reihum)', 'taxmod-stock'),
            ];

            $body .= ListPage::field(InputKind::Choice, 'way', '', ['choices' => array_combine($plan['ways'], array_map(static fn (string $way): string => $ways[$way], $plan['ways'])), 'required' => true, 'lines' => true]);

            $body .= '<p class="description">' . esc_html__('Gebucht wird als Umbuchung; Kisten darin wandern mit. Die alten Unterplätze werden entfernt.', 'taxmod-stock') . '</p>';
        }

        $switch = 'taxmod-stock-kind-' . $place;
        $html   = $this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place)
            . ListPage::dialogAction($switch, __('Platzart ändern …', 'taxmod-stock'), sprintf(/* translators: %s: the place, by its path */ __('Platzart ändern: %s', 'taxmod-stock'), $tree->pathOf($place)), $body, 'booking', 'artwechsel-' . $place, __('Ändern', 'taxmod-stock'))
            . '</form>';

        // The claimed field's button (D-1334) lands here with the dialog open.
        return absint($_GET['artwechsel'] ?? 0) === 1 ? (string) preg_replace('/(id="' . preg_quote($switch, '/') . '")/', '$1 checked', $html, 1) : $html;
    }

    private function copyPlaceDialog(int $place, LocationTree $tree, int $inside): string
    {
        $name = sprintf(/* translators: %s: the name of the original place */ __('%s (Kopie)', 'taxmod-stock'), (string) (array_reverse(explode(' › ', $tree->pathOf($place)))[0]));
        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br>' . ListPage::field(InputKind::Line, 'name', $name, ['required' => true, 'size' => 40]) . '</label>'
            . ($inside > 0 ? '<label style="display:block;margin-top:.5em">' . ListPage::field(InputKind::Tick, 'below', '1') . ' '
                . esc_html(sprintf(/* translators: %d: number of places inside */ _n('Unterort mitkopieren (%d)', 'Unterorte mitkopieren (%d)', $inside, 'taxmod-stock'), $inside)) . '</label>' : '')
            . '<p class="description">' . esc_html__('Die Kopie steht neben dem Original, mit denselben Feldern. Was dort liegt, wird nicht kopiert.', 'taxmod-stock') . '</p>';

        return $this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place)
            . ListPage::dialogAction('taxmod-stock-copy-place-' . $place, __('Kopieren', 'taxmod-stock'), __('Lagerort kopieren', 'taxmod-stock'), $body, 'booking', 'ortkopieren-' . $place, __('Kopieren', 'taxmod-stock'), '', $this->copyIcon())
            . '</form>';
    }

    /**
     * The modeller's record chooser for one value — the same everywhere (his rule 2026-11-03: «tree chooser bzw wenn es nicht
     * hierarchich ist selct feld … beides mit suchfield genormt über das ganze projekt»); 
     *
     */
    private function recordChoice(string $name, string $node): string
    {
        $s      = $this->stock->structure();
        $choice = (string) apply_filters('taxmod_record_chooser', '', [
            'name'        => $name,
            'node'        => $s->node($node),
            'label_field' => $s->field('label'),
        ]);

        return $choice;
    }

    /** D-1090: «Verschieben» — the new place chosen in the same tree, the place itself and what lies in it left out. */
    private function movePlaceDialog(int $place, LocationTree $tree): string
    {
        $s      = $this->stock->structure();
        $name   = 'parent[' . $place . ']';
        $parent = $tree->parentOf($place);
        $choice = (string) apply_filters('taxmod_record_chooser', '', [
            'name'         => $name,
            'node'         => $s->node('location'),
            'parent_field' => $s->field('location_parent'),
            'label_field'  => $this->stock->placeWords(),
            'exclude'      => [$place],
            'preselected'  => $parent > 0 ? $parent : null,
            'inline'       => true,
        ]);
        $body = ListPage::field(InputKind::Choice, $name, (string) $parent, ['choices' => ['0' => __('— oberste Ebene —', 'taxmod-stock')], 'lines' => true])
            . '<div style="max-height:50vh;overflow:auto">' . $choice . '</div>'
            . '<p class="description">' . esc_html($tree->isMovable($place) && $this->stock->relocates()
                ? __('Alles, was darin liegt, zieht mit; der Umzug wird als Vorgang «Umlagerung» festgehalten.', 'taxmod-stock')
                : __('Alles, was darin liegt, zieht mit.', 'taxmod-stock')) . '</p>';

        return $this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place)
            . ListPage::dialogAction('taxmod-stock-move-place-' . $place, __('Verschieben', 'taxmod-stock'), __('Verschieben nach', 'taxmod-stock'), $body, 'booking', 'umhaengen-' . $place, __('Verschieben', 'taxmod-stock'))
            . '</form>';
    }

    /**
     * D-1090: what a drop on the tree asks before it acts — a place onto a place (Verschieben), a lot onto a place
     * (Umbuchung). The modeller only reports the drop; this script fills the dialog and opens it.
     */
    private function dropDialogs(string $tab, int $place, LocationTree $tree): string
    {
        $paths   = [0 => __('— oberste Ebene —', 'taxmod-stock')];
        $movable = [];

        foreach (array_keys($this->stock->places()) as $id) {
            $paths[$id] = $tree->pathOf($id);

            if ($tree->isMovable($id)) {
                $movable[] = $id;
            }
        }

        $hangBody = '<input type="hidden" class="taxmod-stock-drop-parent" value="0">'
            . '<p style="margin:0" class="taxmod-stock-drop-text"></p>'
            . '<p class="description taxmod-stock-drop-box" hidden>' . esc_html__('Der Umzug wird als Vorgang «Umlagerung» festgehalten.', 'taxmod-stock') . '</p>';
        $lotBody  = '<input type="hidden" name="thing" value=""><input type="hidden" name="lot" value=""><input type="hidden" name="from" value=""><input type="hidden" name="location" value="">'
            . '<p style="margin-top:0" class="taxmod-stock-drop-text"></p>'
            . self::actForm([['label' => __('Menge', 'taxmod-stock'), 'control' => ListPage::quantity('quantity', ''),
                'hint' => __('Bucht die Menge vom bisherigen Ort ab und am neuen zu, als Vorgang «Umbuchung».', 'taxmod-stock')]]);

        $texts = [
            'hang' => __('«%1$s» nach «%2$s» verschieben? Alles, was darin liegt, zieht mit.', 'taxmod-stock'),
            'lot'  => __('Posten #%1$s von «%2$s» nach «%3$s» umbuchen.', 'taxmod-stock'),
        ];

        return '<style>.taxmod-stock-drop-dialogs .taxmod-dialog-open{display:none}.taxmod-stock-place-actions form{display:inline}</style><div class="taxmod-stock-drop-dialogs">'
            . $this->formStart('lagerstruktur') . $this->returnFields($tab, $place)
            . ListPage::dialogAction('taxmod-stock-drop-place', __('Verschieben', 'taxmod-stock'), __('Lagerort verschieben', 'taxmod-stock'), $hangBody, 'booking', 'umhaengen-0', __('Verschieben', 'taxmod-stock'))
            . '</form>'
            . $this->formStart('umbuchen') . $this->returnFields($tab, $place)
            . ListPage::dialogAction('taxmod-stock-drop-lot', __('Umbuchen', 'taxmod-stock'), __('Bestandsposten umbuchen', 'taxmod-stock'), $lotBody, 'booking', 'umbuchen', __('Umbuchen', 'taxmod-stock'))
            . '</form>'
            // D-1095: «davor»/«danach» reorders at once — it moves nothing and is undone by dragging back.
            . str_replace('<form ', '<form class="taxmod-stock-rank-form" ', $this->formStart('reihenfolge')) . $this->returnFields($tab, $place)
            . '<input type="hidden" name="place" value=""><input type="hidden" name="target" value=""><input type="hidden" name="where" value="">'
            . '</form></div>'
            . '<script>(function(){'
            . 'document.addEventListener("taxmod-record-drop",function(e){var d=e.detail,g;if(d.record===null||(d.where!=="before"&&d.where!=="after")){return;}'
            . 'e.stopImmediatePropagation();g=document.querySelector(".taxmod-stock-rank-form");g.elements.place.value=d.record;g.elements.target.value=d.target;g.elements.where.value=d.where;g.requestSubmit();},true);'
            . 'var paths=' . wp_json_encode((object) $paths) . ',movable=' . wp_json_encode($movable) . ',texts=' . wp_json_encode($texts) . ';'
            . 'function fill(t,a){return t.replace(/%(\\d)\\$s/g,function(m,n){return a[parseInt(n,10)-1];});}'
            . 'function open(id){var sw=document.getElementById(id);sw.checked=true;sw.dispatchEvent(new Event("change",{bubbles:true}));return sw.closest("form");}'
            . 'document.addEventListener("dragstart",function(e){var r=e.target.closest&&e.target.closest("[data-taxmod-stock-lot]");if(!r){return;}e.dataTransfer.effectAllowed="move";e.dataTransfer.setData("application/x-taxmod-item","lot:"+r.getAttribute("data-taxmod-stock-lot")+":"+encodeURIComponent(r.getAttribute("data-taxmod-stock-unit")||""));});'
            . 'document.addEventListener("taxmod-record-drop",function(e){var d=e.detail,f;'
            . 'if(d.record!==null){f=open("taxmod-stock-drop-place");var p=f.querySelector(".taxmod-stock-drop-parent");p.name="parent["+d.record+"]";p.value=d.target;'
            . 'f.querySelector("button[name=booking]").value="umhaengen-"+d.record;f.querySelector(".taxmod-stock-drop-text").textContent=fill(texts.hang,[paths[d.record]||"#"+d.record,paths[d.target]||"#"+d.target]);'
            . 'f.querySelector(".taxmod-stock-drop-box").hidden=movable.indexOf(d.record)<0;return;}'
            . 'if(!d.item||d.item.indexOf("lot:")!==0||d.target===0){return;}var x=d.item.split(":");if(parseInt(x[2],10)===d.target){return;}f=open("taxmod-stock-drop-lot");'
            . 'f.elements.lot.value=x[1];f.elements.from.value=x[2];f.elements.quantity.value=x[3];f.elements.thing.value=x[4];f.elements.location.value=d.target;var u=f.querySelector(".taxmod-quantity-unit");if(u){u.textContent=decodeURIComponent(x[5]||"");}'
            . 'f.querySelector(".taxmod-stock-drop-text").textContent=fill(texts.lot,[x[1],paths[x[2]]||"#"+x[2],paths[d.target]||"#"+d.target]);});'
            . '})();</script>';
    }

    /** A quantity as the forms take it: a dot, no trailing zeros. */
    private function plain(float $quantity): string
    {
        return DecimalText::stored($quantity, 3);
    }

    /** D-1082: «reserviert für Produktion» at the place that carries the flag, «reserviert» at what lies below it. */
    private function reservedMark(int $place, \TaxmodStock\Core\ReservedPlaces $flags): string
    {
        $holder = $flags->reservingPlaceOf($place);

        if ($holder === null) {
            return '';
        }

        return ' <span class="taxmod-stock-reserved" style="background:#fcf0e3;color:#8a4b00;border-radius:3px;padding:0 6px">'
            . esc_html($holder === $place ? __('reserviert für Produktion', 'taxmod-stock') : __('reserviert', 'taxmod-stock')) . '</span>';
    }

    /**
     * D-1082: setting or taking off the flag, asked in the shared dialog — taking it off frees what lies there, so the
     * dialog says how many lots that are.
     */
    private function reserveDialog(int $place, bool $flagged, int $lots): string
    {
        if (! $this->stock->structure()->hasField('location_reserved')) {
            return '';
        }

        $text = $flagged
            ? sprintf(
                /* translators: %d: lots in the place and below it */
                __('Das Kennzeichen kommt weg; die %d Posten dort und darunter sind dann nicht mehr reserviert.', 'taxmod-stock'),
                $lots
            )
            : __('Alles, was hier und an den Orten darunter liegt, ist dann reserviert: verbraucht wird erst nach dem Umbuchen hinaus, und schon reservierte Ware kommt nicht hinein.', 'taxmod-stock');

        return ListPage::dialogAction(
            'taxmod-stock-reserve-' . $place,
            $flagged ? __('Kennzeichen entfernen', 'taxmod-stock') : __('Für Produktion reservieren', 'taxmod-stock'),
            $flagged ? __('Reservierung des Ortes aufheben', 'taxmod-stock') : __('Reserviert für Produktion', 'taxmod-stock'),
            '<p style="margin:0">' . esc_html($text) . '</p>',
            'booking',
            ($flagged ? 'freigeben-' : 'reservieren-') . $place,
            $flagged ? __('Entfernen', 'taxmod-stock') : __('Reservieren', 'taxmod-stock')
        );
    }

    /**
     * D-1298 keeps the modeller's own control for «Menge»; D-1504 and his finding 21.11.2026 «Die Menge ist wieder nicht
     * einheitswert»: it stands in the one quantity frame with the unit beside it, which the chooser's pick fills ({@see self::unitScript()}).
     */
    private function consumptionQuantity(string $control, string $unit): string
    {
        if ($control === '') {
            return ListPage::quantity('quantity', $unit, '', ['required' => 'required']);
        }

        // The quantity frame's width and alignment for the modeller's input, which carries no class of its own.
        $control = preg_replace('/<input (?![^>]*class=)/', '<input class="taxmod-quantity-value" ', $control, 1) ?? $control;

        return '<span class="taxmod-quantity">' . $control . '<span class="taxmod-quantity-unit taxmod-stock-unit">' . esc_html($unit) . '</span></span>';
    }

    private function consumptionForm(int $preset, int $presetLot, int $presetPlace = 0): string
    {
        $ledger = $this->stock->ledger();
        // D-1299, his question 2026-11-10 «baum in dem ich bestand wähle»: one chooser picks the lot, the material follows from it;
        // a material alone (from its list) proposes its oldest lot with a rest, as the booking always did.
        $lot    = $presetLot > 0 && $ledger->thingOf($presetLot) !== null ? $presetLot : ($preset > 0 && $ledger->lotsOf($preset) !== [] ? $ledger->proposedLot($preset) : 0);
        $thing  = $lot > 0 ? (int) $ledger->thingOf($lot) : 0;
        $word   = '';
        foreach ($lot > 0 ? $this->stock->lotHits([$thing]) : [] as $hit) {
            $word = $hit['id'] === $lot ? $hit['word'] : $word;
        }
        $controls = $this->stock->consumptionControls('taxmod-stock-consumption');
        // A material named without a lot still says its unit; the chooser's pick says it after.
        $counted  = $thing > 0 ? $thing : $preset;
        $unit     = $counted > 0 ? ($this->stock->unitWords([$counted])[$counted] ?? '') : '';
        $pack     = $thing > 0 ? ($this->stock->packsOf([$thing])[$thing] ?? null) : null;

        // D-1039: «Ausbuchen» on a row of the Bestand tab takes from that row's place.
        $at   = $presetPlace > 0 ? sprintf(/* translators: %s: the place, by its path */ __('Ausgebucht wird am Ort %s.', 'taxmod-stock'), $this->stock->places()[$presetPlace] ?? '#' . $presetPlace) : '';
        $rows = [
            ['label' => __('Bestandsposten', 'taxmod-stock'), 'control' => $this->lotWords()
                . $this->recordPicker('lot', $lot > 0 ? $lot : null, $word === '' ? '#' . $lot : $word, null, __('Material oder Posten suchen …', 'taxmod-stock'))
                . ($presetPlace > 0 ? '<input type="hidden" name="place" value="' . esc_attr((string) $presetPlace) . '">' : ''), 'hint' => $at],
            // D-1298: the modeller's own number control; the unit is the material's, shown beside it by name.
            // D-1398: a material counted in pins goes out in whole pieces, each cut from one strip; no «Menge» then.
            ...($pack !== null ? [
                ['label' => __('Anzahl Stücke', 'taxmod-stock'), 'for' => 'taxmod-stock-pieces', 'control' => ListPage::field(InputKind::Number, 'pieces', '', ['id' => 'taxmod-stock-pieces', 'min' => 1, 'step' => 1, 'required' => true])],
                ['label' => __('Pins je Stück', 'taxmod-stock'), 'for' => 'taxmod-stock-piece-pins', 'control' => ListPage::field(InputKind::Number, 'piece_pins', (string) $pack->content, ['id' => 'taxmod-stock-piece-pins', 'min' => 1, 'max' => $pack->content, 'step' => 1, 'required' => true]),
                    'hint' => __('Jedes Stück ganz aus einer Leiste; was übrig bleibt, kommt als eigener Posten zurück.', 'taxmod-stock')],
            ] : [
                ['label' => __('Menge', 'taxmod-stock'), 'control' => $this->consumptionQuantity($controls['quantity'], $unit)],
            ]),
            $this->purposeRow($presetPlace, '', true),
        ];
        // D-1297, D-1298: the kind, from the modeller's chooser narrowed to the leaves under «Verbrauch»; required there.
        if ($controls['kind'] !== '') {
            $rows[] = ['label' => __('Art', 'taxmod-stock'), 'control' => $controls['kind'], 'hint' => __('Pflicht: wofür die Ware das Lager verlässt.', 'taxmod-stock')];
        }
        $rows = [...$rows,
            ['label' => __('Grund (optional)', 'taxmod-stock'), 'for' => 'taxmod-stock-consumption-reason', 'control' => ListPage::field(InputKind::Line, 'reason', '', ['id' => 'taxmod-stock-consumption-reason', 'list' => 'taxmod-stock-consumption-reasons', 'size' => 32])
                . self::consumptionReasonList()],
        ];
        $submit = get_submit_button(__('Verbrauch buchen', 'taxmod-stock'), 'primary', 'submit', false);

        // D-1436: the page says what it does, like a dialog.
        return '<h2>' . esc_html__('Verbrauch buchen (Ausbuchen)', 'taxmod-stock') . '</h2>'
            . str_replace('<form ', '<form id="taxmod-stock-consumption" ', $this->formStart('verbrauch')) . self::actForm($rows, $submit) . '</form>';
    }

    /** The page being drawn; details drawn later come by admin-ajax, so there it is the page that asked for them. '' unknown. */
    private static function here(): string
    {
        return esc_url_raw(wp_doing_ajax() ? (string) wp_get_referer()
            : (is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_HOST'] ?? ''))) . wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
    }

    /** D-1377: a page of this admin to go back to, '' for anything else. */
    private static function returnUrl(string $raw): string
    {
        $url = esc_url_raw(rawurldecode(wp_unslash($raw)));

        return $url !== '' && str_starts_with($url, admin_url()) ? (string) wp_validate_redirect($url, '') : '';
    }

    /**
     * The architecture thread 2026-11-09: a form for an act, not a record, through the modeller's shared form block
     * (`ListPage::form`, modeller 0.2.33) — label left, the hint as «?», the buttons below; plain labels on an older one.
     *
     * @param list<array{label: string, control: string, hint?: string, for?: string}> $rows
     */
    public static function actForm(array $rows, string $actions = ''): string
    {
        return ListPage::form($rows, $actions);
    }

    /**
     * D-1088: the purpose of a consumption — a subject, «für Produktion», or none. Taken from a reserved place, its Bezug
     * is preselected: reserved goods go only for exactly that purpose. One row of the shared form block.
     *
     * D-1567: with `$lots` the group «Bestand» names a Bestandsposten as the Bezug, picked with the record chooser (D-1488)
     * in lot mode — the Ausbuchen form and the row dialog alike (D-1511); Fertigung and Reparatur stay no Bezug.
     *
     * @return array{label: string, control: string, hint: string, for: string}
     */
    private function purposeRow(int $place, string $idSuffix = '', bool $lots = false): array
    {
        $field    = 'taxmod-stock-consumption-purpose' . ($idSuffix === '' ? '' : '-' . $idSuffix);
        $preset   = $this->stock->purposeAt($place);
        $selected = $preset === null ? '' : ($preset->production ? 'produktion' : (string) $preset->subject);
        $choices  = ['produktion' => __('für Produktion (ohne Projekt)', 'taxmod-stock')];

        foreach ($this->purposes ??= $this->stock->purposes() as $group => $records) {
            foreach ($records as $id => $label) {
                $choices[$id] = $group . ' · ' . $label;
            }
        }
        if ($lots) {
            $choices[self::PURPOSE_LOT] = __('Bestand', 'taxmod-stock') . ' · ' . __('Bestandsposten wählen …', 'taxmod-stock');
        }
        $control = SelectMarkup::of('purpose', $choices, $selected, attributes: ['id' => $field], nothingWord: __('— kein Bezug —', 'taxmod-stock'));
        if ($lots) {
            $box      = $field . '-lot';
            $control .= $this->lotWords() . '<span id="' . esc_attr($box) . '" hidden> ' . $this->recordPicker('purpose_lot', null, '', null, __('Bestandsposten suchen …', 'taxmod-stock')) . '</span>'
                . '<script>(function(){var s=document.getElementById(' . wp_json_encode($field) . '),b=document.getElementById(' . wp_json_encode($box) . ');'
                . 'if(!s||!b){return;}function f(){b.hidden=s.value!==' . wp_json_encode(self::PURPOSE_LOT) . ';}s.addEventListener("change",f);f();})();</script>';
        }

        return ['label' => __('Bezug', 'taxmod-stock'), 'for' => $field, 'control' => $control,
            'hint' => __('Reservierte Ware darf nur für genau ihren Bezug verbraucht werden; der Verbrauch mindert dann die offene Reservierung. Für jeden anderen Zweck erst aus dem reservierten Bereich umbuchen.', 'taxmod-stock')];
    }

    /** D-1088: «produktion», a record id, or nothing — as the form sends it; D-1567: «bestand» with the lot picked. */
    private function purpose(string $value, int $lot = 0): Purpose
    {
        return match (true) {
            $value === 'produktion'       => Purpose::production(),
            $value === self::PURPOSE_LOT  => $lot > 0 ? Purpose::subject($lot) : throw StockError::noSubject(),
            default                       => Purpose::subject(absint($value)),
        };
    }

    /**
     * D-1088: the reasons offered for a consumption; any other text is accepted too. A repair is a reason, not a Bezug.
     *
     * @return list<string>
     */
    private static function consumptionReasons(): array
    {
        // D-1297: a loss is a kind of its own now («Verlust/Schwund»), not a reason.
        return [__('Reparatur', 'taxmod-stock')];
    }

    /**
     * The ticked purchases with their thing — «keep» when the chooser still holds the Modell, else the record chosen — and their place.
     *
     * @return array<int, ReceiptChoice>
     */
    private function receiptChoices(): array
    {
        $things = (array) ($_POST['thing'] ?? []);
        $models = (array) ($_POST['model'] ?? []);
        $places = (array) ($_POST['location'] ?? []);
        $out    = [];

        $ticked = array_filter(array_map('absint', (array) ($_POST['purchases'] ?? [])));

        // A purchase whose thing was changed by hand is meant to go in, ticked or not (his report 2026-10-28: «nix passiert»).
        $new = $this->newThings();

        foreach ($things as $purchase => $thing) {
            if ((absint($thing) > 0 && absint($thing) !== absint($models[$purchase] ?? 0)) || isset($new[absint($purchase)])) {
                $ticked[] = absint($purchase);
            }
        }

        foreach (array_unique(array_filter($ticked)) as $purchase) {
            $thing = absint($things[$purchase] ?? 0);
            $model = absint($models[$purchase] ?? 0);

            $out[$purchase] = (match (true) {
                // D-1062: a group chosen in the search — a new material there, named like the purchase.
                isset($new[$purchase])              => ReceiptChoice::create($new[$purchase]),
                $thing === 0                        => throw StockError::purchaseWithoutThing($purchase),
                $model !== 0 && $thing === $model   => ReceiptChoice::keep(),
                default                             => ReceiptChoice::existing($thing),
            })->at(absint($places[$purchase] ?? 0) ?: null);
        }

        return $out;
    }

    /**
     * D-1440: an act of the one table of «Angekommen», once per order its purchases belong to — each order its own write.
     *
     * @param list<int>                               $purchases
     * @param callable(int, list<int>): array<mixed>  $act
     * @return array<int, array<mixed>> order → the act's answer
     */
    private function receiptPerOrder(array $purchases, callable $act): array
    {
        $orderOf = array_map('absint', (array) ($_POST['order_of'] ?? []));
        $by      = [];

        foreach ($purchases as $purchase) {
            if (($orderOf[$purchase] ?? 0) > 0) {
                $by[$orderOf[$purchase]][] = (int) $purchase;
            }
        }

        if ($by === []) {
            throw StockError::nothingToBook();
        }

        $out = [];

        foreach ($by as $order => $only) {
            $out[$order] = $act($order, $only);
        }

        return $out;
    }

    /**
     * D-1440, his word 2026-11-18 11:33: «wenn kein Lagerort angegeben ist, dann wird es in den Wareneingang gelegt» — a choice
     * without a place takes «Lagerort Wareneingang»; while that is not set, the choice stays as it was.
     *
     * @param array<int, ReceiptChoice> $choices
     * @return array<int, ReceiptChoice>
     */
    private function toArrival(array $choices): array
    {
        $arrival = $this->stock->receiptInbound();

        return $arrival === 0 ? $choices : array_map(static fn (ReceiptChoice $c): ReceiptChoice => $c->location === null ? $c->at($arrival) : $c, $choices);
    }

    /** D-1440: «Einlagern» of one row — its material, quantity and place, without a place «Lagerort Wareneingang». */
    private function storePosition(int $order, int $purchase, string $today): array
    {
        $_POST['purchases'] = [(string) $purchase];
        $choice = $this->receiptChoices()[$purchase] ?? throw StockError::purchaseWithoutThing($purchase);
        $choice = $this->toArrival([$purchase => $choice])[$purchase];

        if ($choice->location === null) {
            throw StockError::notConfigured(__('Lagerort Wareneingang', 'taxmod-stock'));
        }

        return $this->stock->bookReceipt($order, [$purchase => $choice], $today, $this->title(__('Wareneingang', 'taxmod-stock'), $today), array_intersect_key($this->receiptQuantities(), [$purchase => true]));
    }

    /**
     * D-1440: «Wareneingang rückgängig …» on «Angekommen» — the orders ticked, and those of the purchases ticked.
     *
     * @return array{done: list<int>, skipped: array<int, int>}
     */
    private function unbookOrders(): array
    {
        $orderOf = array_map('absint', (array) ($_POST['order_of'] ?? []));
        $orders  = array_map('absint', (array) ($_POST['orders'] ?? []));

        foreach (array_map('absint', (array) ($_POST['purchases'] ?? [])) as $purchase) {
            $orders[] = $orderOf[$purchase] ?? 0;
        }

        $orders = array_values(array_unique(array_filter($orders)));

        if ($orders === []) {
            throw StockError::nothingToBook();
        }

        $out     = ['done' => [], 'skipped' => []];
        $inbound = new \TaxmodStock\WordPress\InboundService($this->stock);

        foreach ($orders as $order) {
            $one = $inbound->unbookFree($order);
            $out = ['done' => [...$out['done'], ...$one['done']], 'skipped' => $out['skipped'] + $one['skipped']];
        }

        return $out;
    }

    /** @return array<int, string> purchase id → the quantity typed in its row; the service checks it */
    private function receiptQuantities(): array
    {
        $out = [];

        foreach ((array) ($_POST['quantity'] ?? []) as $purchase => $typed) {
            if (absint($purchase) > 0 && is_scalar($typed)) {
                $out[absint($purchase)] = sanitize_text_field(wp_unslash((string) $typed));
            }
        }

        return $out;
    }

    /**
     * The material chosen per purchase in the goods receipt form, whatever is ticked — the interim state he keeps without a receipt.
     *
     * @return array<int, int> purchase id → thing
     */
    private function assignedThings(): array
    {
        $out = [];

        $drawn = (array) ($_POST['model'] ?? []);

        foreach ((array) ($_POST['thing'] ?? []) as $purchase => $thing) {
            // An empty choice is a material taken away; 0 tells the booking to clear the purchase's «Modell».
            // His report 2026-11-16 «materialien … sind nicht mehr gesetzt»: only where the form was drawn with one — an empty
            // row of an older form never clears a material chosen since.
            if (absint($purchase) > 0 && (absint($thing) > 0 || ((string) $thing === '' && absint($drawn[$purchase] ?? 0) > 0))) {
                $out[absint($purchase)] = absint($thing);
            }
        }

        return $out;
    }

    /** @return array<int, int> purchase id → the group of a new material chosen in its row («new-<node>», D-1062) */
    private function assignedGroups(): array
    {
        $out = [];

        foreach ((array) ($_POST['thing'] ?? []) as $purchase => $thing) {
            if (absint($purchase) > 0 && preg_match('/^new-(\d+)$/', (string) $thing, $m) === 1) {
                $out[absint($purchase)] = (int) $m[1];
            }
        }

        // The button «Neues Material» asks the group in a dialog of its own.
        foreach ((array) ($_POST['newgroup'] ?? []) as $purchase => $node) {
            if (absint($purchase) > 0 && absint($node) > 0) {
                $out[absint($purchase)] = absint($node);
            }
        }

        return $out;
    }

    /**
     * D-1062, D-1069: the new materials chosen in the rows — named like their purchase, filled later in the material view.
     *
     * @return array<int, NewThing> purchase id → new material
     */
    private function newThings(): array
    {
        $out    = [];
        $places = $this->assignedPlaces();

        foreach ($this->assignedGroups() as $purchase => $node) {
            $out[$purchase] = $this->stock->newThingFor($purchase, $node, absint($_POST['order'] ?? 0), ($places[$purchase] ?? 0) ?: null,
                $this->stock->manufacturerTyped((array) wp_unslash($_POST['newfields_' . $purchase] ?? []), $node));
        }

        return $out;
    }

    /** @return array<int, int> purchase id → the place chosen in its row */
    private function assignedPlaces(): array
    {
        $out = [];

        foreach ((array) ($_POST['location'] ?? []) as $purchase => $place) {
            if (absint($purchase) > 0) {
                $out[absint($purchase)] = absint($place);
            }
        }

        // His report 2026-11-09 «wenn ich ein material neu anlege wird der lagerort aus dem neuen material nicht übernommen»: the row
        // showed the place proposed before the new material existed; left as it was, the new material's own (its group's) is taken.
        $new   = array_intersect_key($this->assignedGroups(), $out);
        $order = absint($_POST['order'] ?? 0);

        if ($new !== [] && $order > 0) {
            $shown = $this->stock->receiptLocations(array_intersect_key($this->stock->receiptPlan($order), $new));

            foreach (array_keys($new) as $purchase) {
                if (($shown[$purchase] ?? -1) === $out[$purchase]) {
                    $out[$purchase] = 0;
                }
            }
        }

        return $out;
    }

    private function lagerInput(): LagerInput
    {
        $minimum = sanitize_text_field(wp_unslash((string) ($_POST['minimum'] ?? '')));

        // D-1360: the Standard-Lagerorte in their order, empty and repeated ones left out; a single «location» as before.
        $places = array_values(array_unique(array_filter(array_map('absint', is_array($_POST['locations'] ?? null) ? array_values($_POST['locations']) : [absint($_POST['location'] ?? 0)]))));

        return new LagerInput(
            ! empty($_POST['single']),
            absint($_POST['use'] ?? 0) ?: null,
            $places[0] ?? null,
            $minimum === '' ? null : absint($minimum),
            null,
            absint($_POST['unit'] ?? 0) ?: null,
            absint($_POST['prefix'] ?? 0) ?: null,
            array_slice($places, 1)
        );
    }

    /**
     * The modeller's chooser for «every record under Model» (D-958 F3a, D-1011): the chosen word with a bin, or a search field and
     * a tree button. Hits and tree come from the material endpoints, kept to the groups of things. D-1488: the modeller draws it —
     * one chooser, no copy here.
     *
     * @param int|null $purchase D-1111: the receipt row whose «Material» dialog the chosen word opens; null: a plain word
     */
    private function recordPicker(string $name, ?int $value, string $word, ?int $purchase = null, string $placeholder = '', bool $wrap = false): string
    {
        return \Taxmod\WordPress\Admin\RecordPick::remote($name, $value, $word, [
            'search' => $placeholder === '' ? __('Material suchen …', 'taxmod-stock') : $placeholder,
            'tree'   => __('Im Baum wählen', 'taxmod-stock'),
            'clear'  => __('Zurücksetzen', 'taxmod-stock'),
        ], $wrap, $value === null || $purchase === null ? '' : 'taxmod-stock-material-' . $purchase);
    }

    /** Stefan 2026-11-14 «einfach ein Material wählen»: the material chooser of the stock with what it needs, for another page. */
    public function materialPicker(string $name): string
    {
        return $this->recordPicker($name, null, '') . $this->modellerAssets(false);
    }

    /** The material chooser alone, for a page that already drew {@see self::materialPicker()} once. */
    public function materialChooser(string $name): string
    {
        return $this->recordPicker($name, null, '');
    }

    /**
     * The modeller's stylesheet and script for {@see self::recordPicker()}, and the one element its script reads the material
     * endpoints from: search, the tree's groups and a group's things.
     */
    /** The lot-mode words of {@see self::modellerAssets()}, drawn once per page however many choosers ask (D-1567). */
    private function lotWords(): string
    {
        if ($this->lotWordsDrawn) {
            return '';
        }
        $this->lotWordsDrawn = true;

        return $this->modellerAssets(false, true);
    }

    private function modellerAssets(bool $groups, bool $lots = false): string
    {
        if (class_exists(\Taxmod\WordPress\Plugin::class)) {
            // D-1269: the modeller's own stylesheet with the icon size he chose, and its script.
            do_action(\Taxmod\WordPress\Plugin::STYLE_ACTION);
        }

        $address = static fn (string $action): string => esc_url(add_query_arg(['action' => $action, '_ajax_nonce' => wp_create_nonce($action)], admin_url('admin-post.php')));

        $search  = $lots ? $address(self::LOT_SEARCH) : $address(self::THING_SEARCH) . ($groups ? '&amp;groups=1' : '');

        // D-1299: for Ausbuchen the same tree of groups, its records are the lots of the group's materials.
        return '<span hidden id="taxmod-words" data-record-search="' . $search . '"'
            . ' data-record-nodes="' . $address(self::THING_NODES) . '" data-record-branch="' . $address($lots ? self::LOT_BRANCH : self::THING_BRANCH) . '"'
            . ' data-browse-title="' . ($lots ? esc_attr__('Bestandsposten im Baum wählen', 'taxmod-stock') : esc_attr__('Material im Baum wählen', 'taxmod-stock')) . '"'
            . ' data-browse-filter="' . ($lots ? esc_attr__('Material, Posten oder Gruppe suchen …', 'taxmod-stock') : esc_attr__('Material oder Gruppe suchen …', 'taxmod-stock')) . '"'
            // Read by the modeller's script when a tree fetched for a chooser is gone (D-898).
            . ' data-reload-list="' . esc_attr__('Diese Liste ist nicht mehr verfügbar. Bitte die Seite neu laden.', 'taxmod-stock') . '"'
            . ' data-no-hits="' . esc_attr__('Nichts gefunden.', 'taxmod-stock') . '"></span>'
            . self::unitScript($address(self::THING_UNIT));
    }

    /**
     * D-1504: a material taken in a chooser puts its unit beside the quantity in the same dialog or row ({@see ListPage::quantity()});
     * only shown — the server books in the material's own unit. Printed once per page.
     */
    private static function unitScript(string $address): string
    {
        static $printed = false;

        if ($printed) {
            return '';
        }

        $printed = true;

        return '<script>(function(){var url=' . wp_json_encode(html_entity_decode($address)) . ';'
            . 'function put(r){var t=r.target;if(!t||t.type!=="radio"||!t.closest(".taxmod-record-choice")||!t.value){return;}'
            . 'var box=t.closest("tr,.taxmod-dialog-panel,form");var u=box&&box.querySelector(".taxmod-quantity-unit");if(!u){return;}'
            . 'fetch(url+"&thing="+encodeURIComponent(t.value),{credentials:"same-origin"}).then(function(a){return a.json();}).then(function(a){if(a&&a.success){u.textContent=a.data.unit||"";}}).catch(function(){});}'
            . 'document.addEventListener("change",put);document.addEventListener("taxmod-record-picked",put);})();</script>';
    }

    /**
     * A thing as one search field that shows the record's name; its id travels in a hidden field beside it
     * (his question 2026-10-25: «was hält uns davon ab den einfach aufzulösen ?»). Typing «#123» sets the id directly.
     */
    private function thingField(string $name, string $id, ?int $value): string
    {
        $shown = '';

        if ($value !== null) {
            $label = $this->stock->recordLabels([$value])[$value] ?? '';
            $shown = '#' . $value . ($label === '' ? '' : ' · ' . $label);
        }

        return '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) ($value ?? '')) . '">'
            . '<input type="search" class="taxmod-stock-thing-search" data-for="' . esc_attr($id) . '" list="' . esc_attr($id . '-hits') . '" value="' . esc_attr($shown) . '" placeholder="'
            . esc_attr__('Material suchen …', 'taxmod-stock') . '" style="width:18em"><datalist id="' . esc_attr($id . '-hits') . '"></datalist>';
    }

    /**
     * Filters a choice list as one types, and asks the material search for a thing; loads an order's purchases
     * once its details show. Public because the goods receipt cockpit shows the same booking form (D-1001).
     */
    public function script(bool $receipt = false): string
    {
        $search    = add_query_arg(['action' => self::THING_SEARCH, '_ajax_nonce' => wp_create_nonce(self::THING_SEARCH)], admin_url('admin-post.php'));
        $positions = add_query_arg(['action' => self::POSITIONS, '_ajax_nonce' => wp_create_nonce(self::POSITIONS)], admin_url('admin-post.php'));
        $failed    = __('Speichern fehlgeschlagen.', 'taxmod-stock');

        return $this->modellerAssets($receipt) . '<script>(function(){'
            . 'var url=' . wp_json_encode($search) . ',pos=' . wp_json_encode($positions) . ',post=' . wp_json_encode(admin_url('admin-post.php')) . ';'
            // D-1256: the manufacturer row asks for the chosen group's own field; a group without one hides the row.
            . 'var mk=' . wp_json_encode(add_query_arg(['action' => self::MAKER, '_ajax_nonce' => wp_create_nonce(self::MAKER)], admin_url('admin-post.php'))) . ';'
            . 'function maker(m){var d=m.closest(".taxmod-dialog-panel")||document,n=m.dataset.groupField,'
            . 'f=d.querySelector("input[name=\\""+n+"\\"]:checked")||d.querySelector("select[name=\\""+n+"\\"]"),g=f?String(f.value):"",r=m.closest(".taxmod-form-row")||m;'
            . 'if(m.dataset.group===g){return;}m.dataset.group=g;if(!g||g==="0"){m.innerHTML="";r.hidden=true;return;}'
            . 'fetch(mk+"&group="+encodeURIComponent(g)+"&prefix="+encodeURIComponent(m.dataset.prefix),{credentials:"same-origin"}).then(function(x){return x.json();})'
            . '.then(function(j){if(m.dataset.group!==g){return;}m.innerHTML=(j.data&&j.data.html)||"";r.hidden=m.innerHTML==="";});}'
            . 'function makers(){document.querySelectorAll(".taxmod-stock-maker").forEach(maker);}'
            . 'document.addEventListener("change",function(e){if(!e.target.closest||!e.target.closest(".taxmod-stock-maker")){makers();}});makers();'
            // D-1203: a message in the field beside the heading; a page without it keeps the browser's own.
            . 'function say(t,ok){if(window.taxmodMessage){window.taxmodMessage(t,ok?"ok":"bad");}else{window.alert(t);}}'
            // D-1408: the modeller swaps what an act changed (taxmod:replaced); an element kept across the swap is bound once.
            . 'var done=new WeakSet();function once(el){if(done.has(el)){return false;}done.add(el);return true;}'
            . 'function bind(root){if(root!==document){makers();}'
            . 'root.querySelectorAll(".taxmod-stock-filter").forEach(function(f){if(!once(f)){return;}var s=document.getElementById(f.dataset.for);if(!s){return;}var o=Array.prototype.slice.call(s.options);'
            . 'f.addEventListener("input",function(){var q=f.value.toLowerCase(),first=null;o.forEach(function(x){var fix=x.value===""||x.value==="new"||x.value==="keep"||x.value==="search";x.hidden=q!==""&&!fix&&x.text.toLowerCase().indexOf(q)<0;if(!fix&&!x.hidden&&!first){first=x;}});if(q!==""&&first){s.value=first.value;}});});'
            . 'root.querySelectorAll(".taxmod-stock-thing-search").forEach(function(f){if(!once(f)){return;}var t=document.getElementById(f.dataset.for),l=document.getElementById(f.getAttribute("list")),w;'
            . 'var un={},q=function(v){var p=f.closest(".taxmod-dialog-panel,form"),u=p&&p.querySelector(".taxmod-quantity-unit");if(u){u.textContent=v;}};'
            . 'f.addEventListener("input",function(){var m=f.value.match(/^#(\\d+)/);if(m){t.value=m[1];q(un[m[1]]||"");return;}t.value="";q("");clearTimeout(w);if(f.value.trim().length<2){return;}'
            . 'w=setTimeout(function(){fetch(url+"&term="+encodeURIComponent(f.value),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){l.innerHTML="";(j.data||[]).forEach(function(h){un[h.id]=h.unit||"";var x=document.createElement("option");x.value="#"+h.id+" · "+h.word+(h.node?" ("+h.node+")":"");l.appendChild(x);});});},250);});});'
            // INF-116: an order not yet arrived keeps its choice without a reload; the form comes back drawn anew.
            . 'root.querySelectorAll("form.taxmod-stock-assign").forEach(function(f){if(!once(f)){return;}f.addEventListener("submit",function(e){var b=e.submitter;if(b&&b.name==="booking"&&b.value!=="zuordnen"){return;}e.preventDefault();var fd=new FormData(f);fd.set("action",' . wp_json_encode(self::ASSIGN) . ');fd.set("booking","zuordnen");'
            . 'var box=f.closest(".taxmod-stock-positions")||f.parentNode;fetch(post,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){var d=j.data||{};if(j.success&&d.html){box.innerHTML=d.html;bind(box);}say(d.message||' . wp_json_encode($failed) . ',!!j.success);})'
            . '.catch(function(){say(' . wp_json_encode($failed) . ',false);});});});'
            . 'root.querySelectorAll("tr").forEach(function(tr){if(!once(tr)){return;}var cb=tr.querySelector("input[name=\\"purchases[]\\"]");if(!cb||cb.disabled||tr.closest("form.taxmod-stock-assign")){return;}tr.querySelectorAll("select,input[type=text],input[type=search]").forEach(function(f){f.addEventListener("change",function(){cb.checked=true;});});});'
            . '}bind(document);'
            . 'function load(box){if(box.dataset.loaded){return;}box.dataset.loaded="1";var cell=box;cell.textContent="…";'
            . 'fetch(pos+"&order="+encodeURIComponent(box.dataset.order)+(box.dataset.back?"&back="+encodeURIComponent(box.dataset.back):"")+(box.dataset.assign?"&assign=1":"")+(box.dataset.pick?"&pick="+encodeURIComponent(box.dataset.pick):"")+(box.dataset.stage?"&stage="+encodeURIComponent(box.dataset.stage):""),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){cell.innerHTML=(j.data&&j.data.html)||"";bind(cell);var h=location.hash&&document.getElementById(location.hash.slice(1));if(h&&cell.contains(h)){h.scrollIntoView({block:"center"});}})'
            . '.catch(function(){delete box.dataset.loaded;cell.textContent=' . wp_json_encode(__('Positionen konnten nicht geladen werden.', 'taxmod-stock')) . ';});}'
            // An order's purchases load once its details row shows: opened by hand, by «Expand all», or open from the start.
            . 'var seen="IntersectionObserver" in window?new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){seen.unobserve(e.target);load(e.target);}});},{rootMargin:"200px"}):null;'
            . 'function boxes(r){r.querySelectorAll(".taxmod-stock-positions:not([data-loaded])").forEach(function(box){if(seen){seen.observe(box);}else{load(box);}});}boxes(document);'
            . 'document.addEventListener("taxmod:replaced",function(e){var r=e.target&&e.target.querySelectorAll?e.target:document;bind(r);boxes(r);});'
            // D-1087: the branch he opened in the tree beside the material search is the group «Neues Material» proposes.
            . 'var browsed={};document.addEventListener("taxmod-browse-node",function(e){var pick=e.target.closest?e.target.closest("tr"):null,f=pick&&pick.querySelector("[name^=\\"thing[\\"]"),m=f&&/^thing\\[(\\d+)\\]/.exec(f.name);if(m){browsed[m[1]]=String(e.detail.node);}});'
            // D-1309: a view dialog's frame is loaded by the modeller's view-dialog.js, which STYLE_ACTION brings (modeller 0.2.69).
            . 'document.addEventListener("change",function(e){var sw=e.target;if(!sw.classList||!sw.classList.contains("taxmod-dialog-switch")||!sw.checked){return;}'
            // A group already chosen in the row's search is the group «Neues Material» proposes (his report 2026-11-01).
            . 'var nm=/^taxmod-stock-new-(\\d+)$/.exec(sw.id||"");if(nm){var r=document.querySelector("input[name=\\"thing["+nm[1]+"]\\"]:checked"),g=r&&/^new-(\\d+)$/.exec(r.value),gid=g?g[1]:(browsed[nm[1]]||""),sel=document.querySelector("select[name=\\"newgroup["+nm[1]+"]\\"]");if(gid&&sel&&sel.querySelector("option[value=\\""+gid+"\\"]")){sel.value=gid;}'
            // The group tree stands once per page and only a placeholder in the row ({@see StockService::sharedTrees()}): it is put in
            // first — fetched where it is large — and the group is chosen once it stands there.
            . 'var pre=function(){var rb=gid&&document.querySelector("input[name=\\"newgroup["+nm[1]+"]\\"][value=\\""+gid+"\\"]");if(rb){rb.checked=true;var ch=rb.closest(".taxmod-chooser"),tx=rb.parentNode.textContent.trim();if(ch){ch.querySelectorAll(":scope > .taxmod-dialog-open, .taxmod-chooser-current").forEach(function(e){e.textContent=tx;});}}};'
            . 'var st=gid&&document.querySelector(".taxmod-record-stub[data-taxmod-name=\\"newgroup["+nm[1]+"]\\"]");if(st&&window.taxmodPutBodies){window.taxmodPutBodies(st.parentNode,pre);}pre();}});'
            // D-1361, his report 2026-11-14 «übernimmt er immer noch nicht den Lagerort, den ich eingestellt habe im Material … wenn
            // ich den Dialog schließe»: the server draws the order's rows again and only this row's place is swapped — when he
            // has not chosen another one in the row meanwhile. Nothing is saved until «Zuordnung speichern» or the booking.
            . 'document.addEventListener("change",function(e){var sw=e.target,m=sw&&sw.id&&/^taxmod-stock-material-(\\d+)$/.exec(sw.id);if(!m||sw.checked){return;}'
            . 'var row=document.getElementById("taxmod-stock-purchase-"+m[1]),cell=row&&row.querySelector("td.taxmod-stock-receipt-place"),box=row&&(row.closest(".taxmod-stock-positions")||row);if(!cell||!box||!box.dataset.order){return;}'
            . 'var now=function(c){var f=c.querySelector("input[name=\\"location["+m[1]+"]\\"]:checked")||c.querySelector("input[type=hidden][name=\\"location["+m[1]+"]\\"],select[name=\\"location["+m[1]+"]\\"]");return f?String(f.value||"0"):"0";};'
            . 'if(now(cell)!==cell.dataset.drawn){return;}'
            . 'fetch(pos+"&order="+encodeURIComponent(box.dataset.order)+(box.dataset.back?"&back="+encodeURIComponent(box.dataset.back):"")+(box.dataset.assign?"&assign=1":"")+(box.dataset.pick?"&pick="+encodeURIComponent(box.dataset.pick):""),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){'
            . 'var doc=new DOMParser().parseFromString((j.data&&j.data.html)||"","text/html"),fr=doc.getElementById("taxmod-stock-purchase-"+m[1]),fc=fr&&fr.querySelector("td.taxmod-stock-receipt-place");'
            . 'if(!fc||fc.dataset.drawn===cell.dataset.drawn||now(cell)!==cell.dataset.drawn){return;}fc=document.importNode(fc,true);'
            // The cell's chooser may name a tree this page has not drawn yet (the material's Standard-Lagerorte on top): its template comes along.
            . 'doc.querySelectorAll("template.taxmod-shared-body").forEach(function(t){if(!document.querySelector("template.taxmod-shared-body[data-taxmod-body=\\""+t.getAttribute("data-taxmod-body")+"\\"]")){row.closest("table").after(document.importNode(t,true));}});'
            . 'cell.replaceWith(fc);var cb=row.querySelector("input[name=\\"purchases[]\\"]");if(cb&&!cb.disabled){cb.checked=true;}});});'
            . '(function(){var mo=(new URLSearchParams(location.search).get("material")||"").replace(/\\D/g,""),n=0;if(!mo){return;}'
            // The rows of an order arrive after the page (lazy details), so the switch is looked for until it is there.
            . 'var t=setInterval(function(){var ms=document.getElementById("taxmod-stock-material-"+mo);if(ms){clearInterval(t);ms.checked=true;ms.dispatchEvent(new Event("change",{bubbles:true}));}else if(++n>40){clearInterval(t);}},250);})();'
            . '})();</script>';
    }

    /** @return list<InventoryRow> */
    private function inventoryRows(): array
    {
        $rows = [];

        foreach ((array) ($_POST['rows'] ?? []) as $index => $row) {
            $row      = is_array($row) ? wp_unslash($row) : [];
            $thing    = absint($row['thing'] ?? 0) ?: null;
            $group    = absint($row['group'] ?? 0);
            $name     = sanitize_text_field((string) ($row['name'] ?? ''));
            $quantity = sanitize_text_field((string) ($row['quantity'] ?? ''));

            // An empty row is no row; a row with neither a thing nor a name for a new one is left out the same way.
            if ($quantity === '' || ($thing === null && $name === '')) {
                continue;
            }

            $rows[] = new InventoryRow(
                $thing,
                $thing === null ? new NewThing($group, $name, null, $this->stock->manufacturerTyped((array) wp_unslash($_POST['countfields_' . absint($index)] ?? []), $group)) : null,
                $name,
                $quantity,
                sanitize_text_field((string) ($row['value'] ?? '')),
                sanitize_text_field((string) ($row['note'] ?? '')),
                sanitize_text_field((string) ($row['total'] ?? ''))
            );
        }

        return $rows;
    }

    /**
     * D-1058: the reasons offered for «Nicht einlagern»; any other text is accepted too.
     *
     * ⚠️ *INFERRED from the card's context; he has not named his list yet.*
     *
     * @return list<string>
     */
    private static function notStockedReasons(): array
    {
        return [__('privat', 'taxmod-stock'), __('sofort verbaut', 'taxmod-stock'), __('verschenkt', 'taxmod-stock'), __('zurückgeschickt', 'taxmod-stock'), __('defekt entsorgt', 'taxmod-stock')];
    }

    /**
     * D-1111: the material's text as a link with the effect of the row's «Material» button — it toggles the same dialog
     * switch, so the same frame loads; without the modeller's dialog it opens the same page the button opens.
     */
    private function materialLink(int $purchase, int $thing, string $text, string $class = ''): string
    {
        $look = ' style="cursor:pointer;color:#2271b1;text-decoration:underline"';

        return '<label class="' . esc_attr(trim($class . ' taxmod-stock-material-link')) . '" for="' . esc_attr('taxmod-stock-material-' . $purchase) . '"' . $look . '>' . esc_html(trim($text) === '' ? '#' . $thing : $text) . '</label>';
    }

    /** D-1058: the reason field and the «Nicht einlagern» button of one purchase. */
    /**
     * The order each purchase is a position of, read once (order_lines backwards).
     *
     * @return array<int, int> purchase → order
     */
    private function ordersOfPurchases(): array
    {
        $orders = $this->stock->table('order');
        $field  = $this->stock->structure()->field('order_lines');
        $out    = [];

        foreach ($orders->ids() as $order) {
            foreach ($orders->all($order, $field) as $line) {
                $out[(int) $line] = $order;
            }
        }

        return $out;
    }

    /**
     * D-1069: the material of a purchase in a dialog — its own page with every view, so a placeholder from the goods receipt is
     * filled where materials are filled (his word 2026-11-01: «im grunde müssten wir doch im dialog einfach nur die material
     * ansicht zeigen»). The frame loads when the dialog opens.
     */
    private function materialDialog(int $purchase, int $thing, string $name): string
    {
        // His answer 2026-11-01 (screenshot of the stock material page): the stock's own material view, not the modeller's.
        // D-1309: in the modeller's one view dialog (S14) — the frame loads when it opens, «Open fully» in a new tab.
        // D-1451: drawn as the icon square; on a modeller without the icon a button with the word (his finding 2026-11-18).
        return ListPage::viewDialog('taxmod-stock-material-' . $purchase, __('Material', 'taxmod-stock'), sprintf(__('Material bearbeiten: %s', 'taxmod-stock'), $name === '' ? '#' . $thing : $name),
            self::thingUrl($thing, 'daten'), self::icon('ICON_EDIT'), true);
    }

    /**
     * D-1072, his word 2026-11-01: «wir brauc hen einen button für neues material erst suchen ist doof» — the group asked in a dialog,
     * the material created at once (D-1062, D-1071) and then filled through «Material».
     */
    private function newThingDialog(int $purchase, string $formId): string
    {
        // D-1075: the modeller's node chooser — search and tree, the one view he set for choosing in the tree.
        $chooser = $this->stock->groupChooser('newgroup[' . $purchase . ']', 0, $formId, '', true, true);
        $maker   = $this->manufacturerControl('newfields_' . $purchase, 'newgroup[' . $purchase . ']');
        $field   = self::actForm(array_merge([['label' => __('Gruppe', 'taxmod-stock'),
            'control' => $chooser,
            'hint' => __('Das Material heißt wie der Kauf; Lagerort und Bezugsquelle kommen aus der Bestellung.', 'taxmod-stock')]],
            // D-1255: the manufacturer goes into the new material, through the modeller's own control.
            $maker === '' ? [] : [['label' => __('Hersteller', 'taxmod-stock'), 'control' => $maker]]));

        return ListPage::dialogAction('taxmod-stock-new-' . $purchase, __('Neues Material', 'taxmod-stock'), __('Neues Material anlegen', 'taxmod-stock'), $field, 'booking', 'zuordnen', __('Anlegen', 'taxmod-stock'), '', self::icon('ICON_NEW')) . ' ';
    }

    /**
     * «Angehakte angekommen»: the material choice is saved first, then the order arrives.
     *
     * @param list<int> $ticked
     * @return array<string, mixed>
     */
    private function arriveTicked(int $order, array $ticked, string $today): array
    {
        if ($ticked === []) {
            throw StockError::nothingToBook();
        }

        $this->stock->assignPurchases($order, $this->assignedThings(), $this->assignedPlaces(), $this->newThings(), $this->receiptQuantities());

        // D-1411: only the ticked purchases arrive; the others stay where they are.
        return (new \TaxmodStock\WordPress\InboundService($this->stock))->markPurchasesArrived($order, $ticked, $today);
    }

    /**
     * The count of purchases taken back, and each one left with the lot that holds it (architecture 16.11., D-1254).
     *
     * @param array{done: list<int>, skipped: array<int, int>} $undone
     */
    private function unbookedText(array $undone): string
    {
        /* translators: %d: number of purchases */
        $text = sprintf(_n('Rückgängig: %d Kauf ist wieder aus dem Bestand.', 'Rückgängig: %d Käufe sind wieder aus dem Bestand.', count($undone['done']), 'taxmod-stock'), count($undone['done']));

        foreach ($undone['skipped'] as $purchase => $lot) {
            /* translators: 1: purchase id, 2: lot id */
            $text .= ' ' . sprintf(__('Kauf #%1$d bleibt: sein Posten #%2$d wurde umgebucht, verbraucht oder gezählt.', 'taxmod-stock'), $purchase, $lot);
        }

        return $text;
    }

    private function closeControls(int $purchase): string
    {
        $id     = (string) $purchase;
        $field  = self::actForm([['label' => __('Grund', 'taxmod-stock'), 'control' => ListPage::field(InputKind::Line, 'reason[' . $id . ']', '', ['list' => 'taxmod-stock-reasons', 'size' => 40])]]);
        $action = 'nichteinlagern-' . $id;

        // D-1063: the reason is asked in the one dialog every list uses.
        return ListPage::dialogAction('taxmod-stock-close-' . $id, __('Nicht einlagern', 'taxmod-stock'), __('Ohne Lager abschließen', 'taxmod-stock'), $field, 'booking', $action, __('Nicht einlagern', 'taxmod-stock'), '', self::icon('ICON_NOT_STORED'));
    }

    private function formStart(string $booking): string
    {
        // D-1377: where a booking returns to — the page that opened its form (`return`), else the page the form stands on.
        // Einbuchen only with an explicit `return`: without it, it goes to the lot as ever.
        $return = in_array($booking, self::RETURNING, true)
            ? (self::returnUrl((string) ($_GET['return'] ?? '')) ?: (in_array($booking, ['wareneingang', 'einlagern', 'verbrauch', 'inventur'], true) ? '' : self::returnUrl(rawurlencode(self::here()))))
            : '';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="booking" value="' . esc_attr($booking) . '">'
            . ($return === '' ? '' : '<input type="hidden" name="return" value="' . esc_attr($return) . '">')
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
    }

    /**
     * His word 2026-11-20 20:23: «Wenn ich im Bestand suche, springt er in die Materialübersicht … ich möchte den Bestand wissen» —
     * a search started on the page «Bestand» keeps to what is in stock, shows each row's lots open, and a single hit opens its stock.
     */
    public static function asksStock(): bool
    {
        return ($_GET[self::ASKS_STOCK] ?? '') === '1';
    }

    private function listRequest(): StockList
    {
        $orderBy = sanitize_key((string) ($_GET['orderby'] ?? ''));

        return new StockList(
            sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
            sanitize_key((string) ($_GET['filter'] ?? '')),
            in_array($orderBy, StockList::ORDER_BY, true) ? $orderBy : 'name',
            sanitize_key((string) ($_GET['order'] ?? '')) === 'desc',
            Wildcard::matches(...)
        );
    }

    /**
     * The page address of a tab with the current search, filter and order, changed by `$change`.
     *
     * @param array<string, string> $change
     */
    private function listUrl(string $tab, StockList $list, array $change): string
    {
        $query = array_filter([
            'page'    => self::PAGE,
            'tab'     => $tab,
            's'       => trim($list->search),
            'filter'  => $list->filter,
            'orderby' => $list->orderBy === 'name' ? '' : $list->orderBy,
            'order'   => $list->descending ? 'desc' : '',
            'group'   => in_array($tab, ['material', 'gruppenbestand'], true) ? sanitize_key((string) ($_GET['group'] ?? '')) : '',
            self::ASKS_STOCK => $tab === 'material' && self::asksStock() ? '1' : '',
        ]);

        return admin_url('admin.php?' . http_build_query(array_filter(array_merge($query, $change)), '', '&'));
    }

    /**
     * Search box, the filters, bulk bar with «Expand all» and the count, all from the shared list page (D-989).
     *
     * @param list<array{0: string, 1: array<string, string>, 2: string, 3: string}> $filters
     * @param array<string, string>                                                $keep    query values the search keeps
     */
    private function tools(string $tab, StockList $list, array $filters, array $keep, string $placeholder, string $count, string $more = '', array $asking = []): string
    {
        $hidden = array_filter(array_merge([
            'page'    => self::PAGE,
            'tab'     => $tab,
            'orderby' => $list->orderBy === 'name' ? '' : $list->orderBy,
            'order'   => $list->descending ? 'desc' : '',
        ], $keep));

        // The shared bar posts its choice as «action», which admin-post routes on: the handler's name goes in its place, so
        // a dialog joining the bulk form (form=) reaches the handler with the rows ticked.
        $bar = str_replace(
            ['name="action" id="' . self::BULK_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::BULK_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"></form>'],
            // D-1462: the search stands in the bar's row, on the right — put in after, so its GET form keeps no «action».
            ListPage::bulkBar(self::BULK_FORM, self::ACTION, [], $count, ListPage::filters(self::FILTER_FORM, $filters) . $more, true, $asking, self::SEARCH_SPOT)
        );

        return str_replace(self::SEARCH_SPOT, ListPage::searchBox(self::FILTER_FORM, $list->search, $placeholder, $hidden, __('Suchen', 'taxmod-stock')), $bar);
    }

    /**
     * The shared bulk bar with every action of the list in its choice (D-1202); a modeller before 0.1.190 draws the dialogs'
     * own buttons beside it instead.
     *
     * @param array<string, array{0: string, 1: string}> $asking
     */
    private static function bulkBar(string $form, string $count, string $beside, array $asking): string
    {
        if ((new \ReflectionMethod(ListPage::class, 'bulkBar'))->getNumberOfParameters() >= 7) {
            return ListPage::bulkBar($form, self::ACTION, [], $count, $beside, true, $asking);
        }

        return ListPage::bulkBar($form, self::ACTION, [], $count, implode('', array_column($asking, 1)) . $beside);
    }

    /** @return array<string, string> */
    private function materialFilters(): array
    {
        return [
            ''                  => __('Alle Materialien', 'taxmod-stock'),
            StockList::IN_STOCK => __('Nur mit Bestand', 'taxmod-stock'),
            StockList::LOW      => __('Unter Mindestbestand', 'taxmod-stock'),
        ];
    }

    /** @return array{0: string, 1: string, 2: string, 3: ''|'asc'|'desc'} a sortable column for the shared list page */
    private function sortColumn(string $tab, StockList $list, string $key, string $label, string $width): array
    {
        $current = $list->orderBy === $key;
        $next    = $current && ! $list->descending ? 'desc' : 'asc';

        return [$label, $width, $this->listUrl($tab, $list, ['orderby' => $key === 'name' ? '' : $key, 'order' => $next === 'desc' ? 'desc' : '']),
            $current ? ($list->descending ? 'desc' : 'asc') : ''];
    }

    /** @param int $columns the module's columns; the list page adds the checkbox and the actions column */
    private function noItems(int $columns): string
    {
        return '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) ($columns + 2)) . '">' . esc_html__('Nichts gefunden.', 'taxmod-stock') . '</td></tr>';
    }

    private function title(string $kind, string $today): string
    {
        $given = sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? '')));

        return $given !== '' ? $given : $kind . ' ' . $today;
    }

    private function number(float $value): string
    {
        return ListPage::number($value);
    }

    /** D-1178, his word «3 Leisten + 12 Pins»: the stock in whole packs and the rest. */
    private function inPacks(Pack $pack, float $pieces): string
    {
        [$packs, $rest] = $pack->split($pieces);

        /* translators: 1: whole packs, 2: name of the pack, 3: pieces left over */
        return sprintf(__('(%1$d × %2$s + %3$s)', 'taxmod-stock'), $packs, $pack->name === '' ? __('Packung', 'taxmod-stock') : $pack->name, $this->number($rest));
    }


}
