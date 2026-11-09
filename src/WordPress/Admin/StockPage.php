<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Listing\ListView;
use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelLayout;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Renderer\DialogMarkup;
use Taxmod\Core\Tile\Tile;
use Taxmod\Core\Tile\TileTone;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\Tiles;
use Taxmod\WordPress\Printing\LabelButton;
use Taxmod\WordPress\Printing\PrintJobs;
use Taxmod\WordPress\Printing\PrintService;
use Taxmod\WordPress\Printing\PrintTab;
use TaxmodStock\Core\FitLevel;
use TaxmodStock\Core\GroupStock;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\LabelTemplates;
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

    /** The codes «print-label» may ask this page for (D-1235, D-1238): material, lot, place, box. */
    public const LABEL_PREFIXES = ['M', 'P', 'O', 'K'];

    public const PAGE       = Pages::STOCK;
    public const ACTION     = 'taxmod_stock_book';
    public const POSITIONS  = 'taxmod_stock_positions';
    /** The notice after a booking; the goods receipt cockpit shows it too, when the booking came from there. */
    public const NOTICE     = 'taxmod_stock_notice';
    private const LAST_LIST = 'taxmod_stock_last_list_';

    /** D-1147: the source name under which the material list's rows draw their lots when opened. */
    public const DETAILS_MATERIAL = 'stock-material';

    private const BACK_TO_LIST = 'materialliste';
    /** Rows per page of the material list (design 20: a list of thousands draws only what is seen). */
    private const PER_PAGE  = 50;
    private const ROWS      = 6;
    private const TABS      = ['wareneingang', 'material', 'gruppenbestand', 'inventur', 'verbrauch', 'reservierungen', 'lagerorte', 'lagerstruktur', 'platzarten', 'etikettenvorlagen', 'drucker', 'fertigung'];
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
    public const THING_NODES  = 'taxmod_stock_thing_nodes';
    public const THING_BRANCH = 'taxmod_stock_thing_branch';

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
        $html = '<div class="wrap">' . ListPage::heading(__('Lager', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION) . $this->notice();

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
                'etikettenvorlagen' => $this->labelTemplatesPage(absint($_GET['vorlage'] ?? 0)),
                'drucker'   => $this->printersPage(),
                'fertigung' => $this->projectBoxList(),
                default     => absint($_GET['split'] ?? 0) > 0
                    ? $this->splitForm(absint($_GET['order'] ?? 0), absint($_GET['split']), sanitize_key((string) ($_GET['back'] ?? '')))
                    : $this->receiptTab($list, absint($_GET['order'] ?? 0)),
            };
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . $this->script($tab === 'wareneingang') . '</div>';
    }

    /** admin-post handler: capability → nonce → validate → sanitize → act → back to the page with a notice. */
    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'taxmod-stock'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        $kind  = sanitize_key((string) ($_POST['booking'] ?? ''));
        // D-1058: the row buttons carry their purchase in the value, «nichteinlagern-<id>» and «einlagern-<id>».
        $rowPurchase = preg_match('/^(nichteinlagern|einlagern|umhaengen|reservieren|freigeben|ortkopieren|artkopieren|vorlagekopieren|rollewechseln)-(\d+)$/', $kind, $m) === 1 ? (int) $m[2] : 0;
        $kind        = $rowPurchase > 0 ? $m[1] : $kind;
        $today = current_time('Y-m-d');
        // The goods receipt sends «thing» as a list (one per purchase); only the other forms send one thing.
        $thing = is_array($_POST['thing'] ?? null) ? 0 : absint($_POST['thing'] ?? 0);
        $back  = ['material', []];
        // D-1001: a receipt booked from the goods receipt cockpit goes back there.
        $fromCockpit = in_array($kind, ['wareneingang', 'aufteilen', 'zuordnen', 'nichteinlagern', 'einlagern'], true) && sanitize_key((string) ($_POST['back'] ?? '')) === InboundPage::BACK;

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
                    $this->title(__('Umbuchung', 'taxmod-stock'), $today)
                ) : $this->stock->moveLot(
                    absint($_POST['lot'] ?? 0),
                    absint($_POST['from'] ?? 0),
                    absint($_POST['location'] ?? 0),
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    $today,
                    $this->title(__('Umbuchung', 'taxmod-stock'), $today)
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
                'storno'       => $this->stock->cancelTransfer(absint($_POST['transaction'] ?? 0), current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                'verbrauchstorno' => $this->stock->cancelConsumption(absint($_POST['transaction'] ?? 0), current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                'inventurstorno' => $this->stock->cancelCount(absint($_POST['transaction'] ?? 0), absint($_POST['line'] ?? 0) ?: null, current_time('Y-m-d H:i:s'), wp_get_current_user()->display_name),
                'wareneingang' => $this->stock->bookReceipt(
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
                'rollewechseln' => $this->stock->changeRoll($rowPurchase, absint($_POST['roll'] ?? 0)),
                'vorlagekopieren' => $copy = $this->stock->copyLabelTemplate($rowPurchase, sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')))),
                'artkopieren'  => $copy = $this->stock->copyPlaceType($rowPurchase, sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')))),
                // D-1130: «Kopieren» — the copy's id is what the page goes back to.
                'ortkopieren'  => $copy = $this->stock->copyPlace($rowPurchase, sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))), ! empty($_POST['below'])),
                'kopieren'     => $copy = $this->stock->copyThing($thing),
                'zuordnen'     => $this->stock->assignPurchases(absint($_POST['order'] ?? 0), $this->assignedThings(), $this->assignedPlaces(), $this->newThings(), $this->receiptQuantities()),
                'verbrauch'    => $this->stock->bookConsumption(
                    $thing,
                    absint($_POST['lot'] ?? 0) ?: null,
                    sanitize_text_field(wp_unslash((string) ($_POST['quantity'] ?? ''))),
                    $this->purpose(sanitize_key(wp_unslash((string) ($_POST['purpose'] ?? '')))),
                    $today,
                    $this->title(__('Verbrauch', 'taxmod-stock'), $today),
                    absint($_POST['place'] ?? 0) ?: null,
                    sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')))
                ),
                default        => throw StockError::nothingToBook(),
            };
            $this->remember('success', match ($kind) {
                'umhaengen'      => __('Umgehängt: der Ort liegt jetzt dort, mit allem, was in ihm liegt.', 'taxmod-stock'),
                'orteverschieben' => __('Verschoben: die gewählten Orte liegen jetzt dort, mit allem, was in ihnen liegt.', 'taxmod-stock'),
                'umbuchen'       => __('Umgebucht: ein Vorgang «Umbuchung» mit Abgang am alten und Zugang am neuen Ort.', 'taxmod-stock'),
                'sammelumbuchen' => __('Umgebucht: alle gewählten Materialien liegen jetzt am neuen Ort, als ein Vorgang «Umbuchung».', 'taxmod-stock'),
                'postenumbuchen' => __('Umgebucht: die gewählten Posten liegen jetzt ganz am neuen Ort, als ein Vorgang «Umbuchung».', 'taxmod-stock'),
                'storno', 'reservierungstorno', 'verbrauchstorno', 'inventurstorno' => __('Storniert: die Gegenbuchung hebt den Vorgang auf, beide bleiben im Protokoll.', 'taxmod-stock'),
                'reservieren'    => __('Reserviert: der verfügbare Bestand sinkt, der Bestand bleibt.', 'taxmod-stock'),
                'ort'            => __('Lagerort angelegt.', 'taxmod-stock'),
                'platzart'       => __('Platzart angelegt; die weiteren Felder stehen jetzt zum Ausfüllen da.', 'taxmod-stock'),
                'rollewechseln'  => __('Rolle gewechselt: der Drucker druckt jetzt die Etiketten der neuen Rolle. Zurück geht es mit «Rolle wechseln».', 'taxmod-stock'),
                'vorlagekopieren' => __('Kopiert: die neue Vorlage trägt Format, Layout und Anzahl der alten; die Platzarten bleiben bei der alten.', 'taxmod-stock'),
                'artkopieren'    => __('Kopiert: die neue Platzart trägt Felder und Teile der alten; die Plätze bleiben bei der alten.', 'taxmod-stock'),
                'ortkopieren'    => __('Kopiert: der neue Ort trägt die Felder des alten, aber nichts von dem, was dort liegt.', 'taxmod-stock'),
                'kopieren'       => __('Kopiert: das neue Material trägt Daten und Lagersicht des alten, aber keinen Bestand; Bestellnummern bitte neu eintragen.', 'taxmod-stock'),
                'reservieren'    => __('Reserviert für Produktion: alles, was dort und darunter liegt, ist reserviert.', 'taxmod-stock'),
                'freigeben'      => __('Kennzeichen entfernt: was dort liegt, ist nicht mehr reserviert.', 'taxmod-stock'),
                'verschieben'    => __('Verschoben: das Material steht jetzt in der neuen Gruppe, ihre weiteren Felder sind leer.', 'taxmod-stock'),
                'nichteinlagern' => __('Abgeschlossen ohne Lager: kein Bestand, der Grund steht am Kauf.', 'taxmod-stock'),
                'einlagern'      => __('Zurückgenommen: der Kauf kann wieder eingebucht werden.', 'taxmod-stock'),
                'aufteilen' => __('Aufgeteilt: jede Zeile ist jetzt ein Kauf mit seinem Material und kann eingebucht werden.', 'taxmod-stock'),
                'zuordnen'  => __('Zuordnung gespeichert: Material und Lagerort bleiben stehen, noch nichts eingebucht.', 'taxmod-stock'),
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
                $this->remember($info ? 'info' : 'warning', __('Erledigt, aber: die Außenabmessung passt nicht in die Innenabmessung des Ortes darüber (Passberechnung seiner Platzart).', 'taxmod-stock'));
            }

            $open = $kind === 'zuordnen' ? (int) array_key_first(array_filter(array_map('absint', (array) ($_POST['newgroup'] ?? [])))) : 0;
            $back = match ($kind) {
                'verschieben'  => ['material', ['thing' => $thing, 'view' => 'daten']],
                'kopieren'     => ['material', ['thing' => $copy, 'view' => 'bearbeiten']],
                'lager'        => ['material', ['thing' => $thing, 'view' => 'lager']],
                'umbuchen', 'storno', 'verbrauchstorno', 'inventurstorno' => ['material', ['thing' => $thing, 'view' => 'bestand']],
                'reservieren', 'reservierungstorno' => sanitize_key((string) ($_POST['back'] ?? '')) === 'bestand' && $thing > 0
                    ? ['material', ['thing' => $thing, 'view' => 'bestand']] : ['reservierungen', []],
                'wareneingang', 'aufteilen', 'zuordnen', 'nichteinlagern', 'einlagern' => ['wareneingang', ['order' => absint($_POST['order'] ?? 0)]],
                'platzart'     => ['platzarten', array_filter(['typ' => (int) (((array) ($result['refs'] ?? []))['art'] ?? 0)])],
                'artkopieren'  => ['platzarten', array_filter(['typ' => $copy])],
                'vorlagekopieren' => ['etikettenvorlagen', array_filter(['vorlage' => $copy])],
                'rollewechseln' => ['drucker', []],
                'umhaengen', 'orteverschieben', 'ort', 'reservieren', 'freigeben' => ['lagerstruktur', []],
                default        => ['material', $thing > 0 ? ['thing' => $thing, 'view' => 'bestand'] : []],
            };
        } catch (StockError $e) {
            $this->remember('error', Messages::of($e));
            $back = match ($kind) {
                'wareneingang', 'zuordnen', 'nichteinlagern', 'einlagern' => ['wareneingang', ['order' => absint($_POST['order'] ?? 0)]],
                'platzart'          => ['platzarten', []],
                'artkopieren'       => ['platzarten', array_filter(['typ' => $rowPurchase])],
                'vorlagekopieren'   => ['etikettenvorlagen', array_filter(['vorlage' => $rowPurchase])],
                'rollewechseln'     => ['drucker', []],
                'umhaengen', 'orteverschieben', 'ort', 'reservieren', 'freigeben' => ['lagerstruktur', []],
                'aufteilen'         => ['wareneingang', ['order' => absint($_POST['order'] ?? 0), 'split' => absint($_POST['purchase'] ?? 0)]],
                'lager', 'umbuchen', 'storno', 'verbrauchstorno', 'inventurstorno' => ['material', ['thing' => $thing, 'view' => $kind === 'lager' ? 'lager' : 'bestand']],
                // D-1073: refused because values would fall — named, nothing moved (his doubt 2026-11-01: «das verschieben ist problematisch»).
                'verschieben'       => ['material', ['thing' => $thing, 'view' => 'daten']],
                'verbrauch'         => ['verbrauch', ['thing' => $thing]],
                'kopieren'          => ['material', ['thing' => $thing, 'view' => 'daten']],
                'reservieren', 'reservierungstorno' => sanitize_key((string) ($_POST['back'] ?? '')) === 'bestand' && $thing > 0
                    ? ['material', ['thing' => $thing, 'view' => 'bestand']] : ['reservierungen', []],
                default             => [$kind, []],
            };
        }

        // D-1090: the split pages go back to the place that was open.
        $returnTab = sanitize_key((string) ($_POST['return_tab'] ?? ''));

        if (in_array($returnTab, ['lagerorte', 'lagerstruktur'], true)) {
            $back = [$returnTab, array_filter(['ort' => $kind === 'ortkopieren' && $copy > 0 ? $copy : absint($_POST['return_ort'] ?? 0)])];
        }

        // An Umbuchung started in the material list goes back to that very list, search and page included.
        $last = $returnTab === self::BACK_TO_LIST ? get_transient(self::LAST_LIST . get_current_user_id()) : false;

        if (is_string($last) && $last !== '') {
            wp_safe_redirect($last);
            exit;
        }

        if ($fromCockpit) {
            wp_safe_redirect(InboundPage::url(['tab' => 'arrived', 'order' => absint($_POST['order'] ?? 0)] + ($open > 0 ? ['material' => $open] : [])));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tab' => $back[0]] + $back[1] + ($open > 0 ? ['material' => $open] : []), admin_url('admin.php')));
        exit;
    }

    /**
     * D-997: the things the stock knows as one list; a group chosen adds the columns of its technical data, and a
     * row's details are its lots.
     */
    private function materialList(StockList $list, bool $branch = false): string
    {
        $tab    = $branch ? 'gruppenbestand' : 'material';
        $s      = $this->stock->structure();
        $things = $this->stock->things();
        $all    = $things->all();
        $ledger = $this->stock->ledger();
        $lager  = $this->stock->table('lager');
        $group  = absint($_GET['group'] ?? 0);

        if ($all === []) {
            return '<p>' . esc_html__('Noch kein Material. Eine Inventur oder ein Wareneingang bringt das erste.', 'taxmod-stock') . '</p>';
        }

        // D-1174: on «Gruppenbestand» the group holds the groups below it; their technical data differ, so no such columns.
        $within    = $branch && $group > 0 ? GroupStock::branch($this->stock->groupParents(), $group) : [];
        $technical = $group > 0 && ! $branch ? $this->technicalColumns($group) : [];
        $data      = $technical !== [] ? $this->stock->tableOf($group) : null;
        // D-1204: a picture per row, unless the Configuration has pictures in lists off.
        $images    = method_exists(ListPage::class, 'thumbnails') && ListPage::showsImages();
        $columns   = [...($images ? [new ListColumn('image', __('Bild', 'taxmod-stock'), fixed: true, width: '56px')] : []),
            new ListColumn('nr', __('Nr.', 'taxmod-stock'), fixed: true, width: '6em'), new ListColumn('name', __('Material', 'taxmod-stock'), fixed: true),
            new ListColumn('group', __('Gruppe', 'taxmod-stock'))];
        // D-1030: where it lies, by path and project — so a search for a project finds the parts in its box.
        $columns[] = new ListColumn('places', __('Lagerort', 'taxmod-stock'));
        $position  = 0;

        foreach ($technical as $relation => $name) {
            // D-1054: every plain field of the group can be a column; the first four are the starting set.
            $columns[] = new ListColumn('f' . $relation, $name, $position++ < 4);
        }

        $columns[] = new ListColumn('stock', __('Bestand', 'taxmod-stock'), width: '7em');
        $columns[] = new ListColumn('min', __('Mindestbestand', 'taxmod-stock'), width: '9em');
        $listId    = ($branch ? 'stock-groupstock' : 'stock-material') . ($group > 0 ? '-' . $group : '');
        $view      = ListPage::view($listId, $columns, 'name');
        $groups    = [];
        $rows      = [];
        // D-1147: nothing is drawn until a search, a filter or a group asks for it, or «Alle anzeigen».
        $waits     = $branch ? $group === 0 : ListPage::waitsForSearch($view, $group > 0 || $list->filter !== '');

        $packs = $waits ? [] : $this->stock->packsOf(array_keys($all));

        foreach ($waits ? [] : array_keys($all) as $id) {
            $node = $things->nodeOf($id);

            if ($node !== null) {
                $groups[$node] ??= $this->stock->path($node);
            }

            if ($branch ? ! isset($within[$node ?? 0]) : $group > 0 && $node !== $group) {
                continue;
            }

            $record = $things->lagerOf($id);
            $min    = $record === null ? null : $lager->first($record, $s->field('lager_min'));
            $stock  = $ledger->stockOf($id);
            $name   = $things->word($id);
            // His word 2026-11-05: «bei mehreren positionen den platz in der zusammenfassung weg lassen».
            $cells  = ['nr' => (string) $id, 'name' => $name, 'group' => $node === null ? '' : $groups[$node], 'places' => count(array_unique(array_column($this->heldAt($id, $ledger), 1))) > 1 ? '' : $this->placesOf($id, $ledger)];

            foreach (array_keys($technical) as $relation) {
                $cells['f' . $relation] = implode(', ', (array) $data?->all($id, $relation));
            }

            $rows[] = ['id' => $id, 'name' => $name, 'stock' => $stock, 'low' => $min !== null && $stock < (float) $min, 'words' => [],
                // Optional fields (Structure::OPTIONAL_FIELDS): a model without a base unit has none to group by.
                'unit' => $record === null || ! $s->hasField('lager_unit') ? '' : $lager->first($record, $s->field('lager_unit')) . '/' . ($s->hasField('lager_prefix') ? $lager->first($record, $s->field('lager_prefix')) : ''),
                'cells' => $cells + ['stock' => $this->number($stock) . (isset($packs[$id]) ? ' ' . $this->inPacks($packs[$id], $stock) : ''), 'min' => $min === null ? '' : (string) $min],
                'sort'  => ['nr' => $id, 'stock' => $stock, 'min' => $min === null ? -1.0 : (float) $min,
                    'name' => mb_strtolower((string) preg_replace('/^#\d+( · )?/u', '', $name))]];
        }

        if ($waits) {
            foreach ($things->nodes() as $node) {
                $groups[$node] ??= $this->stock->path($node);
            }
        }

        asort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        // The preset filter (with stock, below minimum) stays the page's own; search, column filters and order are the list's.
        $shown   = $view->apply((new StockList('', $list->filter))->apply($rows));
        $visible = $view->visible();
        $open    = $this->stock->viewSettings()->expanded('material');
        $url     = fn (array $change): string => $this->listUrl($tab, $list, $change + [ListPage::SHOW_ALL => ListPage::showsAll() ? '1' : '', 'f' => $view->filters(), 'orderby' => $view->orderBy === 'name' ? '' : $view->orderBy, 'order' => $view->descending ? 'desc' : '']);
        $html    = ($branch ? $this->groupTotals($rows) : '') . $this->tools($tab, $list, [
            ['filter', $this->materialFilters(), $list->filter, __('Filter', 'taxmod-stock')],
            ...($branch ? [] : [['group', ['' => __('Alle Gruppen', 'taxmod-stock')] + $groups, $group > 0 ? (string) $group : '', __('Gruppe', 'taxmod-stock')]]),
        ], ['group' => $group > 0 ? (string) $group : '', 'orderby' => $view->orderBy === 'name' ? '' : $view->orderBy, 'order' => $view->descending ? 'desc' : ''],
            __('Material, Nummer, Gruppe …', 'taxmod-stock'), $waits ? sprintf(
            /* translators: %d: number of materials */
            _n('%d Material im Lager', '%d Materialien im Lager', count($all), 'taxmod-stock'),
            count($all)
        ) : sprintf(
            /* translators: %d: number of materials */
            _n('%d Material', '%d Materialien', count($shown), 'taxmod-stock'),
            count($shown)
        ), ListPage::filterToggle() . ListPage::columnMenu($listId, $view, $url([])), $this->bulkTransferAsk() + $this->materialLabelsAsk());
        $body    = $waits ? ($branch ? '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) (count($visible) + 2)) . '">'
            . esc_html__('Links eine Materialgruppe wählen: angezeigt wird alles darin und in den Gruppen darunter.', 'taxmod-stock') . '</td></tr>'
            : ListPage::searchPrompt(count($visible), $url([ListPage::SHOW_ALL => '1']), count($all))) : ($shown === [] ? $this->noItems(count($visible)) : '');

        // «← Zur Liste» in the material view comes back to exactly this list (design 7.3).
        set_transient(self::LAST_LIST . get_current_user_id(), $url(['paged' => absint($_GET['paged'] ?? 0) > 1 ? (string) absint($_GET['paged']) : '']), DAY_IN_SECONDS);

        $paged  = absint($_GET['paged'] ?? 1);
        $places = $this->stock->places();

        $page   = ListView::page($shown, $paged, self::PER_PAGE);
        // Only the rows of this page, in one read (CD-7).
        $thumbs = $images ? ListPage::thumbnails($this->stock->titleImages(array_column($page, 'id'))) : [];

        foreach ($page as $row) {
            $id    = $row['id'];
            $link  = esc_url($this->thingUrl($id, 'daten'));
            $cells = [];

            foreach ($visible as $column) {
                $text    = $row['cells'][$column->key] ?? '';
                $cells[] = match ($column->key) {
                    'image' => ($thumbs[$id] ?? '') === '' ? '' : '<a href="' . $link . '">' . $thumbs[$id] . '</a>',
                    'nr'    => '<a href="' . $link . '">' . esc_html($text) . '</a>',
                    'name'  => '<strong><a href="' . $link . '">' . esc_html($text === '' ? '—' : $text) . '</a></strong>',
                    'stock' => '<span' . ($row['low'] ? ' style="color:#b32d2e;font-weight:600"' : '') . '>' . esc_html($text) . '</span>',
                    default => esc_html($text === '' ? '—' : $text),
                };
            }

            // His wish 2026-11-03: «vielleicht könnte man den button umbuchen schon hier anzeigen» — one lot at one place
            // moves straight from the row; with more, each lies in the details below with its own «Umbuchen».
            $held  = $this->heldAt($id, $ledger);
            $move  = count($held) === 1 ? $this->transferDialog($id, $held[0][0], $held[0][1], $held[0][2], $places, 0, true) . ' ' : '';
            // D-1147: the lots are drawn when the row opens, not with the list.
            // His wish 2026-11-06: «druck button pro positon» — the material label from the row; the modeller reads the
            // material's own words when it prints, so the rows cost no query each.
            $print = $this->materialLabel($id, (string) ($row['cells']['name'] ?? ''));
            $body .= ListPage::row(self::BULK_FORM, (string) $id, $cells, $move . ($print === '' ? '' : $print . ' ') . '<a class="button button-small" href="' . $link . '">'
                . esc_html__('Öffnen', 'taxmod-stock') . '</a>', ListPage::lazyDetails(self::DETAILS_MATERIAL, ['thing' => $id]), $open);
        }

        return $html . ListPage::table(ListPage::heads($view, $url), $body, ListPage::filterRow(self::FILTER_FORM, $view))
            . ListPage::pagination(count($shown), $paged, self::PER_PAGE, $url);
    }

    /**
     * D-1174, his word 2026-11-05: «gruppen bestand auswahl aus material tax baum knoten wird alles angezeigt unter dem knoten» —
     * the material tree on the left (the modeller's node tree, fixed on the page), on the right everything in the chosen group
     * and the groups below it, in the material list's table with its lots opened lazily.
     */
    private function groupStock(StockList $list): string
    {
        $group  = absint($_GET['group'] ?? 0);
        $group  = isset($this->stock->tree()[$group]) ? $group : 0;
        $pick   = add_query_arg(['page' => self::PAGE, 'tab' => 'gruppenbestand', 'group' => '{id}'], admin_url('admin.php'));
        // The fixed tree needs the modeller from 0.1.167; before that the groups stand in a choice list.
        $inline = version_compare((string) \Taxmod\WordPress\Plugin::VERSION, '0.1.167', '>=');
        $tree   = $inline ? $this->stock->groupChooser('taxmod_stock_group', $group, '', $pick) : '';

        $this->side = '<div class="taxmod-stock-places"><div class="taxmod-stock-places-head"><p class="taxmod-shell-title">'
            . esc_html__('Materialgruppen', 'taxmod-stock') . '</p></div>'
            . ($tree !== '' ? $tree : '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">'
                . '<input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="tab" value="gruppenbestand">'
                . '<select name="group" style="width:100%" onchange="this.form.submit()">' . $this->groupOptions($group) . '</select></form>')
            . '</div>' . $this->placeTreeScript();

        return ($group > 0 ? '<h2>' . esc_html($this->stock->path($group)) . '</h2>' : '') . $this->materialList($list, true);
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
    private function placesOf(int $thing, \TaxmodStock\Core\Ledger $ledger): string
    {
        $places = $this->stock->places();
        $out    = [];

        foreach ($ledger->lotsOf($thing) as $lot) {
            foreach ($ledger->placesOf($lot) as $place => $quantity) {
                if ($place > 0 && $quantity > 0.0) {
                    $out[$place] = $places[$place] ?? '#' . $place;
                }
            }
        }

        return implode('; ', $out);
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
            $origin   = ($amount === null ? '' : ' · ' . esc_html($amount) . ' ' . esc_html__('je Stück', 'taxmod-stock'))
                . ($purchase === null ? ' · ' . esc_html__('Inventur', 'taxmod-stock') : ' · ' . esc_html__('Kauf', 'taxmod-stock') . ' #' . esc_html($purchase));
            $date     = esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10));
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
    private static function copiesInput(string $formId, string $emptyMeans): string
    {
        $input = class_exists(\Taxmod\Core\Renderer\DialogNumber::class)
            ? \Taxmod\Core\Renderer\DialogNumber::input('copies', null, 1, 50, $formId)
            : '<input type="number" name="copies" min="1" max="50" step="1" form="' . esc_attr($formId) . '">';

        return $input . ' <span class="description">' . esc_html($emptyMeans) . '</span>';
    }

    private function lotLabel(int $thing, int $lot, string $label, string $charge): string
    {
        $s         = $this->stock->structure();
        $templates = $this->stock->labelTemplates();
        $first     = $s->hasNode('label_materials') ? $templates->firstIn($s->node('label_materials')) : 0;
        $chosen    = $first > 0 ? $templates->get($first) : null;
        $template  = $chosen === null ? [] : $templates->dialog($first, ['record' => $thing, 'values' => [self::CHARGE_FIELD => sprintf(/* translators: %s: batch number */ __('Charge: %s', 'taxmod-stock'), $charge)]]);

        return (string) apply_filters('taxmod_label_button', '', 'P', $lot, $label === '' ? '#' . $thing : $label, '', $chosen['copies'] ?? 1, $template);
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
        $s         = $this->stock->structure();
        $templates = $this->stock->labelTemplates();
        $chosen    = $s->hasNode('label_materials') ? $templates->get($templates->firstIn($s->node('label_materials'))) : null;
        $template  = $chosen === null ? [] : $templates->dialog($templates->firstIn($s->node('label_materials')), ['record' => $thing]);

        return (string) apply_filters('taxmod_label_button', '', 'M', $thing, $label === '' ? '#' . $thing : $label, '', $chosen['copies'] ?? 1, $template);
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

        $view   = sanitize_key((string) ($_GET['view'] ?? 'daten'));
        $view   = in_array($view, self::VIEWS, true) ? $view : 'daten';
        $table  = $this->stock->tableOf($node);
        $label  = (string) $table->first($thing, $s->field('label'));
        $about  = $s->hasField('description') ? (string) $table->first($thing, $s->field('description')) : '';
        $media  = $this->stock->titleImages([$thing])[$thing] ?? 0;
        $back   = $this->lastList();
        $chips  = $this->chips($thing);
        $titles = [
            'daten'   => __('Technische Daten', 'taxmod-stock'),
            'einkauf' => __('Einkauf', 'taxmod-stock'),
            'lager'   => __('Lager', 'taxmod-stock'),
            'bestand' => __('Bestand', 'taxmod-stock'),
            'verkauf' => __('Verkauf', 'taxmod-stock'),
        ];

        $html = '<div class="taxmod-stock-head" style="display:flex;gap:16px;align-items:flex-start;background:#fff;border:1px solid #c3c4c7;padding:16px;margin:8px 0 0">'
            . ($media > 0 ? (string) wp_get_attachment_image($media, [96, 96], false, ['style' => 'width:96px;height:96px;object-fit:cover;border-radius:4px']) : '<div style="width:96px;height:96px;background:#f0f0f1;border-radius:4px"></div>')
            . '<div><p style="margin:0;color:#50575e">' . esc_html(sprintf(/* translators: %d: record id of the thing */ __('Nr. %d', 'taxmod-stock'), $thing)) . ' · ' . $this->pathLinks($node) . '</p>'
            . '<h2 style="margin:4px 0;font-size:1.6em">' . esc_html($label === '' ? '—' : $label) . '</h2>'
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
        } . '</div>'
            // The foot stays at the bottom of the window: back to the list with its search and filters (design 7.3).
            . '<div class="taxmod-stock-foot" style="position:sticky;bottom:0;z-index:5;display:flex;gap:16px;align-items:center;background:#fff;border:1px solid #c3c4c7;border-top:2px solid #2271b1;padding:8px 16px;margin-top:12px">'
            . '<a href="' . esc_url($back) . '">← ' . esc_html__('Zur Liste', 'taxmod-stock') . '</a></div>';
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
        $last  = get_transient(self::LAST_LIST . get_current_user_id());

        return is_string($last) && str_starts_with($last, $plain) ? $last : $plain;
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
        $form = (string) apply_filters('taxmod_record_editor', '', $thing, $this->thingUrl($thing, 'daten'));

        if ($form === '') {
            return '<p><a class="button" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $thing], admin_url('admin.php'))) . '">'
                . esc_html__('Im Taxonomy Modeller bearbeiten', 'taxmod-stock') . '</a></p>';
        }

        return $form . '<p><a href="' . esc_url($this->thingUrl($thing, 'daten')) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a></p>';
    }

    /** Every field of the thing's node but the head's; references show the name of what they point at. */
    private function dataView(int $thing, int $node): string
    {
        $fields = $this->technicalFields($node);
        $note   = '<p><a class="button" href="' . esc_url($this->thingUrl($thing, 'bearbeiten')) . '">' . esc_html__('Bearbeiten', 'taxmod-stock') . '</a></p>';

        // The modeller draws the record as readers see it — units, parts and choices in their own words; the plain table
        // below is only for a modeller without that block.
        if (\WP_Block_Type_Registry::get_instance()->is_registered('taxmod/record')) {
            wp_enqueue_style('taxmod-record-block');

            return render_block(['blockName' => 'taxmod/record', 'attrs' => ['record' => $thing, 'rowFields' => implode(',', array_keys($fields))], 'innerBlocks' => []]) . $note;
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

        return $html . '</tbody></table>' . $note;
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

        if ($about === []) {
            return '<p>' . esc_html__('Noch kein Kauf zeigt auf dieses Material.', 'taxmod-stock') . '</p>';
        }

        $money   = $this->stock->table('money');
        $orders  = $this->stock->table('order');
        $orderOf = [];

        foreach ($orders->ids() as $order) {
            foreach ($orders->all($order, $s->field('order_lines')) as $line) {
                $orderOf[(int) $line] = $order;
            }
        }

        $orderValue = static fn (?int $order, string $key): string => $order === null || ! $s->hasField($key) ? '' : trim((string) $orders->first($order, $s->field($key)));
        $amountOf   = static function (?string $part) use ($money, $s): string {
            $amount = $part === null ? null : $money->first((int) $part, $s->field('money_amount'));

            return $amount === null ? '—' : number_format_i18n((float) $amount, 2);
        };

        $html = '<table class="widefat striped"><thead><tr><th>' . esc_html__('Kauf', 'taxmod-stock') . '</th><th>' . esc_html__('Lieferant', 'taxmod-stock') . '</th><th>'
            . esc_html__('Datum', 'taxmod-stock') . '</th><th>' . esc_html__('Menge', 'taxmod-stock') . '</th><th>' . esc_html__('Preis je Stück', 'taxmod-stock') . '</th><th>'
            . esc_html__('Gesamtpreis', 'taxmod-stock') . '</th></tr></thead><tbody>';

        foreach ($about as $id) {
            $order    = $orderOf[$id] ?? null;
            $merchant = $orderValue($order, 'order_merchant');
            $merchant = $merchant !== '' ? $merchant : $orderValue($order, 'order_platform');
            $total    = $s->hasField('purchase_total_price') ? $purchases->first($id, $s->field('purchase_total_price')) : null;
            $unit     = $purchases->first($id, $s->field('purchase_landed_price')) ?? $purchases->first($id, $s->field('purchase_unit_price'));
            $count    = (float) ($purchases->first($id, $s->field('purchase_quantity')) ?? 0);
            $each     = $unit === null ? null : $money->first((int) $unit, $s->field('money_amount'));
            // Without a total of its own, the price per piece times the quantity.
            $sum      = $total !== null ? $amountOf($total) : ($each === null || $count <= 0.0 ? '—' : number_format_i18n((float) $each * $count, 2));
            $html    .= '<tr><td>' . esc_html((string) $purchases->first($id, $s->field('label'))) . ' <span class="description">#' . esc_html((string) $id) . '</span></td>'
                . '<td>' . esc_html($merchant === '' ? '—' : $merchant) . '</td>'
                . '<td>' . esc_html(($date = substr($orderValue($order, 'order_date'), 0, 10)) === '' ? '—' : $date) . '</td>'
                . '<td>' . esc_html((string) ($purchases->first($id, $s->field('purchase_quantity')) ?? '—')) . '</td>'
                . '<td>' . esc_html($amountOf($unit)) . '</td>'
                . '<td>' . esc_html($sum) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** D-997, tab «Lager»: Standard-Lagerort, Mindestbestand, Einzelstück (and «Verwendung» where it is set up). */
    private function lagerView(int $thing): string
    {
        $s      = $this->stock->structure();
        $record = $this->stock->things()->lagerOf($thing);
        $lager  = $this->stock->table('lager');
        $value  = static fn (string $key): string => $record === null || ! $s->hasField($key) ? '' : (string) $lager->first($record, $s->field($key));
        $html   = $this->formStart('lager') . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . '<table class="form-table" role="presentation"><tbody>';

        if ($s->hasField('lager_location')) {
            $html .= '<tr><th>' . esc_html__('Standard-Lagerort', 'taxmod-stock') . '</th><td>' . $this->stock->placeChooser('location', (int) $value('lager_location')) . '</td></tr>';
        }

        $html .= '<tr><th>' . esc_html__('Mindestbestand', 'taxmod-stock') . '</th><td><input type="number" min="0" name="minimum" style="width:8em" value="' . esc_attr($value('lager_min')) . '"></td></tr>'
            . '<tr><th>' . esc_html__('Einzelstück', 'taxmod-stock') . '</th><td><input type="checkbox" name="single" value="1"' . checked($value('lager_single'), '1', false) . '></td></tr>';

        if ($s->hasField('lager_use') && $this->stock->uses() !== []) {
            $html .= '<tr><th>' . esc_html__('Verwendung', 'taxmod-stock') . '</th><td><select name="use">' . $this->options($this->stock->uses(), '—', (int) $value('lager_use')) . '</select></td></tr>';
        }

        if ($s->hasField('lager_unit') && $s->hasField('lager_prefix')) {
            $units = $this->stock->baseUnits();
            $html .= '<tr><th>' . esc_html__('Basiseinheit', 'taxmod-stock') . '</th><td><select name="prefix">' . $this->options($units['prefixes'], '—', (int) $value('lager_prefix')) . '</select> '
                . '<select name="unit">' . $this->options($units['units'], '—', (int) $value('lager_unit')) . '</select>'
                . '<p class="description">' . esc_html__('Worin das Lager zählt, z. B. milli + Liter für ml. Ohne Angabe: Stück.', 'taxmod-stock') . '</p></td></tr>';
        }

        return $html . '</tbody></table>' . $this->sourcesTable($record) . get_submit_button(__('Speichern', 'taxmod-stock')) . '</form>';
    }

    /**
     * D-1018, «Bezugsquellen»: a firm and its number per line, the existing ones and two empty lines to add. Clearing a line removes it.
     */
    private function sourcesTable(?int $record): string
    {
        $s = $this->stock->structure();

        if (! $s->hasField('lager_sources') || ! $s->hasNode('source')) {
            return '';
        }

        $firms = $this->stock->firmTree();
        $rows  = [...$this->stock->sourcesOf($record), new SourceRow(null, null, ''), new SourceRow(null, null, '')];
        $html  = '<h3>' . esc_html__('Bezugsquellen', 'taxmod-stock') . '</h3>'
            . '<p class="description">' . esc_html__('Wo es das Material gibt: Hersteller oder Händler und seine Nummer. Eine geleerte Zeile wird entfernt.', 'taxmod-stock') . '</p>'
            . '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__('Firma', 'taxmod-stock') . '</th><th>'
            . esc_html__('Nummer', 'taxmod-stock') . '</th>' . ($s->hasField('source_content') ? '<th>' . esc_html__('Packungsinhalt', 'taxmod-stock') . '</th>' : '') . '</tr></thead><tbody>';

        foreach ($rows as $i => $row) {
            $html .= '<tr><td>' . ($row->part === null ? '' : '<input type="hidden" name="sources[' . $i . '][part]" value="' . esc_attr((string) $row->part) . '">')
                . FirmChoice::field('sources[' . $i . '][firm]', $firms, $row->firm) . '</td>'
                . '<td><input type="text" class="regular-text" name="sources[' . $i . '][number]" value="' . esc_attr($row->number) . '"></td>'
                . ($s->hasField('source_content') ? '<td><input type="text" inputmode="decimal" style="width:7em" name="sources[' . $i . '][content]" value="' . esc_attr($row->content) . '" title="'
                    . esc_attr__('Wie viel eine Packung enthält, in der Basiseinheit', 'taxmod-stock') . '"></td>' : '') . '</tr>';
        }

        return $html . '</tbody></table><input type="hidden" name="has_sources" value="1">';
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

        foreach ($ledger->lotsOf($thing) as $lot) {
            $value    = $lots->first($lot, $s->field('lot_value'));
            $amount   = $value === null ? null : $money->first((int) $value, $s->field('money_amount'));
            $purchase = $lots->first($lot, $s->field('lot_purchase'));
            $worth   += $amount === null ? 0.0 : $ledger->restOf($lot) * (float) $amount;
            $where    = $ledger->placesOf($lot) ?: [$ledger->homeOf($lot) => 0.0];
            $first    = true;

            foreach ($where as $place => $quantity) {
                // D-1227: a lot's further places and its «Vorgänge» move with its first row when the list is sorted or grouped.
                $rows .= '<tr' . ($first ? ' id="taxmod-stock-lot-' . esc_attr((string) $lot) . '"' : ' class="taxmod-list-more"') . '><th scope="row" class="check-column"></th><td>' . ($first ? '#' . esc_html((string) $lot) : '') . '</td>'
                    // His wish 2026-11-08: «Bitte auch das Material anzeigen hier in der Liste».
                    . '<td>' . ($first ? esc_html($name) : '') . '</td><td>' . esc_html($places[$place] ?? '—') . $this->reservedMark($place, $flags) . '</td><td>' . esc_html($this->number($quantity)) . '</td>'
                    . '<td>' . ($first ? esc_html($amount ?? '—') : '') . '</td>'
                    . '<td>' . ($first ? esc_html($purchase === null ? __('Inventur', 'taxmod-stock') : '#' . $purchase) : '') . '</td>'
                    . '<td>' . ($first ? esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10)) : '') . '</td>'
                    . '<td style="text-align:right;white-space:nowrap">' . ($quantity > 0.0 ? $this->transferDialog($thing, $lot, $place, $quantity, $places) : '')
                    . ' ' . $this->bookOutButton(add_query_arg(['page' => self::PAGE, 'tab' => 'verbrauch', 'thing' => $thing, 'lot' => $lot, 'place' => $place], admin_url('admin.php')))
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
            ], $rows));
    }

    /**
     * D-1039: «Umbuchen» in the shared dialog — how much (all of it unless he says less) and to which place.
     *
     * @param array<int, string> $places
     */
    private function transferDialog(int $thing, int $lot, int $from, float $quantity, array $places, int $returnPlace = 0, bool $toList = false, array $merged = []): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $body = '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '"><input type="hidden" name="lot" value="' . esc_attr((string) $lot) . '">'
            // D-1181: a line standing for several lots of the same data moves from them oldest first.
            . (count($merged) > 1 ? '<input type="hidden" name="lots" value="' . esc_attr(implode(',', array_map('intval', $merged))) . '">' : '')
            . '<input type="hidden" name="from" value="' . esc_attr((string) $from) . '">'
            . self::actForm([
                ['label' => __('Menge', 'taxmod-stock'), 'control' => '<input type="text" inputmode="decimal" name="quantity" style="width:7em" value="' . esc_attr(str_replace(',', '.', rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'))) . '">',
                    'hint' => __('Weniger als alles teilt den Posten auf zwei Orte.', 'taxmod-stock')],
                ['label' => __('Nach', 'taxmod-stock'), 'control' => $this->stock->placeChooser('location', 0, '', [$from]),
                    'hint' => __('Bucht die Menge vom bisherigen Ort ab und am neuen zu, als Vorgang «Umbuchung»; «Stornieren» bei den Vorgängen nimmt es zurück.', 'taxmod-stock')],
            ]);

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ($returnPlace > 0 ? $this->returnFields('lagerorte', $returnPlace) : '')
            . ($toList ? '<input type="hidden" name="return_tab" value="' . esc_attr(self::BACK_TO_LIST) . '">' : '')
            . ListPage::dialogAction('taxmod-stock-transfer-' . $lot . '-' . $from . ($toList ? '-list' : ''), __('Umbuchen', 'taxmod-stock'), __('Umbuchen', 'taxmod-stock'), $body, 'booking', 'umbuchen', __('Umbuchen', 'taxmod-stock'), '', self::icon('ICON_TRANSFER'))
            . '</form>';
    }

    /**
     * «Umbuchen nach …» for the rows ticked: the target in the shared dialog; the fields join the bulk form.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function bulkTransferAsk(): array
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return [];
        }

        $body = self::actForm([['label' => __('Nach', 'taxmod-stock'), 'control' => $this->stock->placeChooser('location', 0, self::BULK_FORM),
                'hint' => __('Bucht alles von den angehakten Materialien, jeden Posten von jedem Ort, an den gewählten Ort um: ein Vorgang «Umbuchung», «Stornieren» bei den Vorgängen nimmt ihn zurück.', 'taxmod-stock')]])
            . '<input type="hidden" name="return_tab" value="' . esc_attr(self::BACK_TO_LIST) . '" form="' . esc_attr(self::BULK_FORM) . '">';

        return ['taxmod-stock-bulk-transfer' => [__('Umbuchen nach …', 'taxmod-stock'), '<div class="alignleft actions">' . ListPage::dialogAction('taxmod-stock-bulk-transfer', __('Umbuchen nach …', 'taxmod-stock'), __('Ausgewählte umbuchen', 'taxmod-stock'), $body, 'booking', 'sammelumbuchen', __('Umbuchen', 'taxmod-stock'), self::BULK_FORM) . '</div>']];
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

        if (! $s->hasNode('label_materials') || ! class_exists(DialogMarkup::class) || ! method_exists(LabelButton::class, 'filled')) {
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

        $printers = '<option value="">' . esc_html__('Wie beim Etikett hinterlegt', 'taxmod-stock') . '</option>';

        foreach (method_exists($templates, 'printerList') ? $templates->printerList() : [] as $id => $printer) {
            $printers .= '<option value="' . esc_attr((string) $id) . '">' . esc_html((string) ($printer['name'] ?? '#' . $id)) . '</option>';
        }

        $service = PrintService::isSetUp();
        $form    = ' form="' . esc_attr($formId) . '"';
        $field   = static fn (string $title, string $control): string => '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html($title) . '</span>' . $control . '</label>';
        $body    = $field(__('Etikett', 'taxmod-stock'), '<select name="label_choice"' . $form . '>' . $labels . '</select>')
            . ($service ? $field(__('Drucker', 'taxmod-stock'), '<select name="label_printer"' . $form . '>' . $printers . '</select>') : '')
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
            $this->remember('error', $message);
            wp_safe_redirect($url);
            exit;
        };

        if ($things === []) {
            $fail(__('Kein Material angehakt.', 'taxmod-stock'));
        }

        if ($template === null || ! $s->hasNode('label_materials') || ($template['node'] ?? 0) !== $s->node('label_materials') || ! method_exists(LabelButton::class, 'filled')) {
            $fail(__('Unbekanntes Material-Etikett.', 'taxmod-stock'));
        }

        if ($printer > 0 && ! in_array($chosen, $printers[$printer]['allows'] ?? [], true)) {
            $fail(__('In diesem Drucker liegt keine Rolle für dieses Etikett.', 'taxmod-stock'));
        }

        $names  = $this->stock->recordLabels($things);
        $count  = min(50, $asked > 0 ? $asked : max(1, (int) ($template['copies'] ?? 1)));
        $filled = [];

        $charges = $this->charges(array_values(array_filter(array_map(static fn (array $item): int => $item[1], $items))));
        $lotKind = LabelPrefix::tryFrom('P');

        foreach ($items as [$thing, $lot]) {
            $asLot  = $lot > 0 && $lotKind !== null;
            $dialog = $templates->dialog($chosen, ['record' => $thing] + ($asLot ? ['values' => [self::CHARGE_FIELD => sprintf(/* translators: %s: batch number */ __('Charge: %s', 'taxmod-stock'), $charges[$lot] ?? 'P-' . $lot)]] : []));
            if ($printer > 0) {
                $dialog['printer'] = array_diff_key($printers[$printer], ['allows' => 1, 'roll_name' => 1]);
            }
            $label = LabelContent::of($asLot ? $lotKind : LabelPrefix::from('M'), $asLot ? $lot : $thing, (string) ($names[$thing] ?? '#' . $thing), '');
            $own   = LabelButton::filled($label, array_diff_key($dialog, ['choices' => 1, 'chosen' => 1, 'printers' => 1]));
            if ($own !== null) {
                $filled[] = [$label, $own];
            }
        }

        if ($filled === []) {
            $fail(__('Das Etikett lässt sich nicht zeichnen; bitte sein Layout prüfen.', 'taxmod-stock'));
        }

        if ($toService && PrintService::isSetUp()) {
            $jobs = new PrintJobs();

            // D-1162: the copies of one material travel together, as one job with that many pages.
            foreach ($filled as [$label, $own]) {
                $jobs->queue($own->format, $label, false, $own->toJson(), $count);
            }

            $total = count($filled) * $count;
            $this->remember('success', sprintf(/* translators: %d: number of labels */ _n('%d Etikett wartet auf den Druckdienst.', '%d Etiketten warten auf den Druckdienst.', $total, 'taxmod-stock'), $total));
            wp_safe_redirect($url);
            exit;
        }

        // One label, so one size: every page shares the format and layout of the first.
        $pdf = LabelDocument::renderLayout($filled[0][1]->format, $filled[0][1]->layout, array_merge(...array_map(static fn (array $one): array => array_fill(0, $count, $one[1]->values), $filled)));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="material-etiketten-' . gmdate('Ymd-His') . '.pdf"');
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
                    . esc_html(sprintf(/* translators: %s: the reserved place, by its path */ __('Reservierung des Ortes %s', 'taxmod-stock'), $places[$holder] ?? '#' . $holder)) . '</a></li>';
            }
        }

        $button = $s->hasValue('kind_reservation') ? '<p style="text-align:right">' . $this->reserveFormDialog($thing, 'bestand') . '</p>' : '';

        return ($items === [] ? '' : '<ul style="margin:0 0 12px">' . implode('', $items) . '</ul>') . $button;
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
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $ledger  = $this->stock->ledger();
        $lots    = $this->stock->table('lot');
        $date    = $this->stock->structure()->field('lot_date');
        $choices = '';

        foreach ($this->stock->subjects() as $group => $records) {
            $choices .= '<optgroup label="' . esc_attr($group) . '">' . $this->options($records, '', $subject) . '</optgroup>';
        }

        $lotChoice = '';

        if ($thing > 0) {
            $lotChoice = '<option value="0">' . esc_html__('— kein bestimmter Posten —', 'taxmod-stock') . '</option>';

            foreach ($ledger->lotsOf($thing) as $lot) {
                if ($ledger->restOf($lot) > 0.0) {
                    $lotChoice .= '<option value="' . esc_attr((string) $lot) . '">' . esc_html('#' . $lot . ' · ' . substr((string) $lots->first($lot, $date), 0, 10) . ' · ' . __('Rest', 'taxmod-stock') . ' ' . $this->number($ledger->restOf($lot))) . '</option>';
                }
            }
        }

        $id   = 'taxmod-stock-reserve-' . $thing . '-' . $subject . '-' . $back;
        $body = '<input type="hidden" name="back" value="' . esc_attr($back) . '">'
            . ($subject > 0 ? '<input type="hidden" name="subject" value="' . esc_attr((string) $subject) . '">'
                : '<label style="display:block">' . esc_html__('Bezug', 'taxmod-stock') . '<br><select name="subject" style="width:100%" required><option value="">—</option>' . $choices . '</select></label>')
            . ($thing > 0 ? '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
                : '<span style="display:block;margin-top:.5em">' . esc_html__('Material', 'taxmod-stock') . '<br>' . $this->thingField('thing', $id . '-thing', null) . '</span>')
            . '<label style="display:block;margin-top:.5em">' . esc_html__('Menge', 'taxmod-stock') . '<br><input type="text" inputmode="decimal" name="quantity" style="width:7em" required></label>'
            . ($thing > 0 ? '<label style="display:block;margin-top:.5em">' . esc_html__('Bestandsposten', 'taxmod-stock') . '<br><select name="lot" style="width:100%">' . $lotChoice . '</select></label>'
                : '<label style="display:block;margin-top:.5em">' . esc_html__('Bestandsposten (Nr., optional)', 'taxmod-stock') . '<br><input type="number" min="1" name="lot" style="width:8em"></label>')
            . ($this->stock->structure()->hasField('transaction_deadline') ? '<label style="display:block;margin-top:.5em">' . esc_html__('Frist (optional)', 'taxmod-stock') . '<br><input type="date" name="deadline"></label>' : '')
            . '<p class="description">' . esc_html__('Senkt den verfügbaren Bestand, nicht den Bestand. Hat der Bezug schon eine offene Reservierung, kommt die Position dorthin.', 'taxmod-stock') . '</p>';

        return $this->dialogForm(ListPage::dialogAction($id, $subject > 0 ? __('Position hinzufügen', 'taxmod-stock') : __('Reservieren', 'taxmod-stock'), __('Reservieren', 'taxmod-stock'), $body, 'booking', 'reservieren', __('Reservieren', 'taxmod-stock')));
    }

    /** D-1036: the Storno of a reservation, whole or one position, asked in the shared dialog. */
    private function reservationStornoDialog(int $deal, ?int $line, string $what): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

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
                [esc_html($head['label']), esc_html($subject), esc_html($head['date']), esc_html($head['deadline'] === '' ? '—' : $head['deadline']), esc_html((string) count($head['positions']))],
                $this->reserveFormDialog(0, 'reservierungen', $head['subject']) . ' ' . $this->reservationStornoDialog($deal, null, $head['label'] . ' · ' . $subject),
                '<ul style="margin:0">' . $lines . '</ul>'
            ));
        }

        foreach ($flags->holdings($ledger) + array_fill_keys($flags->flagged(), []) as $holder => $lots) {
            $lines = '';

            foreach ($lots as $lot => $at) {
                foreach ($at as $place => $quantity) {
                    $lines .= '<li>' . $name((int) $ledger->thingOf($lot)) . ' · ' . esc_html($this->number($quantity)) . ' · ' . esc_html__('Posten', 'taxmod-stock') . ' #' . esc_html((string) $lot)
                        . ' · ' . esc_html($places[$place] ?? '#' . $place) . '</li>';
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

        return '<p>' . esc_html__('Eine Reservierung je Bezug: Projekt oder Verkauf. Ein für die Produktion reservierter Lagerort hat genau eine Reservierung, gerechnet aus dem, was dort liegt.', 'taxmod-stock') . '</p>'
            . '<p style="text-align:right">' . $this->reserveFormDialog(0, 'reservierungen') . '</p>'
            . ListPage::table([[__('Reservierung', 'taxmod-stock'), ''], [__('Bezug', 'taxmod-stock'), '14em'], [__('Datum', 'taxmod-stock'), '7em'], [__('Frist', 'taxmod-stock'), '7em'], [__('Positionen', 'taxmod-stock'), '6em']],
                $body === '' ? $this->noItems(5) : $body);
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
                '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place], admin_url('admin.php'))) . '">' . esc_html__('Zum Ort', 'taxmod-stock') . '</a>',
                $details
            );
        }

        return '<h2>' . esc_html__('Projekt-Gebinde', 'taxmod-stock') . '</h2><p>' . esc_html__('Jeder Lagerort mit Projekt gegen die Stückliste seines Projekts: was da ist, was fehlt, was zu viel ist. Gezählt wird alles im Gebinde und in den Orten darin.', 'taxmod-stock') . '</p>'
            . ListPage::table([[__('Gebinde', 'taxmod-stock'), ''], [__('Projekt', 'taxmod-stock'), '14em'], [__('Teile', 'taxmod-stock'), '5em'], [__('Stand', 'taxmod-stock'), '10em'], [__('Zu viel', 'taxmod-stock'), '6em']],
                $body === '' ? $this->noItems(5) : $body);
    }

    /** D-1036: «Stornieren» of a Vorgang, asked in the shared dialog; the Storno names who and when. */
    private function stornoDialog(int $thing, int $deal, string $what, string $booking = 'storno', ?int $line = null): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

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
        $s       = $this->stock->structure();
        $lines   = $this->stock->table('line');
        $deals   = $this->stock->table('transaction');
        $places  = $this->stock->places();
        $kinds   = [
            $s->value('kind_receipt')     => __('Wareneingang', 'taxmod-stock'),
            $s->value('kind_consumption') => __('Verbrauch', 'taxmod-stock'),
            $s->value('kind_count')       => __('Inventur', 'taxmod-stock'),
        ] + ($s->hasValue('kind_transfer') ? [$s->value('kind_transfer') => __('Umbuchung', 'taxmod-stock')] : []);
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
            $consumed = $deal !== null && $kind === $s->value('kind_consumption') && $s->hasField('line_reverses');
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
                . ($place > 0 ? ' · ' . esc_html($places[$place] ?? '#' . $place) : '')
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

    private function bookOutButton(string $url): string
    {
        return self::icon('ICON_BOOK_OUT') === '' || ! method_exists(ListPage::class, 'iconLink')
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
        $groups = $this->groupOptions();
        $html   = $this->formStart('aufteilen')
            . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '"><input type="hidden" name="purchase" value="' . esc_attr((string) $purchase) . '">'
            . ($back === InboundPage::BACK ? '<input type="hidden" name="back" value="' . esc_attr($back) . '">' : '')
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
                . '<td>' . ($this->stock->groupChooser('rows[' . $i . '][group]') ?: '<select name="rows[' . $i . '][group]">' . $groups . '</select>') . '</td>'
                . '<td><input type="text" name="rows[' . $i . '][name]"></td>'
                . '<td><input type="number" min="1" name="rows[' . $i . '][quantity]" style="width:5em"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][price]" style="width:6em"></td></tr>';
        }

        $cancel = $back === InboundPage::BACK
            ? InboundPage::url(['order' => $order])
            : add_query_arg(['page' => self::PAGE, 'tab' => 'wareneingang', 'order' => $order], admin_url('admin.php'));

        return $html . '</tbody></table><p class="submit" style="text-align:right">' . get_submit_button(__('Aufteilen', 'taxmod-stock'), 'primary', 'submit', false)
            . ' <a class="button" href="' . esc_url($cancel) . '">' . esc_html__('Abbrechen', 'taxmod-stock') . '</a></p></form>';
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
        $groups = $this->groupOptions();
        $rows   = '';

        for ($i = 0; $i < self::ROWS; $i++) {
            $rows .= '<tr><th scope="row" class="check-column"></th><td>' . $this->recordPicker('rows[' . $i . '][thing]', null, '') . '</td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][quantity]" style="width:6em"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][value]" style="width:6em"></td>'
                . '<td><input type="text" inputmode="decimal" name="rows[' . $i . '][total]" style="width:6em"></td>'
                . '<td><input type="text" name="rows[' . $i . '][note]" style="width:100%"></td>'
                . '<td style="text-align:right">' . $this->inventoryNewThing($i, $groups) . '</td></tr>';
        }

        $intro  = '<p>' . esc_html__('Gezählter Bestand ohne Kauf. Jede Zeile wird ein Bestandsposten. Wert je Stück oder Gesamtwert; der Gesamtwert wird auf die Menge umgerechnet.', 'taxmod-stock') . '</p>';
        $submit = get_submit_button(__('Inventur buchen', 'taxmod-stock'), 'primary', 'submit', false);
        $table  = ListPage::table([[__('Material', 'taxmod-stock'), ''], [__('Menge', 'taxmod-stock'), '7em'], [__('Wert je Stück', 'taxmod-stock'), '7em'],
            [__('oder Gesamtwert', 'taxmod-stock'), '7em'], [__('Hinweis', 'taxmod-stock'), '']], $rows);

        // The architecture thread 2026-11-09: the repeated rows in the list table, the act's button through the shared form block (modeller 0.2.33).
        return $this->formStart('inventur') . $intro . $table
            . (method_exists(ListPage::class, 'form') ? ListPage::form([], $submit) : '<p class="submit">' . $submit . '</p>') . '</form>';
    }

    /** D-1072 in the count: group and name asked in the row's dialog; the material is made when the count is booked. */
    private function inventoryNewThing(int $row, string $groups): string
    {
        $chooser = $this->stock->groupChooser('rows[' . $row . '][group]');
        $body    = self::actForm([
            ['label' => __('Gruppe', 'taxmod-stock'), 'control' => $chooser !== '' ? $chooser : '<select name="rows[' . esc_attr((string) $row) . '][group]" style="width:100%">' . $groups . '</select>'],
            ['label' => __('Bezeichnung', 'taxmod-stock'), 'control' => '<input type="text" name="rows[' . esc_attr((string) $row) . '][name]" style="width:100%">',
                'hint' => __('Angelegt wird mit «Inventur buchen».', 'taxmod-stock')],
        ]);

        if (! class_exists(\Taxmod\Core\Renderer\DialogMarkup::class)) {
            return '<details style="display:inline-block"><summary class="button button-small">' . esc_html__('+ Neues Material', 'taxmod-stock') . '</summary>' . $body . '</details>';
        }

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
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $body = '<p>' . esc_html__('Die Kopie bekommt alle Daten und die Lagersicht dieses Materials und öffnet sich gleich zum Bearbeiten.', 'taxmod-stock') . '</p>'
            . '<p class="description">' . esc_html__('Nicht mitkopiert: Bestand, Reservierungen und die Bestellnummern der Bezugsquellen.', 'taxmod-stock') . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-stock-copy-' . $thing, __('Kopieren', 'taxmod-stock'), __('Material kopieren', 'taxmod-stock'), $body, 'booking', 'kopieren', __('Kopieren', 'taxmod-stock'))
            . '</form>';
    }

    /** D-1073: «Verschieben» — the material into another group of the tree, asked in the shared dialog. */
    private function moveDialog(int $thing, int $node): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $chooser = $this->stock->groupChooser('group', $node);
        $field   = ($chooser !== '' ? '<span style="display:block">' . esc_html__('Neue Gruppe', 'taxmod-stock') . '<br>' . $chooser . '</span>'
            : '<label style="display:block">' . esc_html__('Neue Gruppe', 'taxmod-stock') . '<br><select name="group" style="width:100%">' . $this->groupOptions($node) . '</select></label>')
            . '<p class="description">' . esc_html__('Felder, die die neue Gruppe zusätzlich hat, kommen leer dazu. Gingen dabei Werte verloren, wird nicht verschoben.', 'taxmod-stock') . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="thing" value="' . esc_attr((string) $thing) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-stock-move-' . $thing, __('Verschieben', 'taxmod-stock'), __('Material verschieben', 'taxmod-stock'), $field, 'booking', 'verschieben', __('Verschieben', 'taxmod-stock'))
            . '</form>';
    }

    /** The groups a new thing can go into, by their path in the tree; the empty choice first. */
    private function groupOptions(int $selected = 0): string
    {
        $paths = [];

        foreach ($this->stock->tree() as $node => $row) {
            if ($row['class'] === 'Category') {
                $paths[$node] = $this->stock->path($node);
            }
        }

        asort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->options(array_filter($paths), '—', $selected);
    }

    /**
     * The orders as a list; each one opens to its purchases below, read only when he opens it.
     *
     * An order handed in with `order` (back from a refused booking) is open and loaded, whatever the filter says.
     */
    private function receiptTab(StockList $list, int $open): string
    {
        $s       = $this->stock->structure();
        $orders  = $this->stock->labels('order');
        $table   = $this->stock->table('order');
        $status  = sanitize_key((string) ($_GET['status'] ?? 'open'));
        $rows    = [];
        $states  = [];
        $words   = $this->purchaseWords();

        foreach ($orders as $id => $label) {
            $state       = $this->orderState($table->first($id, $s->field('order_status')));
            $states[$id] = $state;

            if ($id !== $open && $status !== '' && ($status === 'open' ? $state === 'received' : $state !== $status)) {
                continue;
            }

            $rows[] = ['id' => $id, 'name' => $label, 'stock' => (float) count($table->all($id, $s->field('order_lines'))), 'low' => false,
                'words' => array_merge(...array_map(static fn (string $purchase): array => $words[(int) $purchase] ?? [], $table->all($id, $s->field('order_lines'))))];
        }

        $shown = (new StockList($list->search, '', $list->orderBy, $list->descending))->apply($rows);
        $auto  = $this->stock->viewSettings()->expanded('wareneingang');
        $html  = $this->tools('wareneingang', $list, [['status', [
            'open'     => __('Nicht eingegangen', 'taxmod-stock'),
            'partial'  => __('Teilweise eingegangen', 'taxmod-stock'),
            'received' => __('Eingegangen', 'taxmod-stock'),
            ''         => __('Alle Bestellungen', 'taxmod-stock'),
        ], $status, __('Filter', 'taxmod-stock')]], [], __('Bestellung, Nummer …', 'taxmod-stock'), sprintf(
            /* translators: %d: number of orders */
            _n('%d Bestellung', '%d Bestellungen', count($shown), 'taxmod-stock'),
            count($shown)
        ));
        $body  = $shown === [] ? $this->noItems(3) : '';

        foreach ($shown as $row) {
            $id     = $row['id'];
            $loaded = $id === $open;
            $body  .= ListPage::row(self::BULK_FORM, (string) $id, [
                '<strong>' . esc_html($row['name']) . '</strong>',
                esc_html($this->stateName($states[$id])),
                esc_html((string) (int) $row['stock']),
            ], '', '<div class="taxmod-stock-positions" data-order="' . esc_attr((string) $id) . '"' . ($loaded ? ' data-loaded="1"' : '') . '>'
                . ($loaded ? $this->receiptPositions($id) : '') . '</div>', $loaded || $auto);
        }

        return $html . ListPage::table([$this->sortColumn('wareneingang', $list, 'name', __('Bestellung', 'taxmod-stock'), ''), [__('Status', 'taxmod-stock'), '12em'],
            $this->sortColumn('wareneingang', $list, 'stock', __('Positionen', 'taxmod-stock'), '8em')], $body);
    }

    /**
     * What the search finds an order by besides its name: each purchase's name, description and the name of its
     * «Modell» — «555» finds the order that holds an NE555 (his wish 2026-10-25). One read per node (CD-7).
     *
     * @return array<int, list<string>> purchase id → words
     */
    private function purchaseWords(): array
    {
        $s         = $this->stock->structure();
        $purchases = $this->stock->table('purchase');
        $model     = $s->hasField('purchase_model') ? $s->field('purchase_model') : null;
        $models    = $model === null ? [] : $this->stock->recordLabels(array_values(array_unique(array_filter(array_map(
            static fn (int $id): int => (int) $purchases->first($id, $model),
            $purchases->ids()
        )))));
        $out       = [];

        foreach ($purchases->ids() as $id) {
            $out[$id] = array_values(array_filter([
                (string) $purchases->first($id, $s->field('label')),
                $s->hasField('description') ? (string) $purchases->first($id, $s->field('description')) : '',
                $model === null ? '' : ($models[(int) $purchases->first($id, $model)] ?? ''),
            ]));
        }

        return $out;
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

        check_ajax_referer(self::THING_SEARCH);

        try {
            wp_send_json_success($this->stock->searchThings(sanitize_text_field(wp_unslash((string) ($_GET['term'] ?? ''))), 30, ! empty($_GET['groups'])));
        } catch (StockError $e) {
            wp_send_json_error(['message' => Messages::of($e)]);
        }
    }

    /** admin-post handler for the groups of the tree beside the material search (D-1011): capability → nonce → act. */
    public function thingNodes(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        check_ajax_referer(self::THING_NODES);

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

        check_ajax_referer(self::THING_BRANCH);

        try {
            wp_send_json_success($this->stock->branchThings(absint($_GET['node'] ?? 0)));
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

        check_ajax_referer(self::POSITIONS);
        $order = absint($_GET['order'] ?? 0);
        $back  = sanitize_key((string) ($_GET['back'] ?? ''));

        try {
            wp_send_json_success(['html' => $this->receiptPositions($order, $back === InboundPage::BACK ? $back : '')]);
        } catch (StockError $e) {
            wp_send_json_error(['html' => '<div class="notice notice-error inline"><p>' . esc_html(Messages::of($e)) . '</p></div>']);
        }
    }

    /** «open», «partial» or «received», read from the order's status value. */
    private function orderState(?string $value): string
    {
        $s = $this->stock->structure();

        return match ((int) $value) {
            $s->value('status_received') => 'received',
            $s->value('status_partial')  => 'partial',
            default                      => 'open',
        };
    }

    private function stateName(string $state): string
    {
        return match ($state) {
            'received' => __('eingegangen', 'taxmod-stock'),
            'partial'  => __('teilweise eingegangen', 'taxmod-stock'),
            default    => __('offen', 'taxmod-stock'),
        };
    }

    /**
     * The purchases of one order with their thing and place, as one booking form (D-995).
     *
     * @param string $back where the booking returns to: '' the Lager page, {@see InboundPage::BACK} the cockpit (D-1001)
     */
    private function receiptPositions(int $order, string $back = ''): string
    {
        $orders = $this->stock->labels('order');

        if (! isset($orders[$order])) {
            throw StockError::nothingToBook();
        }

        $plan   = $this->stock->receiptPlan($order);
        $labels = $this->stock->labels('purchase');
        $about  = $this->purchaseAbout(array_keys($plan));
        $models = $this->stock->recordLabels(array_values(array_filter(array_column($plan, 'model'))));
        $where  = $this->stock->receiptLocations($plan);
        $closing = $this->stock->canCloseWithoutStock();
        $bought  = $this->stock->table('purchase');
        $amount  = $this->stock->structure()->field('purchase_quantity');
        $html  = $this->formStart('wareneingang') . '<input type="hidden" name="order" value="' . esc_attr((string) $order) . '">'
            . ($back === '' ? '' : '<input type="hidden" name="back" value="' . esc_attr($back) . '">')
            // ⚠️ *Enter in a reason field must book, not close the first row: the browser presses the first submit button.*
            . '<button type="submit" name="booking" value="wareneingang" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px"></button>'
            . ($closing ? '<datalist id="taxmod-stock-reasons">' . implode('', array_map(static fn (string $r): string => '<option value="' . esc_attr($r) . '">', self::notStockedReasons())) . '</datalist>' : '')
            . '<p>' . esc_html__('Je Kauf das Material: vorgeschlagen ist das «Modell» des Kaufs; «Zurücksetzen» (Pfeil) gibt die Suche frei; «Neues Material» (oder eine Gruppe in der Suche) legt ein neues Material in der Gruppe an. Der Lagerort ist vorgeschlagen: der Standard-Lagerort des Materials, sonst der Ort seiner Gruppe; Gebrauchtware aus einem Zweig mit Test nach dem Wareneingang geht an den «Lagerort für Test», wenn einer eingestellt ist. Der Materialtext öffnet das Material wie der Knopf «Material». Die Menge ist die gekaufte Anzahl (bei Ware in Packungen: Packungen); geändert wird sie beim Buchen oder Speichern in den Kauf geschrieben.', 'taxmod-stock') . '</p>'
            . '<div class="tablenav top"><div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html(sprintf(
                /* translators: %d: number of purchases */
                _n('%d Kauf', '%d Käufe', count($plan), 'taxmod-stock'),
                count($plan)
            )) . '</span></div><br class="clear"></div>';
        $head  = '<tr><td class="manage-column column-cb check-column"><input type="checkbox" aria-label="' . esc_attr__('Alle wählen', 'taxmod-stock') . '"></td>'
            . '<th scope="col" class="manage-column" style="width:28%">' . esc_html__('Kauf', 'taxmod-stock') . '</th><th scope="col" class="manage-column" style="width:28%">' . esc_html__('Material', 'taxmod-stock')
            . '</th><th scope="col" class="manage-column" style="width:6em">' . esc_html__('Menge', 'taxmod-stock') . '</th><th scope="col" class="manage-column">' . esc_html__('Lagerort', 'taxmod-stock') . '</th>'
            // His word 2026-11-01: «buttons immer nach rechts».
            . '<th scope="col" class="manage-column" style="width:10em;text-align:right">' . esc_html__('Aktionen', 'taxmod-stock') . '</th></tr>';
        $html .= '<table class="wp-list-table widefat fixed striped table-view-list"><thead>' . $head . '</thead><tbody>' . ($plan === [] ? $this->noItems(5) : '');
        $actions = static fn (string $buttons): string => '<td style="text-align:right">' . $buttons . '</td>';
        $groups  = null;

        foreach ($plan as $purchase => $row) {
            $lock     = $row['lock'];
            $model    = $row['model'];
            $select   = 'taxmod-stock-choice-' . $purchase;
            $quantity = (string) $bought->first($purchase, $amount);
            $html    .= '<tr><th scope="row" class="check-column"><input type="checkbox" name="purchases[]" value="' . esc_attr((string) $purchase) . '"'
                . ($lock === null && $model !== null ? ' checked' : '') . ($lock === null ? '' : ' disabled') . '></th>'
                . '<td>' . esc_html($labels[$purchase] ?? '#' . $purchase) . ($lock === null ? '' : ' <em>(' . esc_html(Messages::lockReason($lock)) . ')</em>')
                . (($about[$purchase] ?? '') === '' ? '' : '<br><span class="description">' . esc_html($about[$purchase]) . '</span>') . '</td>';

            if ($lock !== null) {
                // Concept R3: a lot purchase is told apart first; its parts are then received like any purchase.
                $split = $lock === 'purchase_undetermined'
                    ? '<a class="button button-small" href="' . esc_url(add_query_arg(array_filter(['page' => self::PAGE, 'tab' => 'wareneingang', 'order' => $order, 'split' => $purchase, 'back' => $back]), admin_url('admin.php'))) . '">' . esc_html__('Aufteilen', 'taxmod-stock') . '</a>'
                    : '';
                // D-1058: a purchase closed without stock shows its reason and can be taken back.
                $again = match (true) {
                    $lock === 'not_stocked' => '<button type="submit" class="button button-small" name="booking" value="einlagern-' . esc_attr((string) $purchase) . '">' . esc_html__('Zurücknehmen', 'taxmod-stock') . '</button>',
                    // A lot purchase that never goes into stock needs no splitting first.
                    $lock === 'purchase_undetermined' && $closing => $this->closeControls($purchase),
                    default => '',
                };
                $html .= '<td>' . ($model === null ? esc_html('—') : $this->materialLink($purchase, $model, '#' . $model . ' · ' . ($models[$model] ?? ''))) . '</td>'
                    . '<td>' . esc_html($quantity) . '</td>'
                    . '<td>' . ($lock === 'not_stocked' ? esc_html($this->stock->notStockedReason($purchase)) : '') . '</td>'
                    . $actions(($model === null ? '' : $this->materialDialog($purchase, $model)) . $split . ' ' . $again) . '</tr>';
                continue;
            }

            // The modeller's own record chooser on the purchase's «Modell» (his word 2026-10-28: «eigentlich haben wir doch den tax chooser»).
            $html .= '<td><input type="hidden" name="model[' . esc_attr((string) $purchase) . ']" value="' . esc_attr((string) ($model ?? '')) . '">'
                . $this->recordPicker('thing[' . $purchase . ']', $model, $model === null ? '' : ($models[$model] ?? '#' . $model), $purchase) . '</td>'
                // His wish 2026-11-05: «Menge 1, geliefert wurden 40» — the quantity is corrected here, not in the purchase.
                . '<td><input type="number" min="1" step="1" style="width:5em" name="quantity[' . esc_attr((string) $purchase) . ']" value="' . esc_attr($quantity) . '"></td>'
                . '<td>' . $this->stock->placeChooser('location[' . $purchase . ']', $where[$purchase] ?? 0) . '</td>'
                . $actions(($model === null ? $this->newThingDialog($purchase, $groups ??= $this->groupOptions()) : $this->materialDialog($purchase, $model)) . ($closing ? $this->closeControls($purchase) : '')) . '</tr>';
        }

        // His word 2026-10-28: «zwischenstände speichern … ohne we» — the second button keeps the chosen material, books nothing.
        return $html . '</tbody></table><p class="submit" style="text-align:right">' . get_submit_button(__('Wareneingang buchen', 'taxmod-stock'), 'primary', 'submit', false)
            . ' <button type="submit" class="button" name="booking" value="zuordnen">' . esc_html__('Zuordnung speichern', 'taxmod-stock') . '</button></p></form>';
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
                    . '<button type="submit" class="button button-small" name="booking" value="umhaengen-' . esc_attr((string) $place) . '">' . esc_html__('Umhängen', 'taxmod-stock') . '</button> '
                    . '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $place], admin_url('admin.php')) . '#taxmod-preview-edit') . '">' . esc_html__('Bearbeiten', 'taxmod-stock') . '</a> '
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

            return '<p>' . esc_html__('Jeder Lagerort mit den Posten, die dort Bestand haben; eine Kiste gehört mit allem darin zu ihrem Projekt.', 'taxmod-stock')
                . ' <a href="' . esc_url($structure) . '">' . esc_html__('Lagerstruktur bearbeiten', 'taxmod-stock') . '</a></p>' . $table;
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
            . '<h2>' . esc_html__('Neuer Lagerort', 'taxmod-stock') . '</h2><p>'
            . '<label>' . esc_html__('Name', 'taxmod-stock') . ' <input type="text" name="name" style="width:14em"></label> '
            . '<label>' . esc_html__('liegt in', 'taxmod-stock') . ' ' . $this->stock->placeChooser('new_parent') . ' <span class="description">' . esc_html__('leer: oberste Ebene', 'taxmod-stock') . '</span></label> '
            . '<label><input type="checkbox" name="movable" value="1"> ' . esc_html__('beweglich (Kiste, Karton)', 'taxmod-stock') . '</label> '
            . ($s->hasField('location_reserved') ? '<label><input type="checkbox" name="reserved" value="1"> ' . esc_html__('reserviert für Produktion', 'taxmod-stock') . '</label> ' : '')
            . ($projects === [] ? '' : '<label>' . esc_html__('Projekt', 'taxmod-stock') . ' <select name="project">' . $this->options($projects, '—') . '</select></label> ')
            . '<button type="submit" class="button button-primary" name="booking" value="ort">' . esc_html__('Anlegen', 'taxmod-stock') . '</button></p></form>';
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
        $place = absint($_GET['ort'] ?? 0);
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
        $this->side = '<div class="taxmod-stock-places">'
            . '<div class="taxmod-stock-places-head"><p class="taxmod-shell-title">' . esc_html__('Lagerorte', 'taxmod-stock') . '</p>'
            . ($edit ? '<label class="button button-small" for="taxmod-stock-new-place-0">' . esc_html__('+ Neuer Lagerort', 'taxmod-stock') . '</label>' : '') . '</div>'
            . ($edit ? '<div class="taxmod-stock-places-top" data-taxmod-drop-record="0">' . esc_html__('— oberste Ebene —', 'taxmod-stock') . '</div>' : '')
            . $left . '</div>' . $this->placeTreeScript();

        return '<div class="taxmod-stock-split">' . $right . '</div>'
            // The opener of «Neuer Lagerort» stands in the tree's box; its dialog stays here, outside the sticky column.
            . ($edit ? '<div class="taxmod-stock-drop-dialogs">' . $this->newPlaceDialog(0, $tab) . '</div>' : '')
            . $this->dropDialogs($tab, $place, $tree);
    }

    /**
     * D-1092: in the narrow column a long name ends in «…» and shows whole on hover; the chosen place is scrolled into view
     * inside the tree's box, not the page.
     */
    private function placeTreeScript(): string
    {
        return '<script>(function(){function run(){var box=document.querySelector(".taxmod-stock-places");if(!box){return;}'
            . 'box.querySelectorAll(".taxmod-tree-label").forEach(function(l){var row=l.closest(".taxmod-tree-row");if(row){row.title=row.textContent.trim();}});'
            . 'var on=box.querySelector(".taxmod-chooser-row input[type=radio]:checked"),list=box.querySelector(".taxmod-chooser-tree");if(!on||!list){return;}'
            . 'var row=on.closest(".taxmod-tree-row"),r=row.getBoundingClientRect(),l=list.getBoundingClientRect(),head=box.querySelector(".taxmod-tree-search"),top=l.top+(head?head.offsetHeight:0);'
            . 'if(r.top<top||r.bottom>l.bottom){list.scrollTop+=r.top-top-(l.bottom-top)/3;}}'
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
            'pick_url'     => add_query_arg(['page' => self::PAGE, 'tab' => $tab, 'ort' => '{id}'], admin_url('admin.php')),
            'drag'         => true,
            'suffix'       => $suffix,
        ]);
    }

    /** The head of the right side: the place's name, where it lies and its marks. */
    private function placeHead(int $place, LocationTree $tree): string
    {
        $parent  = $tree->parentOf($place);
        $project = $tree->projectOf($place);
        $marks   = ($tree->isMovable($place) ? ' <span class="description">(' . esc_html__('beweglich', 'taxmod-stock') . ')</span>' : '')
            . $this->reservedMark($place, $this->stock->reservedPlaces());

        return '<h2 style="margin-top:0">' . esc_html((string) (array_reverse(explode(' › ', $tree->pathOf($place)))[0])) . $marks . '</h2>'
            . '<p>' . esc_html__('Liegt in', 'taxmod-stock') . ': ' . esc_html($parent > 0 ? $tree->pathOf($parent) : __('— oberste Ebene —', 'taxmod-stock'))
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
        $form    = (string) apply_filters('taxmod_record_editor', '', $place, $back);
        $label   = (string) apply_filters('taxmod_label_button', '', ...$this->placeLabel($place, $tree));
        $facts = [
            [__('Beweglich (Kiste, Karton)', 'taxmod-stock'), $tree->isMovable($place) ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')],
            [__('Unterorte', 'taxmod-stock'), (string) (count($below) - 1)],
        ];
        $table = '';

        foreach ($facts as [$name, $value]) {
            $table .= '<tr><th scope="row" style="width:14em">' . esc_html($name) . '</th><td>' . esc_html($value) . '</td></tr>';
        }

        $html = $this->placeHead($place, $tree)
            . '<div class="taxmod-stock-place-actions" style="text-align:right;margin-bottom:12px">'
            . ($form === '' ? '<a class="button" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $place], admin_url('admin.php'))) . '">' . esc_html__('Im Taxonomy Modeller bearbeiten', 'taxmod-stock') . '</a> ' : '')
            // D-1224, his word 2026-11-07: «zur bestandsansicht wechseln» — what lies here and below, in «Platzbestand».
            . '<a class="button" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'lagerorte', 'ort' => $place], admin_url('admin.php'))) . '">' . esc_html__('Zur Bestandsansicht', 'taxmod-stock') . '</a> '
            . $this->movePlaceDialog($place, $tree) . ' '
            . $this->newPlaceDialog($place, 'lagerstruktur') . ' '
            . $this->copyPlaceDialog($place, $tree, count($below) - 1) . ' '
            . $this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place)
            . $this->reserveDialog($place, $flags->isFlagged($place), $all) . '</form>'
            . ($label === '' ? '' : ' ' . $label) . '</div>'
            . ($form === '' ? '<table class="widefat striped" style="max-width:40em">' . $table . '</table>' : $form)
            . $this->childList($place, $tree, $rows);

        if ($tree->isMovable($place) && $this->stock->relocates()) {
            $moves = $this->stock->relocationsOf($place);
            $where = static fn (int $p): string => $p > 0 ? $tree->pathOf($p) : __('— oberste Ebene —', 'taxmod-stock');
            $html .= '<h3>' . esc_html__('Umlagerungen', 'taxmod-stock') . '</h3>' . ($moves === []
                ? '<p class="description">' . esc_html__('Noch keine.', 'taxmod-stock') . '</p>'
                : '<ul>' . implode('', array_map(static fn (array $m): string => '<li>' . esc_html(substr($m['date'], 0, 10) . ': ' . $where($m['from']) . ' → ' . $where($m['to'])) . '</li>', $moves)) . '</ul>');
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
        [$template, $copies] = $this->stock->labelTemplates()->forPlace($this->stock->placeTypes(), $type, $movable);

        return [$movable ? 'K' : 'O', $place, $tree->titleOf($place), implode(' · ', array_filter([
            $tree->shortOf($place),
            $project === '' ? '' : __('Projekt', 'taxmod-stock') . ': ' . $project,
            $parent,
        ])), $copies, $template === null && $instead === 0 ? [] : $this->stock->labelTemplates()->dialog($instead > 0 ? $instead : $this->stock->placeTypes()->labelTemplateOf($type), [
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

        if ($children === [] || ! method_exists(ListPage::class, 'dialogAction')) {
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
            ], '<a class="button button-small" href="' . $link . '">' . esc_html__('Öffnen', 'taxmod-stock') . '</a>', $this->childDetails($child, $tree));
        }

        $count = sprintf(/* translators: %d: number of places */ _n('%d Unterort', '%d Unterorte', count($children), 'taxmod-stock'), count($children));
        $bar   = str_replace(
            ['name="action" id="' . self::CHILD_FORM . '-action"', '</form>'],
            ['name="bulk" id="' . self::CHILD_FORM . '-action"', '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
                . '<input type="hidden" name="return_tab" value="lagerstruktur"><input type="hidden" name="return_ort" value="' . esc_attr((string) $place) . '"></form>'],
            self::bulkBar(self::CHILD_FORM, $count, '', $this->childLabelDialog() + $this->childMoveDialog($place))
        );

        return '<h3 style="margin-top:1.5em">' . esc_html__('Unterorte', 'taxmod-stock') . '</h3>' . $bar
            . ListPage::table([[__('Bezeichnung', 'taxmod-stock')], [__('Platzart', 'taxmod-stock'), '12em'], [__('Beweglich', 'taxmod-stock'), '7em'],
                [__('Unterorte', 'taxmod-stock'), '7em'], [__('Posten', 'taxmod-stock'), '6em']], $body);
    }

    /**
     * D-1224, his word 2026-11-07: «wo sind denn die massen aktionen und das drucken» — the ticked lots of «Platzbestand»
     * reach «Etiketten drucken …» (their materials' labels) and «Umbuchen nach …» (each lot whole), in the shared bulk bar.
     */
    private function placeLotsBar(int $place, int $count): string
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
            self::bulkBar(self::PLACE_FORM, $text, '', $label + $move)
        );
    }

    /** A child's row details: what its label would say, so the bulk print holds no surprise. */
    private function childDetails(int $child, LocationTree $tree): string
    {
        [$prefix, $id, $title, $subtitle] = $this->placeLabel($child, $tree);

        return '<p class="description" style="margin:.5em 0 0">' . esc_html__('Etikett', 'taxmod-stock') . ': '
            . esc_html($prefix . '-' . $id . ' · ' . $title . ($subtitle === '' ? '' : ' · ' . $subtitle)) . '</p>';
    }

    /** «Etiketten drucken …» for the ticked Unterorte: the format, then the print service or one PDF with a page per label. */
    /** @return array<string, array{0: string, 1: string}> */
    private function childLabelDialog(): array
    {
        if (! class_exists(LabelFormat::class) || ! class_exists(DialogMarkup::class)) {
            return [];
        }

        $options = '';

        foreach (LabelFormat::shipped() as $format) {
            $options .= '<option value="' . esc_attr($format->key) . '"' . selected($format->key, 'holder62', false) . '>'
                . esc_html(PrintTab::formatName($format) . ' · ' . PrintTab::size($format)) . '</option>';
        }

        $service = PrintService::isSetUp();
        $form    = ' form="' . esc_attr(self::CHILD_FORM) . '"';
        $body    = '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Format', 'taxmod-stock') . '</span>'
            . '<select name="format"' . $form . '>' . $options . '</select></label>'
            // D-1152: empty means each place's own number — 2 for a box, 1 otherwise; a number holds for every ticked place.
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Anzahl je Ort', 'taxmod-stock') . '</span>'
            . self::copiesInput(self::CHILD_FORM, __('leer: 2 bei beweglichen, sonst 1', 'taxmod-stock')) . '</label>'
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
        $body   = '<label style="display:block">' . esc_html__('Nach', 'taxmod-stock') . '<br>' . $this->stock->placeChooser('children_parent', $place, self::CHILD_FORM, $below)
            . ' <span class="description">' . esc_html__('leer: oberste Ebene', 'taxmod-stock') . '</span></label>'
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
        $format = class_exists(LabelFormat::class) ? (LabelFormat::shipped()[sanitize_key((string) ($_POST['format'] ?? ''))] ?? null) : null;
        $tree   = $this->stock->locationTree();
        $table  = $this->stock->table('location');
        $labels = [];
        $filled = [];
        $asked  = absint($_POST['copies'] ?? 0);
        // D-1153: a place whose type names a template prints from it; the chosen format holds for the rest.
        $fills  = method_exists(LabelButton::class, 'filled');
        $this->contentsOf(array_values(array_filter($places, [$table, 'has'])));

        foreach ($places as $place) {
            if ($table->has($place)) {
                [$prefix, $id, $title, $subtitle, $copies, $template] = $this->placeLabel($place, $tree);
                $label = LabelContent::of(LabelPrefix::from($prefix), $id, $title, $subtitle);
                $own   = $fills ? LabelButton::filled($label, $template) : null;

                // D-1162: the copies travel together, as one job with that many pages.
                $count = min(50, $asked > 0 ? $asked : $copies);

                if ($own === null) {
                    $labels[] = [$label, $count];
                } else {
                    $filled[] = [$label, $own, $count];
                }
            }
        }

        if (($format === null && $labels !== []) || ($labels === [] && $filled === [])) {
            $this->remember('error', $labels === [] && $filled === [] ? __('Kein Ort angehakt.', 'taxmod-stock') : __('Etiketten brauchen den Taxonomy Modeller mit Druck und ein bekanntes Format.', 'taxmod-stock'));
            wp_safe_redirect($url);
            exit;
        }

        if ($toService && PrintService::isSetUp()) {
            $jobs = new PrintJobs();

            foreach ($labels as [$label, $count]) {
                $jobs->queue($format, $label, false, null, $count);
            }

            foreach ($filled as [$label, $own, $count]) {
                $jobs->queue($own->format, $label, false, $own->toJson(), $count);
            }

            $count = array_sum(array_column($labels, 1)) + array_sum(array_column($filled, 2));
            $this->remember('success', sprintf(/* translators: %d: number of labels */ _n('%d Etikett wartet auf den Druckdienst.', '%d Etiketten warten auf den Druckdienst.', $count, 'taxmod-stock'), $count));
            wp_safe_redirect($url);
            exit;
        }

        // One PDF has one page size: labels from different templates, or mixed with the chosen format, go to the service instead.
        $kinds = array_unique(array_map(static fn (array $pair): string => $pair[1]->format->key . '|' . $pair[1]->layout->toJson(), $filled));

        if ($filled !== [] && ($labels !== [] || count($kinds) > 1)) {
            $this->remember('error', __('Die angehakten Orte haben verschiedene Etikettenvorlagen; ein PDF hat nur eine Größe. Bitte getrennt drucken oder den Druckdienst nehmen.', 'taxmod-stock'));
            wp_safe_redirect($url);
            exit;
        }

        if ($filled !== []) {
            $pdf = LabelDocument::renderLayout($filled[0][1]->format, $filled[0][1]->layout, array_merge(...array_map(static fn (array $one): array => array_fill(0, $one[2], $one[1]->values), $filled)));
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="etiketten-' . gmdate('Ymd-His') . '.pdf"');
            header('Content-Length: ' . (string) strlen($pdf));
            echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
            exit;
        }

        $pdf = LabelDocument::render($format, array_merge(...array_map(static fn (array $one): array => array_fill(0, $one[1], $one[0]), $labels)));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="etiketten-' . gmdate('Ymd-His') . '.pdf"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
        exit;
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
            $form = (string) apply_filters('taxmod_record_editor', '', $chosen, $back);

            return $html . '<div style="text-align:right;margin-bottom:12px">' . $this->copyTypeDialog($chosen, $types->nameOf($chosen)) . '</div>'
                . '<h3>' . esc_html($types->nameOf($chosen)) . '</h3>'
                . ($form === '' ? '<p><a class="button" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $chosen], admin_url('admin.php'))) . '">' . esc_html__('Im Taxonomy Modeller bearbeiten', 'taxmod-stock') . '</a></p>' : $form)
                . '<p><a href="' . esc_url($list) . '">' . esc_html__('← alle Platzarten', 'taxmod-stock') . '</a></p>';
        }

        $places = $this->stock->table('location');
        $used   = [];

        if ($s->hasField('location_place_type')) {
            foreach ($places->ids() as $place) {
                $type = (int) $places->first($place, $s->field('location_place_type'));
                $used[$type] = ($used[$type] ?? 0) + 1;
            }
        }

        $body = '';

        foreach ($types->names() as $type => $name) {
            $parts = implode(', ', array_map(static fn (array $part): string => $part[1] . ' × ' . $types->nameOf($part[0]), $types->partsOf($type)));
            $body .= '<tr><th scope="row" class="check-column"></th>'
                . '<td><a href="' . esc_url(add_query_arg('typ', $type, $list)) . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td>' . esc_html($types->isMovable($type) ? __('ja', 'taxmod-stock') : __('nein', 'taxmod-stock')) . '</td>'
                . '<td>' . esc_html($parts === '' ? '—' : $parts) . '</td>'
                . '<td>' . esc_html((string) ($used[$type] ?? 0)) . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $this->copyTypeDialog($type, $name) . ' <a class="button" href="' . esc_url(add_query_arg('typ', $type, $list)) . '">' . esc_html__('Bearbeiten', 'taxmod-stock') . '</a></td></tr>';
        }

        $dialog = method_exists(ListPage::class, 'dialogAction')
            ? $this->formStart('platzart') . ListPage::dialogAction(
                'taxmod-stock-new-place-type',
                __('+ Neue Platzart', 'taxmod-stock'),
                __('Neue Platzart', 'taxmod-stock'),
                '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br><input type="text" name="name" style="width:100%" required></label>',
                'booking',
                'platzart',
                __('Anlegen', 'taxmod-stock')
            ) . '</form>'
            : '';

        return $html . '<div style="text-align:right;margin-bottom:12px">' . $dialog . '</div>'
            . ListPage::table([[__('Platzart', 'taxmod-stock'), ''], [__('Beweglich', 'taxmod-stock'), '7em'], [__('Teile', 'taxmod-stock'), ''], [__('Plätze', 'taxmod-stock'), '5em']], $body === '' ? $this->noItems(5) : $body);
    }

    /**
     * D-1153, his word 2026-11-04: «die etiketten bekommen dann einfach eine detailansicht oder» — the labels in a list by
     * category (D-1160); one opened shows its record in the modeller's editor, the modeller's preview and its label button.
     */
    private function labelTemplatesPage(int $chosen): string
    {
        $s    = $this->stock->structure();
        $html = '<h2>' . esc_html__('Etiketten', 'taxmod-stock') . '</h2>';

        if (! $s->hasNode('label_template')) {
            return $html . '<p class="description">' . esc_html__('Das Modell kennt noch keine Etiketten.', 'taxmod-stock') . '</p>';
        }

        $templates = $this->stock->labelTemplates();
        $types     = $this->stock->placeTypes();
        $list      = add_query_arg(['page' => self::PAGE, 'tab' => 'etikettenvorlagen'], admin_url('admin.php'));
        $nameOf    = static fn (array $t): string => trim($t['category'] . ' · ' . $t['name'], ' ·');

        if ($chosen > 0) {
            $template = $templates->get($chosen);
            $name     = $template === null ? '#' . $chosen : $nameOf($template);
            $back     = add_query_arg('vorlage', $chosen, $list);
            $form     = (string) apply_filters('taxmod_record_editor', '', $chosen, $back);

            return $html . '<div style="text-align:right;margin-bottom:12px">' . $this->copyTemplateDialog($chosen, $template['name'] ?? $name) . '</div>'
                . '<h3>' . esc_html($name) . ' <span class="description" style="font-weight:normal">· Id: ' . esc_html((string) $chosen) . '</span></h3>'
                . $this->templatePreview($chosen, $template)
                . ($form === '' ? '<p><a class="button" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $chosen], admin_url('admin.php'))) . '">' . esc_html__('Im Taxonomy Modeller bearbeiten', 'taxmod-stock') . '</a></p>' : $form)
                . '<p><a href="' . esc_url($list) . '">' . esc_html__('← alle Etiketten', 'taxmod-stock') . '</a></p>';
        }

        $used = [];

        foreach (array_keys($types->names()) as $type) {
            $template = $types->labelTemplateOf($type);

            if ($template > 0) {
                $used[$template][] = $types->nameOf($type);
            }
        }

        $all = $templates->all();
        uasort($all, static fn (array $a, array $b): int => strnatcasecmp($nameOf($a), $nameOf($b)));
        $body = '';

        foreach ($all as $id => $template) {
            $link  = esc_url(add_query_arg('vorlage', $id, $list));
            $body .= '<tr><th scope="row" class="check-column"></th>'
                . '<td>' . esc_html($template['category'] === '' ? '—' : $template['category']) . '</td>'
                . '<td><a href="' . $link . '"><strong>' . esc_html($template['name']) . '</strong></a></td>'
                . '<td>' . esc_html($template['format']['w'] . ' × ' . $template['format']['h'] . ' mm · ' . $template['format']['key']) . '</td>'
                . '<td>' . esc_html(implode(', ', $used[$id] ?? []) ?: '—') . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $this->copyTemplateDialog($id, $template['name']) . ' <a class="button" href="' . $link . '">' . esc_html__('Öffnen', 'taxmod-stock') . '</a></td></tr>';
        }

        return $html . ListPage::table([[__('Kategorie', 'taxmod-stock'), '14em'], [__('Etikett', 'taxmod-stock'), ''], [__('Größe', 'taxmod-stock'), '16em'], [__('Platzarten', 'taxmod-stock'), '']], $body === '' ? $this->noItems(4) : $body);
    }

    /**
     * The modeller's preview of the label (D-1161) beside its record, and its label button as test print.
     *
     * D-1154, his word 2026-11-04: «erst schauen ob etikett benutz und dann ersten besten nehmen» — the first place whose type uses
     * the label, read by the modeller from the record (D-1160); without one the examples written in the layout.
     *
     * @param array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string}|null $template
     */
    private function templatePreview(int $id, ?array $template): string
    {
        if ($template === null) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Das Etikett braucht Druckformat, Breite, Höhe und ein Layout.', 'taxmod-stock') . '</p></div>';
        }

        if (! has_filter('taxmod_label_preview')) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Die Vorschau braucht den Taxonomy Modeller ab 0.1.160.', 'taxmod-stock') . '</p></div>';
        }

        $place = $this->firstPlaceWith($id);
        $label = LabelTemplates::forButton($template);

        if ($place > 0) {
            [$prefix, $code, $title, $subtitle, , $own] = $this->placeLabel($place, $this->stock->locationTree());
            $label += ['values' => ['title' => $title, 'subtitle' => $subtitle] + (array) ($own['values'] ?? []), 'code' => $prefix . '-' . $code];
            $button = (string) apply_filters('taxmod_label_button', '', $prefix, $code, $title, $subtitle, $template['copies'], $own);
            $source = '<p class="description">' . esc_html(sprintf(/* translators: %s: the place shown */ __('Platz: %s', 'taxmod-stock'), $this->stock->locationTree()->pathOf($place))) . '</p>';
        } else {
            try {
                $examples = LabelLayout::fromJson($template['layout'])->examples();
            } catch (\InvalidArgumentException) {
                $examples = [];
            }
            $button = (string) apply_filters('taxmod_label_button', '', 'O', 12345, (string) ($examples['title'] ?? $template['name']), (string) ($examples['subtitle'] ?? ''), $template['copies'],
                $this->stock->labelTemplates()->dialog($id, ['values' => $examples]));
            $source = '<p class="description">' . esc_html__('Noch keine Platzart nutzt dieses Etikett: Testdruck mit den Beispielwerten.', 'taxmod-stock') . '</p>';
        }

        return '<div style="margin-bottom:12px">' . (string) apply_filters('taxmod_label_preview', '', $label, $place) . $source
            . ($button === '' ? '' : '<div style="text-align:right">' . $button . '</div>') . '</div>';
    }

    /** D-1154: the first place (by id) whose place type prints with this template; 0 where none does. */
    private function firstPlaceWith(int $template): int
    {
        $s = $this->stock->structure();

        if (! $s->hasField('location_place_type')) {
            return 0;
        }

        $types  = $this->stock->placeTypes();
        $places = $this->stock->table('location');
        $ids    = $places->ids();
        sort($ids);

        foreach ($ids as $place) {
            $type = (int) $places->first($place, $s->field('location_place_type'));

            if ($type > 0 && $types->labelTemplateOf($type) === $template) {
                return $place;
            }
        }

        return 0;
    }

    /** D-1130: «Kopieren» of a label template — its name proposed with «(Kopie)», in the shared dialog. */
    private function copyTemplateDialog(int $template, string $name): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br><input type="text" name="name" value="'
            . esc_attr(sprintf(/* translators: %s: the name of the original template */ __('%s (Kopie)', 'taxmod-stock'), $name)) . '" style="width:100%" required></label>'
            . '<p class="description">' . esc_html__('Die Kopie trägt Format, Layout und Anzahl dieser Vorlage. Platzarten, die sie nutzen, bleiben bei der alten.', 'taxmod-stock') . '</p>';

        return $this->formStart('etikettenvorlagen')
            . ListPage::dialogAction('taxmod-stock-copy-template-' . $template, __('Kopieren', 'taxmod-stock'), __('Etikettenvorlage kopieren', 'taxmod-stock'), $body, 'booking', 'vorlagekopieren-' . $template, __('Kopieren', 'taxmod-stock'))
            . '</form>';
    }

    /**
     * D-1192: the printers under Druck — the roll each holds and the labels it allows, «Rolle wechseln» in the shared dialog, each
     * printer and roll opened in the record editor.
     */
    private function printersPage(): string
    {
        $s    = $this->stock->structure();
        $html = '<h2>' . esc_html__('Drucker', 'taxmod-stock') . '</h2>';

        if (! $s->hasNode('label_printer') || ! $s->hasNode('label_roll')) {
            return $html . '<p class="description">' . esc_html__('Das Modell kennt noch keine Drucker und Rollen.', 'taxmod-stock') . '</p>';
        }

        $templates = $this->stock->labelTemplates();
        $active    = $templates->printerList();
        $rolls     = $this->stock->rolls();
        // His word 2026-11-07: «warum kommt bei den drucker einstellung der tax und nicht eine detailseite ?» — a printer or a roll
        // opens here, like a place does under Lagerstruktur.
        $open      = static fn (int $record): string => esc_url(add_query_arg(['page' => self::PAGE, 'tab' => 'drucker', 'satz' => $record], admin_url('admin.php')));
        $chosen    = absint($_GET['satz'] ?? 0);
        $printers  = $this->stock->labels('label_printer');

        if ($chosen > 0 && (isset($printers[$chosen]) || isset($rolls[$chosen]))) {
            return $this->printerDetails($chosen, $printers, $rolls, $active, $templates);
        }
        $body      = '';

        $setup = $s->hasField('printer_setup') ? $this->stock->table('label_printer') : null;

        foreach ($printers as $id => $name) {
            $printer = $active[$id] ?? null;
            $howto   = $setup === null ? '' : trim((string) $setup->first($id, $s->field('printer_setup')));
            $allowed = $printer === null ? [] : array_map(static fn (int $t): string => (string) ($templates->get($t)['name'] ?? '#' . $t), $printer['allows']);
            $body   .= '<tr><th scope="row" class="check-column"></th>'
                . '<td><a href="' . $open($id) . '"><strong>' . esc_html($name) . '</strong></a>'
                . ($howto === '' ? '' : '<details><summary>' . esc_html__('Einrichtung', 'taxmod-stock') . '</summary><div>' . esc_html($howto) . '</div></details>') . '</td>'
                . '<td>' . esc_html($printer === null ? __('— (Warteschlange oder Rolle fehlt)', 'taxmod-stock') : $printer['queue']) . '</td>'
                . '<td>' . esc_html($printer['roll_name'] ?? '—') . '</td>'
                . '<td>' . esc_html(implode(', ', array_unique($allowed)) ?: '—') . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $this->changeRollDialog($id, $name, $rolls) . ' <a class="button" href="' . $open($id) . '">' . esc_html__('Öffnen', 'taxmod-stock') . '</a></td></tr>';
        }

        $rollBody = '';
        foreach ($rolls as $id => $name) {
            $rollBody .= '<tr><th scope="row" class="check-column"></th><td><a href="' . $open($id) . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td style="text-align:right"><a class="button" href="' . $open($id) . '">' . esc_html__('Öffnen', 'taxmod-stock') . '</a></td></tr>';
        }

        // D-1201, his word «was wir pro drucker etikett machen müssen müssen wir irgendwo speichern als anleitung»: the steps for a
        // new label stand here; what is particular to one printer stands in its own «Einrichtung».
        $help = '<details class="taxmod-help" style="margin:0 0 1em"><summary><strong>' . esc_html__('Neues Etikett einrichten', 'taxmod-stock') . '</strong></summary><ol>'
            . '<li>' . esc_html__('Unter Etiketten das Etikett anlegen: Größe, Rolle, Anzahl, Layout.', 'taxmod-stock') . '</li>'
            . '<li>' . esc_html__('Unter Rollen das Etikett bei der Rolle eintragen, auf der es gedruckt wird.', 'taxmod-stock') . '</li>'
            . '<li>' . esc_html__('Kennt der Drucker die Größe nicht nach Millimetern, die Größe am Drucker anlegen und unter «Seitengrößen» als «BreitexHöhe=Name» eintragen — wie, steht in seiner Einrichtung.', 'taxmod-stock') . '</li>'
            . '<li>' . esc_html__('Testdruck aus dem Etikett; verschoben oder blass: Versatz, Zeilenlänge oder Layout anpassen.', 'taxmod-stock') . '</li>'
            . '</ol></details>';

        return $html . $help . ListPage::table([[__('Drucker', 'taxmod-stock'), '14em'], [__('Warteschlange', 'taxmod-stock'), '12em'], [__('Eingelegte Rolle', 'taxmod-stock'), '14em'], [__('Erlaubte Etiketten', 'taxmod-stock'), '']], $body === '' ? $this->noItems(4) : $body)
            . '<h2>' . esc_html__('Rollen', 'taxmod-stock') . '</h2>'
            . '<p class="description">' . esc_html__('Eine Rolle nennt ihre Breite und die Etiketten, die auf ihr gedruckt werden dürfen.', 'taxmod-stock') . '</p>'
            . ListPage::table([[__('Rolle', 'taxmod-stock'), '']], $rollBody === '' ? $this->noItems(1) : $rollBody);
    }

    /**
     * The detail page of one printer or roll: its own fields in the record editor, and for a printer its loaded roll, the
     * labels that roll allows, «Rolle wechseln» and its «Einrichtung» (D-1201).
     *
     * @param array<int, string>               $printers
     * @param array<int, string>               $rolls
     * @param array<int, array<string, mixed>> $active
     */
    private function printerDetails(int $record, array $printers, array $rolls, array $active, LabelTemplates $templates): string
    {
        $s       = $this->stock->structure();
        $list    = add_query_arg(['page' => self::PAGE, 'tab' => 'drucker'], admin_url('admin.php'));
        $back    = add_query_arg(['satz' => $record], $list);
        $form    = (string) apply_filters('taxmod_record_editor', '', $record, $back);
        $isRoll  = isset($rolls[$record]);
        $name    = $isRoll ? $rolls[$record] : $printers[$record];
        $facts   = '';
        $actions = '';

        if ($isRoll) {
            $holders = array_filter($active, static fn (array $p): bool => ($p['roll_name'] ?? '') === $name);
            $facts  .= '<tr><th scope="row" style="width:14em">' . esc_html__('Eingelegt in', 'taxmod-stock') . '</th><td>'
                . esc_html(implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $holders)) ?: '—') . '</td></tr>';
        } else {
            $printer = $active[$record] ?? null;
            $allowed = $printer === null ? [] : array_unique(array_map(static fn (int $t): string => (string) ($templates->get($t)['name'] ?? '#' . $t), $printer['allows']));
            $howto   = $s->hasField('printer_setup') ? trim((string) $this->stock->table('label_printer')->first($record, $s->field('printer_setup'))) : '';
            $facts  .= '<tr><th scope="row" style="width:14em">' . esc_html__('Eingelegte Rolle', 'taxmod-stock') . '</th><td>' . esc_html($printer['roll_name'] ?? '—') . '</td></tr>'
                . '<tr><th scope="row">' . esc_html__('Erlaubte Etiketten', 'taxmod-stock') . '</th><td>' . esc_html(implode(', ', $allowed) ?: '—') . '</td></tr>'
                . ($howto === '' ? '' : '<tr><th scope="row">' . esc_html__('Einrichtung', 'taxmod-stock') . '</th><td>' . esc_html($howto) . '</td></tr>');
            $actions = $this->changeRollDialog($record, $name, $rolls);
        }

        return '<p><a href="' . esc_url($list) . '">' . esc_html__('← Drucker und Rollen', 'taxmod-stock') . '</a></p>'
            . '<h2>' . esc_html($name) . '</h2>'
            . '<div style="text-align:right;margin-bottom:12px">' . $actions
            . ($form === '' ? ' <a class="button" href="' . esc_url(add_query_arg(['page' => 'taxmod', 'taxmod_open_record' => $record], admin_url('admin.php'))) . '">' . esc_html__('Im Taxonomy Modeller bearbeiten', 'taxmod-stock') . '</a>' : '') . '</div>'
            // D-1220: the facts beside the form on wide screens, above it on narrow ones — the shared block.
            . (method_exists(ListPage::class, 'beside')
                ? ListPage::beside($form, '<table class="widefat striped">' . $facts . '</table>')
                : '<table class="widefat striped">' . $facts . '</table>' . $form);
    }

    /**
     * D-1192: «Rolle wechseln» in the shared dialog — which roll is in the printer now.
     *
     * @param array<int, string> $rolls
     */
    private function changeRollDialog(int $printer, string $name, array $rolls): string
    {
        if (! method_exists(ListPage::class, 'dialogAction') || $rolls === []) {
            return '';
        }
        $current = (int) $this->stock->table('label_printer')->first($printer, $this->stock->structure()->field('printer_loaded_roll'));
        $options = '';
        foreach ($rolls as $id => $roll) {
            $options .= '<option value="' . (int) $id . '"' . selected($id, $current, false) . '>' . esc_html($roll) . '</option>';
        }
        $body = '<label style="display:block">' . esc_html__('Welche Rolle ist jetzt eingelegt?', 'taxmod-stock') . '<br><select name="roll" style="width:100%">' . $options . '</select></label>'
            . '<p class="description">' . esc_html(sprintf(/* translators: %s: printer */ __('%s druckt danach nur die Etiketten, die diese Rolle erlaubt.', 'taxmod-stock'), $name)) . '</p>';

        return $this->formStart('drucker')
            . ListPage::dialogAction('taxmod-stock-change-roll-' . $printer, __('Rolle wechseln', 'taxmod-stock'), __('Rolle wechseln', 'taxmod-stock'), $body, 'booking', 'rollewechseln-' . $printer, __('Übernehmen', 'taxmod-stock'))
            . '</form>';
    }

    /** D-1130: «Kopieren» of a kind of place — its name proposed with «(Kopie)», in the shared dialog. */
    private function copyTypeDialog(int $type, string $name): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br><input type="text" name="name" value="'
            . esc_attr(sprintf(/* translators: %s: the name of the original kind of place */ __('%s (Kopie)', 'taxmod-stock'), $name)) . '" style="width:100%" required></label>'
            . '<p class="description">' . esc_html__('Die Kopie trägt alle Felder und Teile dieser Platzart. Plätze dieser Art bleiben bei der alten.', 'taxmod-stock') . '</p>';

        return $this->formStart('platzarten')
            . ListPage::dialogAction('taxmod-stock-copy-type-' . $type, __('Kopieren', 'taxmod-stock'), __('Platzart kopieren', 'taxmod-stock'), $body, 'booking', 'artkopieren-' . $type, __('Kopieren', 'taxmod-stock'))
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

        foreach ($tree->withAllBelow($place) as $where) {
            foreach ($byPlace[$where] ?? [] as $lot => $quantity) {
                $thing = (int) $lots->first($lot, $s->field('lot_thing'));
                $inner = $where === $place ? '' : implode(' › ', array_slice(explode(' › ', $tree->pathOf($where)), $depth));
                $count++;
                $body .= '<tr draggable="true" data-taxmod-stock-lot="' . esc_attr(implode(':', [$lot, $where, $this->plain($quantity), $thing])) . '" style="cursor:grab">'
                    . '<th scope="row" class="check-column"><input type="checkbox" name="selected[]" value="' . esc_attr($lot . ':' . $where . ':' . $thing) . '" form="' . esc_attr(self::PLACE_FORM) . '" aria-label="' . esc_attr__('Auswählen', 'taxmod-stock') . '"></th>'
                    // His question 2026-11-08: «müsste dann nicht eigentlich der Bestand angezeigt werden, anstatt das Material?» —
                    // the row is a lot, so the lot stands first and leads to its own line in the material's Bestand.
                    . '<td><a href="' . esc_url($this->lotUrl($thing, $lot)) . '">#' . esc_html((string) $lot) . '</a></td>'
                    . '<td><a href="' . esc_url($this->lotUrl($thing, $lot)) . '">' . esc_html($things[$thing] ?? '#' . $thing) . '</a></td>'
                    . '<td>' . esc_html($inner) . $this->reservedMark($where, $flags) . '</td>'
                    . '<td>' . esc_html($this->number($quantity)) . '</td>'
                    . '<td>' . esc_html(substr((string) $lots->first($lot, $s->field('lot_date')), 0, 10)) . '</td>'
                    . '<td style="text-align:right;white-space:nowrap">' . $this->transferDialog($thing, $lot, $where, $quantity, $places, $place)
                    . ' ' . $this->bookOutButton(add_query_arg(['page' => self::PAGE, 'tab' => 'verbrauch', 'thing' => $thing, 'lot' => $lot, 'place' => $where], admin_url('admin.php')))
                    // His word 2026-11-08: «Massenfunktion ist da, aber das Drucken pro Posten».
                    . ' ' . $this->lotLabel($thing, $lot, (string) ($things[$thing] ?? ''), $charges[$lot] ?? '') . '</td></tr>';
            }
        }

        $structure = add_query_arg(['page' => self::PAGE, 'tab' => 'lagerstruktur', 'ort' => $place], admin_url('admin.php'));

        return $this->placeHead($place, $tree)
            . '<p>' . esc_html(sprintf(/* translators: %d: lots at the place and below it */ _n('%d Posten hier und darunter.', '%d Posten hier und darunter.', $count, 'taxmod-stock'), $count))
            . ' <a href="' . esc_url($structure) . '">' . esc_html__('Lagerstruktur bearbeiten', 'taxmod-stock') . '</a></p>'
            . ($count > 0 ? $this->placeLotsBar($place, $count) : '')
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
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $s        = $this->stock->structure();
        $projects = $this->stock->projects();
        $types    = $this->stock->placeTypes()->names();
        $body     = '<input type="hidden" name="new_parent" value="' . esc_attr((string) $parent) . '">'
            . '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br><input type="text" name="name" style="width:100%" required></label>'
            // D-1123: a kind decides whether the place moves and brings its parts along (Alex: nine drawers).
            . ($types === [] ? '' : '<div style="margin-top:.5em">' . esc_html__('Platzart', 'taxmod-stock') . '<br>' . $this->recordChoice('place_type', 'location_type', $types) . '</div>')
            . '<label style="display:block;margin-top:.5em"><input type="checkbox" name="movable" value="1"> ' . esc_html($types === [] ? __('beweglich (Kiste, Karton)', 'taxmod-stock') : __('beweglich (Kiste, Karton) — ohne Platzart', 'taxmod-stock')) . '</label>'
            . ($s->hasField('location_reserved') ? '<label style="display:block"><input type="checkbox" name="reserved" value="1"> ' . esc_html__('reserviert für Produktion', 'taxmod-stock') . '</label>' : '')
            . ($projects === [] ? '' : '<div style="margin-top:.5em">' . esc_html__('Projekt', 'taxmod-stock') . '<br>' . $this->recordChoice('project', 'project', $projects) . '</div>');

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

    /** D-1130: «Kopieren» of a place — its name proposed with «(Kopie)», the places inside it on request. */
    private function copyPlaceDialog(int $place, LocationTree $tree, int $inside): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        $name = sprintf(/* translators: %s: the name of the original place */ __('%s (Kopie)', 'taxmod-stock'), (string) (array_reverse(explode(' › ', $tree->pathOf($place)))[0]));
        $body = '<label style="display:block">' . esc_html__('Name', 'taxmod-stock') . '<br><input type="text" name="name" value="' . esc_attr($name) . '" style="width:100%" required></label>'
            . ($inside > 0 ? '<label style="display:block;margin-top:.5em"><input type="checkbox" name="below" value="1"> '
                . esc_html(sprintf(/* translators: %d: number of places inside */ _n('Unterort mitkopieren (%d)', 'Unterorte mitkopieren (%d)', $inside, 'taxmod-stock'), $inside)) . '</label>' : '')
            . '<p class="description">' . esc_html__('Die Kopie steht neben dem Original, mit denselben Feldern. Was dort liegt, wird nicht kopiert.', 'taxmod-stock') . '</p>';

        return $this->formStart('lagerstruktur') . $this->returnFields('lagerstruktur', $place)
            . ListPage::dialogAction('taxmod-stock-copy-place-' . $place, __('Kopieren', 'taxmod-stock'), __('Lagerort kopieren', 'taxmod-stock'), $body, 'booking', 'ortkopieren-' . $place, __('Kopieren', 'taxmod-stock'))
            . '</form>';
    }

    /**
     * The modeller's record chooser for one value — the same everywhere (his rule 2026-11-03: «tree chooser bzw wenn es nicht
     * hierarchich ist selct feld … beides mit suchfield genormt über das ganze projekt»); a plain select only without the modeller.
     *
     * @param array<int, string> $fallback
     */
    private function recordChoice(string $name, string $node, array $fallback): string
    {
        $s      = $this->stock->structure();
        $choice = (string) apply_filters('taxmod_record_chooser', '', [
            'name'        => $name,
            'node'        => $s->node($node),
            'label_field' => $s->field('label'),
        ]);

        return $choice !== '' ? $choice : '<select name="' . esc_attr($name) . '" style="width:100%">' . $this->options($fallback, '—') . '</select>';
    }

    /** D-1090: «Verschieben» — the new place chosen in the same tree, the place itself and what lies in it left out. */
    private function movePlaceDialog(int $place, LocationTree $tree): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

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
        $body = '<label style="display:block;margin-bottom:.5em"><input type="radio" name="' . esc_attr($name) . '" value="0"' . checked($parent, 0, false) . '> '
            . esc_html__('— oberste Ebene —', 'taxmod-stock') . '</label>'
            . ($choice !== '' ? '<div style="max-height:50vh;overflow:auto">' . $choice . '</div>'
                : '<select name="' . esc_attr($name) . '" style="width:100%">' . $this->options(array_diff_key($this->stock->places(), array_flip($tree->withAllBelow($place))), __('— oberste Ebene —', 'taxmod-stock'), $parent) . '</select>')
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
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

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
            . self::actForm([['label' => __('Menge', 'taxmod-stock'), 'control' => '<input type="text" inputmode="decimal" name="quantity" style="width:7em">',
                'hint' => __('Bucht die Menge vom bisherigen Ort ab und am neuen zu, als Vorgang «Umbuchung».', 'taxmod-stock')]]);

        $texts = [
            'hang' => __('«%1$s» nach «%2$s» verschieben? Alles, was darin liegt, zieht mit.', 'taxmod-stock'),
            'lot'  => __('Posten #%1$s von «%2$s» nach «%3$s» umbuchen.', 'taxmod-stock'),
        ];

        return '<style>.taxmod-stock-drop-dialogs .taxmod-dialog-open{display:none}.taxmod-stock-place-actions form{display:inline}</style><div class="taxmod-stock-drop-dialogs">'
            . $this->formStart('lagerstruktur') . $this->returnFields($tab, $place)
            . ListPage::dialogAction('taxmod-stock-drop-place', __('Verschieben', 'taxmod-stock'), __('Verschieben', 'taxmod-stock'), $hangBody, 'booking', 'umhaengen-0', __('Verschieben', 'taxmod-stock'))
            . '</form>'
            . $this->formStart('umbuchen') . $this->returnFields($tab, $place)
            . ListPage::dialogAction('taxmod-stock-drop-lot', __('Umbuchen', 'taxmod-stock'), __('Umbuchen', 'taxmod-stock'), $lotBody, 'booking', 'umbuchen', __('Umbuchen', 'taxmod-stock'))
            . '</form></div>'
            . '<script>(function(){'
            . 'var paths=' . wp_json_encode((object) $paths) . ',movable=' . wp_json_encode($movable) . ',texts=' . wp_json_encode($texts) . ';'
            . 'function fill(t,a){return t.replace(/%(\\d)\\$s/g,function(m,n){return a[parseInt(n,10)-1];});}'
            . 'function open(id){var sw=document.getElementById(id);sw.checked=true;sw.dispatchEvent(new Event("change",{bubbles:true}));return sw.closest("form");}'
            . 'document.addEventListener("dragstart",function(e){var r=e.target.closest&&e.target.closest("[data-taxmod-stock-lot]");if(!r){return;}e.dataTransfer.effectAllowed="move";e.dataTransfer.setData("application/x-taxmod-item","lot:"+r.getAttribute("data-taxmod-stock-lot"));});'
            . 'document.addEventListener("taxmod-record-drop",function(e){var d=e.detail,f;'
            . 'if(d.record!==null){f=open("taxmod-stock-drop-place");var p=f.querySelector(".taxmod-stock-drop-parent");p.name="parent["+d.record+"]";p.value=d.target;'
            . 'f.querySelector("button[name=booking]").value="umhaengen-"+d.record;f.querySelector(".taxmod-stock-drop-text").textContent=fill(texts.hang,[paths[d.record]||"#"+d.record,paths[d.target]||"#"+d.target]);'
            . 'f.querySelector(".taxmod-stock-drop-box").hidden=movable.indexOf(d.record)<0;return;}'
            . 'if(!d.item||d.item.indexOf("lot:")!==0||d.target===0){return;}var x=d.item.split(":");if(parseInt(x[2],10)===d.target){return;}f=open("taxmod-stock-drop-lot");'
            . 'f.elements.lot.value=x[1];f.elements.from.value=x[2];f.elements.quantity.value=x[3];f.elements.thing.value=x[4];f.elements.location.value=d.target;'
            . 'f.querySelector(".taxmod-stock-drop-text").textContent=fill(texts.lot,[x[1],paths[x[2]]||"#"+x[2],paths[d.target]||"#"+d.target]);});'
            . '})();</script>';
    }

    /** A quantity as the forms take it: a dot, no trailing zeros. */
    private function plain(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
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
        if (! method_exists(ListPage::class, 'dialogAction') || ! $this->stock->structure()->hasField('location_reserved')) {
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

    private function consumptionForm(int $preset, int $presetLot, int $presetPlace = 0): string
    {
        $ledger = $this->stock->ledger();
        $labels = $this->stock->things()->all();
        $lots   = $this->stock->table('lot');
        $date   = $this->stock->structure()->field('lot_date');
        $have   = array_intersect_key($labels, array_flip($ledger->things()));
        $choice = '<option value="0">' . esc_html__('automatisch: ältester Posten mit Rest', 'taxmod-stock') . '</option>';

        foreach ($have as $thing => $label) {
            foreach ($ledger->lotsOf($thing) as $lot) {
                $choice .= '<option value="' . esc_attr((string) $lot) . '"' . selected($lot, $presetLot, false) . '>' . esc_html($label . ' · ' . substr((string) $lots->first($lot, $date), 0, 10)
                    . ' · ' . __('Rest', 'taxmod-stock') . ' ' . $this->number($ledger->restOf($lot))) . '</option>';
            }
        }

        // D-1039: «Ausbuchen» on a row of the Bestand tab takes from that row's place.
        $at   = $presetPlace > 0 ? sprintf(/* translators: %s: the place, by its path */ __('Ausgebucht wird am Ort %s.', 'taxmod-stock'), $this->stock->places()[$presetPlace] ?? '#' . $presetPlace) : '';
        $rows = [
            ['label' => __('Material', 'taxmod-stock'), 'for' => 'taxmod-stock-consumption-thing', 'control' => '<select id="taxmod-stock-consumption-thing" name="thing" required>' . $this->options($have, '', $preset) . '</select>'],
            ['label' => __('Menge', 'taxmod-stock'), 'for' => 'taxmod-stock-consumption-quantity', 'control' => '<input type="text" inputmode="decimal" id="taxmod-stock-consumption-quantity" name="quantity" required style="width:6em">'],
            ['label' => __('Bestandsposten', 'taxmod-stock'), 'for' => 'taxmod-stock-consumption-lot', 'control' => '<select id="taxmod-stock-consumption-lot" name="lot">' . $choice . '</select>'
                . ($presetPlace > 0 ? '<input type="hidden" name="place" value="' . esc_attr((string) $presetPlace) . '">' : ''), 'hint' => $at],
            $this->purposeRow($presetPlace),
            ['label' => __('Grund (optional)', 'taxmod-stock'), 'for' => 'taxmod-stock-consumption-reason', 'control' => '<input type="text" id="taxmod-stock-consumption-reason" name="reason" list="taxmod-stock-consumption-reasons" style="width:20em">'
                . '<datalist id="taxmod-stock-consumption-reasons">' . implode('', array_map(static fn (string $r): string => '<option value="' . esc_attr($r) . '">', self::consumptionReasons())) . '</datalist>'],
        ];
        $submit = get_submit_button(__('Verbrauch buchen', 'taxmod-stock'), 'primary', 'submit', false);

        return $this->formStart('verbrauch') . self::actForm($rows, $submit) . '</form>';
    }

    /**
     * The architecture thread 2026-11-09: a form for an act, not a record, through the modeller's shared form block
     * (`ListPage::form`, modeller 0.2.33) — label left, the hint as «?», the buttons below; plain labels on an older one.
     *
     * @param list<array{label: string, control: string, hint?: string, for?: string}> $rows
     */
    public static function actForm(array $rows, string $actions = ''): string
    {
        if (method_exists(ListPage::class, 'form')) {
            return ListPage::form($rows, $actions);
        }

        return implode('', array_map(static fn (array $row): string => '<p><label style="display:block">' . esc_html($row['label']) . '<br>' . $row['control'] . '</label>'
            . (($row['hint'] ?? '') === '' ? '' : '<span class="description">' . esc_html($row['hint']) . '</span>') . '</p>', $rows))
            . ($actions === '' ? '' : '<p class="submit">' . $actions . '</p>');
    }

    /**
     * D-1088: the purpose of a consumption — a subject, «für Produktion», or none. Taken from a reserved place, its Bezug
     * is preselected: reserved goods go only for exactly that purpose. One row of the shared form block.
     *
     * @return array{label: string, control: string, hint: string, for: string}
     */
    private function purposeRow(int $place): array
    {
        $preset   = $this->stock->purposeAt($place);
        $selected = $preset === null ? '' : ($preset->production ? 'produktion' : (string) $preset->subject);
        $choices  = '<option value="">' . esc_html__('— kein Bezug —', 'taxmod-stock') . '</option>'
            . '<option value="produktion"' . selected($selected, 'produktion', false) . '>' . esc_html__('für Produktion (ohne Projekt)', 'taxmod-stock') . '</option>';

        foreach ($this->stock->purposes() as $group => $records) {
            $choices .= '<optgroup label="' . esc_attr($group) . '">';

            foreach ($records as $id => $label) {
                $choices .= '<option value="' . esc_attr((string) $id) . '"' . selected($selected, (string) $id, false) . '>' . esc_html($label) . '</option>';
            }

            $choices .= '</optgroup>';
        }

        return ['label' => __('Bezug', 'taxmod-stock'), 'for' => 'taxmod-stock-consumption-purpose', 'control' => '<select id="taxmod-stock-consumption-purpose" name="purpose">' . $choices . '</select>',
            'hint' => __('Reservierte Ware darf nur für genau ihren Bezug verbraucht werden; der Verbrauch mindert dann die offene Reservierung. Für jeden anderen Zweck erst aus dem reservierten Bereich umbuchen.', 'taxmod-stock')];
    }

    /** D-1088: «produktion», a record id, or nothing — as the form sends it. */
    private function purpose(string $value): Purpose
    {
        return $value === 'produktion' ? Purpose::production() : Purpose::subject(absint($value));
    }

    /**
     * D-1088: the reasons offered for a consumption; any other text is accepted too. A repair is a reason, not a Bezug.
     *
     * @return list<string>
     */
    private static function consumptionReasons(): array
    {
        // D-1168: goods sent a second time leave as a loss.
        return [__('Reparatur', 'taxmod-stock'), __('Verlust', 'taxmod-stock')];
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

        foreach ((array) ($_POST['thing'] ?? []) as $purchase => $thing) {
            // An empty choice is a material taken away; 0 tells the booking to clear the purchase's «Modell».
            if (absint($purchase) > 0 && (absint($thing) > 0 || (string) $thing === '')) {
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
            $out[$purchase] = $this->stock->newThingFor($purchase, $node, absint($_POST['order'] ?? 0), ($places[$purchase] ?? 0) ?: null);
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

        return $out;
    }

    private function lagerInput(): LagerInput
    {
        $minimum = sanitize_text_field(wp_unslash((string) ($_POST['minimum'] ?? '')));

        return new LagerInput(
            ! empty($_POST['single']),
            absint($_POST['use'] ?? 0) ?: null,
            absint($_POST['location'] ?? 0) ?: null,
            $minimum === '' ? null : absint($minimum),
            empty($_POST['has_sources']) ? null : $this->sourceRows(),
            absint($_POST['unit'] ?? 0) ?: null,
            absint($_POST['prefix'] ?? 0) ?: null
        );
    }

    /** @return list<SourceRow> */
    private function sourceRows(): array
    {
        $rows = [];

        foreach (is_array($_POST['sources'] ?? null) ? wp_unslash($_POST['sources']) : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $rows[] = new SourceRow(
                absint($line['part'] ?? 0) ?: null,
                absint($line['firm'] ?? 0) ?: null,
                sanitize_text_field((string) ($line['number'] ?? '')),
                str_replace(',', '.', sanitize_text_field((string) ($line['content'] ?? '')))
            );
        }

        return $rows;
    }

    /**
     * The modeller's chooser for «every record under Model» (D-958 F3a, D-1011): the chosen word with a bin, or a search field and
     * a tree button. Hits and tree come from the material endpoints, kept to the groups of things. Same markup as the modeller's
     * summary field, so its script and stylesheet drive it.
     *
     * @param int|null $purchase D-1111: the receipt row whose «Material» dialog the chosen word opens; null: a plain word
     */
    private function recordPicker(string $name, ?int $value, string $word, ?int $purchase = null): string
    {
        $radio = static fn (string $choice, bool $on): string => '<label class="taxmod-record-choice"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($choice) . '"'
            . ($on ? ' checked' : '') . '></label>';
        $none  = $value === null;
        $clear = __('Zurücksetzen', 'taxmod-stock');
        $tree  = __('Im Baum wählen', 'taxmod-stock');

        return '<span class="taxmod-record-pick taxmod-record-remote' . ($none ? '' : ' taxmod-record-taken') . '">'
            . '<span class="taxmod-record-choices" hidden>' . $radio('', $none) . ($none ? '' : $radio((string) $value, true)) . '</span>'
            . '<input type="search" class="taxmod-record-quick" autocomplete="off" placeholder="' . esc_attr__('Material suchen …', 'taxmod-stock') . '"' . ($none ? '' : ' hidden') . '>'
            . '<button type="button" class="button taxmod-icon-button taxmod-record-browse"' . ($none ? '' : ' hidden') . ' title="' . esc_attr($tree) . '">'
                . '<span class="dashicons dashicons-networking" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($tree) . '</span></button>'
            . ($none || $purchase === null
                ? '<span class="taxmod-record-dialog-open taxmod-record-word"' . ($none ? ' hidden' : '') . '>' . esc_html($word) . '</span>'
                : $this->materialLink($purchase, $value, $word, 'taxmod-record-dialog-open taxmod-record-word'))
            . '<button type="button" class="button taxmod-icon-button taxmod-record-clear"' . ($none ? ' hidden' : '') . ' title="' . esc_attr($clear) . '">'
                . '<span class="dashicons dashicons-undo" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($clear) . '</span></button>'
            . '<span class="taxmod-record-hits" hidden></span>'
            . '</span>';
    }

    /**
     * The modeller's stylesheet and script for {@see self::recordPicker()}, and the one element its script reads the material
     * endpoints from: search, the tree's groups and a group's things.
     */
    private function modellerAssets(bool $groups): string
    {
        if (class_exists(\Taxmod\WordPress\Plugin::class)) {
            $root = dirname((string) (new \ReflectionClass(\Taxmod\WordPress\Plugin::class))->getFileName(), 3);
            $base = WP_PLUGIN_DIR . '/' . basename($root) . '/x.php';
            wp_enqueue_style('taxmod-admin', plugins_url('assets/admin.css', $base), ['dashicons', 'buttons'], (string) \Taxmod\WordPress\Plugin::VERSION);
            wp_enqueue_script('taxmod-admin', plugins_url('assets/admin.js', $base), [], (string) \Taxmod\WordPress\Plugin::VERSION, true);
        }

        $address = static fn (string $action): string => esc_url(add_query_arg(['action' => $action, '_ajax_nonce' => wp_create_nonce($action)], admin_url('admin-post.php')));

        $search  = $address(self::THING_SEARCH) . ($groups ? '&amp;groups=1' : '');

        return '<span hidden id="taxmod-words" data-record-search="' . $search . '"'
            . ' data-record-nodes="' . $address(self::THING_NODES) . '" data-record-branch="' . $address(self::THING_BRANCH) . '"'
            . ' data-browse-title="' . esc_attr__('Material im Baum wählen', 'taxmod-stock') . '"'
            . ' data-browse-filter="' . esc_attr__('Gruppe suchen …', 'taxmod-stock') . '"'
            . ' data-no-hits="' . esc_attr__('Nichts gefunden.', 'taxmod-stock') . '"></span>';
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

        return $this->modellerAssets($receipt) . '<script>(function(){'
            . 'var url=' . wp_json_encode($search) . ',pos=' . wp_json_encode($positions) . ';'
            . 'function bind(root){'
            . 'root.querySelectorAll(".taxmod-stock-filter").forEach(function(f){var s=document.getElementById(f.dataset.for);if(!s){return;}var o=Array.prototype.slice.call(s.options);'
            . 'f.addEventListener("input",function(){var q=f.value.toLowerCase(),first=null;o.forEach(function(x){var fix=x.value===""||x.value==="new"||x.value==="keep"||x.value==="search";x.hidden=q!==""&&!fix&&x.text.toLowerCase().indexOf(q)<0;if(!fix&&!x.hidden&&!first){first=x;}});if(q!==""&&first){s.value=first.value;}});});'
            . 'root.querySelectorAll(".taxmod-stock-thing-search").forEach(function(f){var t=document.getElementById(f.dataset.for),l=document.getElementById(f.getAttribute("list")),w;'
            . 'f.addEventListener("input",function(){var m=f.value.match(/^#(\\d+)/);if(m){t.value=m[1];return;}t.value="";clearTimeout(w);if(f.value.trim().length<2){return;}'
            . 'w=setTimeout(function(){fetch(url+"&term="+encodeURIComponent(f.value),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){l.innerHTML="";(j.data||[]).forEach(function(h){var x=document.createElement("option");x.value="#"+h.id+" · "+h.word+(h.node?" ("+h.node+")":"");l.appendChild(x);});});},250);});});'
            . 'root.querySelectorAll("tr").forEach(function(tr){var cb=tr.querySelector("input[name=\\"purchases[]\\"]");if(!cb||cb.disabled){return;}tr.querySelectorAll("select,input[type=text],input[type=search]").forEach(function(f){f.addEventListener("change",function(){cb.checked=true;});});});'
            . '}bind(document);'
            . 'function load(box){if(box.dataset.loaded){return;}box.dataset.loaded="1";var cell=box;cell.textContent="…";'
            . 'fetch(pos+"&order="+encodeURIComponent(box.dataset.order)+(box.dataset.back?"&back="+encodeURIComponent(box.dataset.back):""),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){cell.innerHTML=(j.data&&j.data.html)||"";bind(cell);})'
            . '.catch(function(){delete box.dataset.loaded;cell.textContent=' . wp_json_encode(__('Positionen konnten nicht geladen werden.', 'taxmod-stock')) . ';});}'
            // An order's purchases load once its details row shows: opened by hand, by «Expand all», or open from the start.
            . 'var seen="IntersectionObserver" in window?new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){seen.unobserve(e.target);load(e.target);}});},{rootMargin:"200px"}):null;'
            . 'document.querySelectorAll(".taxmod-stock-positions:not([data-loaded])").forEach(function(box){if(seen){seen.observe(box);}else{load(box);}});'
            // D-1087: the branch he opened in the tree beside the material search is the group «Neues Material» proposes.
            . 'var browsed={};document.addEventListener("taxmod-browse-node",function(e){var pick=e.target.closest?e.target.closest("tr"):null,f=pick&&pick.querySelector("[name^=\\"thing[\\"]"),m=f&&/^thing\\[(\\d+)\\]/.exec(f.name);if(m){browsed[m[1]]=String(e.detail.node);}});'
            // D-1069: the material's frame loads only when its dialog opens.
            . 'document.addEventListener("change",function(e){var sw=e.target;if(!sw.classList||!sw.classList.contains("taxmod-dialog-switch")||!sw.checked){return;}'
            . 'var f=sw.parentNode.querySelector("iframe[data-src]");if(f&&!f.src){f.src=f.getAttribute("data-src");}'
            // A group already chosen in the row's search is the group «Neues Material» proposes (his report 2026-11-01).
            . 'var nm=/^taxmod-stock-new-(\\d+)$/.exec(sw.id||"");if(nm){var r=document.querySelector("input[name=\\"thing["+nm[1]+"]\\"]:checked"),g=r&&/^new-(\\d+)$/.exec(r.value),gid=g?g[1]:(browsed[nm[1]]||""),sel=document.querySelector("select[name=\\"newgroup["+nm[1]+"]\\"]");if(gid&&sel&&sel.querySelector("option[value=\\""+gid+"\\"]")){sel.value=gid;}var rb=gid&&document.querySelector("input[name=\\"newgroup["+nm[1]+"]\\"][value=\\""+gid+"\\"]");if(rb){rb.checked=true;var ch=rb.closest(".taxmod-chooser"),tx=rb.parentNode.textContent.trim();if(ch){ch.querySelectorAll(":scope > .taxmod-dialog-open, .taxmod-chooser-current").forEach(function(e){e.textContent=tx;});}}}});'
            . '(function(){var mo=(new URLSearchParams(location.search).get("material")||"").replace(/\\D/g,""),n=0;if(!mo){return;}'
            // The rows of an order arrive after the page (lazy details), so the switch is looked for until it is there.
            . 'var t=setInterval(function(){var ms=document.getElementById("taxmod-stock-material-"+mo);if(ms){clearInterval(t);ms.checked=true;ms.dispatchEvent(new Event("change",{bubbles:true}));}else if(++n>40){clearInterval(t);}},250);})();'
            . '})();</script>';
    }

    /** @return list<InventoryRow> */
    private function inventoryRows(): array
    {
        $rows = [];

        foreach ((array) ($_POST['rows'] ?? []) as $row) {
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
                $thing === null ? new NewThing($group, $name) : null,
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

        if (! class_exists(\Taxmod\Core\Renderer\DialogMarkup::class)) {
            return '<a class="' . esc_attr(trim($class . ' taxmod-stock-material-link')) . '" href="' . esc_url(self::thingUrl($thing, 'daten')) . '" target="_blank">' . esc_html($text) . '</a>';
        }

        return '<label class="' . esc_attr(trim($class . ' taxmod-stock-material-link')) . '" for="' . esc_attr('taxmod-stock-material-' . $purchase) . '"' . $look . '>' . esc_html($text) . '</label>';
    }

    /** D-1058: the reason field and the «Nicht einlagern» button of one purchase. */
    /**
     * D-1069: the material of a purchase in a dialog — its own page with every view, so a placeholder from the goods receipt is
     * filled where materials are filled (his word 2026-11-01: «im grunde müssten wir doch im dialog einfach nur die material
     * ansicht zeigen»). The frame loads when the dialog opens.
     */
    private function materialDialog(int $purchase, int $thing): string
    {
        if (! class_exists(\Taxmod\Core\Renderer\DialogMarkup::class)) {
            return '<a class="button button-small" href="' . esc_url(self::thingUrl($thing, 'daten')) . '" target="_blank">' . esc_html__('Material', 'taxmod-stock') . '</a> ';
        }

        // The panel grows to the frame; the shared dialog is drawn for short forms.
        // His answer 2026-11-01 (screenshot of the stock material page): the stock's own material view, not the modeller's.
        $record = self::thingUrl($thing, 'daten');
        $frame  = '<style>#taxmod-stock-material-' . $purchase . ' ~ .taxmod-dialog .taxmod-dialog-panel{width:min(94vw,1150px);max-width:none}</style><iframe data-src="' . esc_url($record) . '" title="' . esc_attr__('Material', 'taxmod-stock') . '"'
            . ' style="display:block;width:100%;height:75vh;border:0"></iframe>';

        return \Taxmod\Core\Renderer\DialogMarkup::of(
            'taxmod-stock-material-' . $purchase,
            esc_html__('Material', 'taxmod-stock'),
            esc_html__('Material', 'taxmod-stock'),
            $frame,
            '<a class="button" href="' . esc_url($record) . '" target="_blank">' . esc_html__('In Logistik öffnen', 'taxmod-stock') . '</a>',
            'button taxmod-dialog-open taxmod-stock-material-open',
            __('Schließen', 'taxmod-stock')
        ) . ' ';
    }

    /**
     * D-1072, his word 2026-11-01: «wir brauc hen einen button für neues material erst suchen ist doof» — the group asked in a dialog,
     * the material created at once (D-1062, D-1071) and then filled through «Material».
     */
    private function newThingDialog(int $purchase, string $groups): string
    {
        if (! method_exists(ListPage::class, 'dialogAction')) {
            return '';
        }

        // D-1075: the modeller's node chooser — search and tree, the one view he set for choosing in the tree; a plain list without it.
        $chooser = $this->stock->groupChooser('newgroup[' . $purchase . ']');
        $field   = self::actForm([['label' => __('Gruppe', 'taxmod-stock'),
            'control' => $chooser !== '' ? $chooser : '<select name="newgroup[' . esc_attr((string) $purchase) . ']" style="width:100%">' . $groups . '</select>',
            'hint' => __('Das Material heißt wie der Kauf; Lagerort und Bezugsquelle kommen aus der Bestellung.', 'taxmod-stock')]]);

        return ListPage::dialogAction('taxmod-stock-new-' . $purchase, __('Neues Material', 'taxmod-stock'), __('Neues Material anlegen', 'taxmod-stock'), $field, 'booking', 'zuordnen', __('Anlegen', 'taxmod-stock')) . ' ';
    }

    private function closeControls(int $purchase): string
    {
        $id     = (string) $purchase;
        $field  = self::actForm([['label' => __('Grund', 'taxmod-stock'), 'control' => '<input type="text" list="taxmod-stock-reasons" name="reason[' . esc_attr($id) . ']" style="width:100%">']]);
        $action = 'nichteinlagern-' . $id;

        // D-1063: the reason is asked in the one dialog every list uses; an older modeller keeps the field in the row.
        if (method_exists(ListPage::class, 'dialogAction')) {
            return ListPage::dialogAction('taxmod-stock-close-' . $id, __('Nicht einlagern', 'taxmod-stock'), __('Ohne Lager abschließen', 'taxmod-stock'), $field, 'booking', $action, __('Nicht einlagern', 'taxmod-stock'));
        }

        return '<input type="text" list="taxmod-stock-reasons" name="reason[' . esc_attr($id) . ']" placeholder="' . esc_attr__('Grund', 'taxmod-stock') . '" style="width:9em"> '
            . '<button type="submit" class="button button-small" name="booking" value="' . esc_attr($action) . '">' . esc_html__('Nicht einlagern', 'taxmod-stock') . '</button>';
    }

    private function formStart(string $booking): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="booking" value="' . esc_attr($booking) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false);
    }

    /** @param array<int, string> $labels */
    private function options(array $labels, string $empty, int $selected = 0): string
    {
        $html = $empty === '' ? '' : '<option value="0">' . esc_html($empty) . '</option>';

        foreach ($labels as $id => $label) {
            $html .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $selected, false) . '>' . esc_html($label) . '</option>';
        }

        return $html;
    }

    private function listRequest(): StockList
    {
        $orderBy = sanitize_key((string) ($_GET['orderby'] ?? ''));

        return new StockList(
            sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
            sanitize_key((string) ($_GET['filter'] ?? '')),
            in_array($orderBy, StockList::ORDER_BY, true) ? $orderBy : 'name',
            sanitize_key((string) ($_GET['order'] ?? '')) === 'desc'
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
            self::bulkBar(self::BULK_FORM, $count, ListPage::filters(self::FILTER_FORM, $filters) . $more, $asking)
        );

        return ListPage::searchBox(self::FILTER_FORM, $list->search, $placeholder, $hidden, __('Suchen', 'taxmod-stock')) . $bar;
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
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    /** D-1178, his word «3 Leisten + 12 Pins»: the stock in whole packs and the rest. */
    private function inPacks(Pack $pack, float $pieces): string
    {
        [$packs, $rest] = $pack->split($pieces);

        /* translators: 1: whole packs, 2: name of the pack, 3: pieces left over */
        return sprintf(__('(%1$d × %2$s + %3$s)', 'taxmod-stock'), $packs, $pack->name === '' ? __('Packung', 'taxmod-stock') : $pack->name, $this->number($rest));
    }

    private function remember(string $type, string $text): void
    {
        set_transient(self::NOTICE . get_current_user_id(), ['type' => $type, 'text' => $text], 60);
    }

    private function notice(): string
    {
        $notice = get_transient(self::NOTICE . get_current_user_id());

        if (! is_array($notice)) {
            // D-1025: what the modeller said after saving the material here.
            $said = isset($_GET['taxmod_message']) ? sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['taxmod_message']))) : '';

            return $said === '' ? '' : '<div class="notice notice-' . ($said === 'ok' ? 'success' : 'info') . ' is-dismissible"><p>' . esc_html($said === 'ok' ? __('Gespeichert.', 'taxmod-stock') : $said) . '</p></div>';
        }

        delete_transient(self::NOTICE . get_current_user_id());

        return '<div class="notice notice-' . esc_attr($notice['type'] === 'success' ? 'success' : 'error') . '"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }
}
