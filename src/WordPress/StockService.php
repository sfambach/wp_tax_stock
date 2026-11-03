<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\InboundArchive;
use TaxmodStock\Core\InventoryRow;
use TaxmodStock\Core\LagerChanges;
use TaxmodStock\Core\LagerInput;
use TaxmodStock\Core\Ledger;
use TaxmodStock\Core\LocationRules;
use TaxmodStock\Core\LocationSettings;
use TaxmodStock\Core\NewThing;
use TaxmodStock\Core\Purchasing;
use TaxmodStock\Core\LocationChanges;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\PlaceTypes;
use TaxmodStock\Core\NotStocked;
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\ReceiptPlan;
use TaxmodStock\Core\Purpose;
use TaxmodStock\Core\ReservationRow;
use TaxmodStock\Core\Reservations;
use TaxmodStock\Core\ReservedPlaces;
use TaxmodStock\Core\SourceRow;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Storno;
use TaxmodStock\Core\TransferRow;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TestQueue;
use TaxmodStock\Core\TestSettings;
use TaxmodStock\Core\Things;
use TaxmodStock\Core\ViewSettings;

/**
 * Reads the Stock tables once per request and hands bookings to the gateway — the seam between page and core.
 */
final class StockService
{
    public const OPTION = 'taxmod_stock_structure';

    public const PLANNED_PLACES = 'taxmod_stock_planned_places';

    /** D-972: which branch of `Model` goes to which place. */
    public const LOCATIONS = 'taxmod_stock_locations';

    /**
     * The branches under `Model` that hold no material — sources, page templates, price records — and so are no group of
     * things (his report 2026-10-28: «LD1111V33 wird zweimal angezeigt einmal als spannungsregler und einmal als quellen
     * verzeichnis»). A list of node ids; registered for REST so it can be set without the screen.
     */
    public const NOT_MATERIAL = 'taxmod_stock_not_material';

    /** D-1002: the branches under `Model` whose lots are tested after the goods receipt. */
    public const TESTS = 'taxmod_stock_tests';

    /** The goods receipt cockpit's start day (Y-m-d): older orders are history and stand only under «Alle». */
    public const INBOUND_SINCE = 'taxmod_stock_inbound_since';

    /** INFERRED default, until he sets his own: the month the cockpit came. */
    public const INBOUND_SINCE_DEFAULT = '2026-10-01';

    /** Days after its receipt a booked order moves to «Archiviert» (D-1008). */
    public const INBOUND_ARCHIVE_DAYS = 'taxmod_stock_inbound_archive_days';

    /** His word: «nach einem Monat oder so». */
    public const INBOUND_ARCHIVE_DAYS_DEFAULT = 30;

    /** Per tab whether its rows open by themselves (see {@see ViewSettings}). */
    public const VIEWS = 'taxmod_stock_views';

    /** @var array<int, Table> node id → its records, read once */
    private array $tables = [];

    /** @var array<int, array{name: string, parent: int, class: string}>|null the tree under the things' root, read once */
    private ?array $tree = null;

    private ?Things $things = null;

    private ?LocationTree $locationTree = null;

    private ?PlaceTypes $placeTypes = null;

    /** @var list<string>|null D-1097: the position lists the model holds as compositions, read once */
    private ?array $composed = null;

    public function __construct(private readonly ModelGateway $model = new ModelGateway())
    {
    }

    public function structure(): Structure
    {
        $raw     = get_option(self::OPTION, []);
        $file    = dirname(__DIR__, 2) . '/structure-live.json';
        $bundled = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return Structure::fromArray(Structure::completed(is_array($raw) ? $raw : [], is_array($bundled) ? $bundled : []));
    }

    /**
     * The structure, told which position lists the model holds as compositions (D-1097) — read from the fields of their
     * nodes, once per request, so the module writes positions right before and after the switch. Anything that writes
     * positions builds on this one.
     *
     * ⚠️ *A model that cannot be read (the modeller inactive, ids of another site) counts as aggregation: that is how every
     * list stood before D-1097, and the write itself then says what is wrong.*
     */
    public function structureWithKinds(): Structure
    {
        $s = $this->structure();

        if ($this->composed === null) {
            $this->composed = [];

            foreach (Structure::positionLists() as $key => $head) {
                try {
                    if (($this->model->fields($s->node($head))[$s->field($key)]['kind'] ?? '') === 'composition') {
                        $this->composed[] = $key;
                    }
                } catch (StockError) {
                    continue;
                }
            }
        }

        return $s->withComposed($this->composed);
    }

    public function table(string $node): Table
    {
        return $this->tableOf($this->structure()->node($node));
    }

    public function tableOf(int $node): Table
    {
        return $this->tables[$node] ??= $this->model->table($node);
    }

    /** D-995: the things the stock knows, named by their own label — one read per node they live in (CD-7). */
    public function things(): Things
    {
        if ($this->things !== null) {
            return $this->things;
        }

        $s     = $this->structure();
        $lager = $this->table('lager');
        $known = Things::known($s, $lager, $this->ledger());
        $nodes = $this->model->nodesOf($known);

        return $this->things = new Things($s, $lager, $this->recordLabels($known, $nodes), $nodes);
    }

    /**
     * The labels of records anywhere in the model, grouped by node so that each node is read once.
     *
     * @param list<int>            $records
     * @param array<int, int>|null $nodes record → node, when already known
     * @return array<int, string>
     */
    public function recordLabels(array $records, ?array $nodes = null): array
    {
        $field  = $this->structure()->field('label');
        $byNode = [];
        $out    = [];

        foreach ($nodes ?? $this->model->nodesOf($records) as $record => $node) {
            $byNode[$node][] = $record;
        }

        foreach ($byNode as $node => $ids) {
            $table = $this->tableOf($node);

            foreach ($ids as $id) {
                $out[$id] = (string) $table->first($id, $field);
            }
        }

        return $out;
    }

    /** @return array<int, int> record → the node it lives in */
    public function nodesOf(array $records): array
    {
        return $this->model->nodesOf($records);
    }

    /**
     * D-1062, D-1069: the new material a purchase gets in `$node`, named like the purchase — a placeholder he fills in the
     * material view («das material nicht nur als dummy anlegen und dann einfach über die material sicht füllen»).
     */
    public function newThingFor(int $purchase, int $node, int $order = 0, ?int $place = null): NewThing
    {
        $thing = new NewThing($node, (string) ($this->table('purchase')->first($purchase, $this->structure()->field('label')) ?? '#' . $purchase), $this->lagerFromPurchase($purchase, $order, $place));
        $this->checkGroups([$thing]);

        return $thing;
    }

    /**
     * D-1071, his wish «wäre irgendwie nett wenn die felder die gefüllt werden können schon gefüllt wären»: the Lager view of a
     * new material, as far as the purchase knows it — the place chosen in its row, and the shop as a source with its number.
     */
    private function lagerFromPurchase(int $purchase, int $order, ?int $place): ?LagerInput
    {
        $s        = $this->structure();
        $orders   = $this->table('order');
        $value    = static fn (string $key): string => $order > 0 && $s->hasField($key) ? trim((string) $orders->first($order, $s->field($key))) : '';
        $merchant = $value('order_merchant');
        $firm     = null;

        // The firm is found by its name only: the order carries the shop as text.
        foreach (array_replace([], ...array_values($this->firms())) as $id => $name) {
            foreach ([$value('order_platform'), $merchant] as $shop) {
                if ($shop !== '' && mb_strtolower(trim($name)) === mb_strtolower($shop)) {
                    $firm ??= $id;
                }
            }
        }

        $number = $s->hasField('purchase_number') ? trim((string) $this->table('purchase')->first($purchase, $s->field('purchase_number'))) : '';

        // His convention for a shop without article numbers (2026-10-30): «Bestellung <nr> (Verkäufer)».
        if ($number === '' && ($nr = $value('order_number')) !== '') {
            $number = 'Bestellung ' . $nr . ($merchant !== '' && $firm !== null && mb_strtolower($merchant) !== mb_strtolower($this->firmName($firm)) ? ' (' . $merchant . ')' : '');
        }

        $sources = $firm === null && $number === '' ? null : [new SourceRow(null, $firm, $number)];

        return $place === null && $sources === null ? null : new LagerInput(false, null, $place, null, $sources);
    }

    private function firmName(int $firm): string
    {
        return (string) (array_replace([], ...array_values($this->firms()))[$firm] ?? '');
    }

    /**
     * D-1073, his word 2026-11-01: «wo es abgelegt ist im tax baum das müsste ich ja auch ändern können daraufhin müssen sich die
     * felder ändern» — the material goes into another group in one step; the group's further fields come empty. A move that
     * would drop values is refused and names them (his doubt the same day: «das verschieben ist problematisch … wegen der daten»).
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function moveThing(int $thing, int $group): array
    {
        $this->checkThings([$thing]);

        if (! isset($this->tree()[$group])) {
            throw StockError::notAGroup($group);
        }

        try {
            return $this->model->write([['op' => 'move_record', 'record' => $thing, 'node' => $group, 'anywhere' => true]]);
        } catch (StockError $e) {
            if ($e->reason === 'model_refused' && preg_match('/would drop values of: (.+?)\.?$/', $e->detail, $m) === 1) {
                throw StockError::wouldDrop($m[1]);
            }

            throw $e;
        }
    }

    /**
     * The fields of a node with their names, for the tab «Technische Daten».
     *
     * @return array<int, array{name: string, target: int, type: string|null}>
     */
    public function fieldsOf(int $node): array
    {
        return $this->model->fields($node);
    }

    /**
     * The groups a thing can live in: every node under the node «Ding» of the Lager view points at (D-995), except the
     * branch the Lager view lives in.
     *
     * @return array<int, array{name: string, parent: int, class: string}>
     */
    public function tree(): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        $s    = $this->structure();
        $root = $this->model->fields($s->node('lager'))[$s->field('lager_thing')]['target'] ?? 0;

        $tree = $root > 0 ? $this->model->tree($root) : [];

        // The branch that holds the Lager view itself (Stock with its orders, purchases and lots) is no group of things,
        // nor is a branch he names as holding no material.
        $own  = $tree[$s->node('lager')]['parent'] ?? 0;
        $drop = $own > 0 && $own !== $root ? [$own => true] : [];

        foreach ($this->notMaterial() as $branch) {
            if ($branch !== $root && isset($tree[$branch])) {
                $drop[$branch] = true;
            }
        }

        do {
            $before = count($drop);

            foreach ($tree as $id => $node) {
                if (isset($drop[$node['parent']])) {
                    $drop[$id] = true;
                }
            }
        } while (count($drop) > $before);

        return $this->tree = array_diff_key($tree, $drop);
    }

    /** @return array<int, int> node → parent, the tree of the groups as the test rule (D-1002) walks it */
    public function groupParents(): array
    {
        return array_map(static fn (array $node): int => $node['parent'], $this->tree());
    }

    /**
     * D-1075: the modeller's node chooser over the groups of things — its root, and the branches that hold no material
     * (the Lager's own branch, and those he names) left out with everything under them.
     */
    public function groupChooser(string $name, int $preselected = 0, string $form = ''): string
    {
        $s    = $this->structure();
        $root = $this->model->fields($s->node('lager'))[$s->field('lager_thing')]['target'] ?? 0;

        if ($root === 0) {
            return '';
        }

        $all     = $this->model->tree($root);
        $own     = $all[$s->node('lager')]['parent'] ?? 0;
        $exclude = array_values(array_unique(array_filter(
            array_merge($own !== $root ? [$own] : [], $this->notMaterial()),
            static fn (int $branch): bool => $branch !== $root && isset($all[$branch])
        )));

        return (string) apply_filters('taxmod_node_chooser', '', array_filter([
            'name'        => $name,
            'root'        => $root,
            'exclude'     => $exclude,
            'unpickable'  => [$root],
            'preselected' => $preselected,
            'form'        => $form,
        ], static fn (mixed $v): bool => $v !== '' && $v !== 0 && $v !== []) + ['name' => $name, 'root' => $root]);
    }

    /**
     * Things whose text or group name holds every word of the search — only in the groups of things, never in the Lager's own
     * branch, so a purchase or a lot is no hit.
     *
     * @return list<array{id: int, word: string, node: string}>
     */
    public function searchThings(string $term, int $limit = 30, bool $groups = false): array
    {
        $words = array_slice(array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($term))) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 2
        )), 0, 5);
        $tree  = $this->tree();

        if ($words === [] || $tree === []) {
            return [];
        }

        $conditions = [];

        foreach ($words as $word) {
            $conditions[] = ['text' => $word, 'nodes' => array_keys(array_filter($tree, static fn (array $node): bool => str_contains(mb_strtolower($node['name']), $word)))];
        }

        $ids    = $this->model->recordsMatching(array_keys($tree), $conditions, $limit);
        $nodes  = $this->model->nodesOf($ids);
        $labels = $this->recordLabels($ids, $nodes);
        $out    = [];

        foreach ($ids as $id) {
            $out[] = ['id' => $id, 'word' => ($labels[$id] ?? '') === '' ? '#' . $id : $labels[$id], 'node' => $tree[$nodes[$id] ?? 0]['name'] ?? ''];
        }

        // D-1062: in the goods receipt a group is a hit too — a new material there, named like the purchase.
        if ($groups) {
            foreach ($tree as $node => $row) {
                $name = mb_strtolower($row['name']);

                if (array_filter($words, static fn (string $word): bool => ! str_contains($name, $word)) === []) {
                    /* translators: %s: name of a group in the material tree */
                    $out[] = ['id' => 'new-' . $node, 'word' => sprintf(__('Neues Material in «%s»', 'taxmod-stock'), $row['name']), 'node' => ''];
                }
            }
        }

        return $out;
    }

    /**
     * The groups of things as rows of a tree, depth first, for the tree beside the material search (D-1011).
     *
     * @return list<array{id: int, name: string, depth: int}>
     */
    public function treeRows(): array
    {
        $tree     = $this->tree();
        $children = [];
        $roots    = [];

        foreach ($tree as $id => $node) {
            if (isset($tree[$node['parent']])) {
                $children[$node['parent']][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $rows = [];
        $walk = function (int $id, int $depth) use (&$walk, &$rows, $tree, $children): void {
            $rows[] = ['id' => $id, 'name' => $tree[$id]['name'], 'depth' => $depth];

            foreach ($children[$id] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };

        // The root is «Model» for every thing and says nothing; its children are the top of the tree.
        foreach ($roots as $root) {
            foreach ($children[$root] ?? [] as $top) {
                $walk($top, 0);
            }
        }

        return $rows;
    }

    /**
     * The things in a group and the groups below it, with their name — at most `$limit`.
     *
     * @return list<array{id: int, word: string, node: int}>
     */
    public function branchThings(int $group, int $limit = 300): array
    {
        $tree = $this->tree();

        if (! isset($tree[$group])) {
            throw StockError::notAGroup($group);
        }

        $branch = [$group => true];

        do {
            $before = count($branch);

            foreach ($tree as $id => $node) {
                if (isset($branch[$node['parent']])) {
                    $branch[$id] = true;
                }
            }
        } while (count($branch) > $before);

        $nodes  = $this->model->recordsOf(array_keys($branch), $limit);
        $labels = $this->recordLabels(array_keys($nodes), $nodes);
        $out    = [];

        foreach ($nodes as $id => $node) {
            $out[] = ['id' => $id, 'word' => ($labels[$id] ?? '') === '' ? '#' . $id : $labels[$id], 'node' => $node];
        }

        return $out;
    }

    /** @return array<int, string> every node under the root of things, with its name — whether it holds material or not */
    public function modelNames(): array
    {
        $s    = $this->structure();
        $root = $this->model->fields($s->node('lager'))[$s->field('lager_thing')]['target'] ?? 0;

        return $root > 0 ? array_map(static fn (array $node): string => (string) $node['name'], $this->model->tree($root)) : [];
    }

    /** @return list<int> the branches named as holding no material */
    public function notMaterial(): array
    {
        $raw = get_option(self::NOT_MATERIAL, []);

        return array_values(array_filter(array_map('absint', is_array($raw) ? $raw : [])));
    }

    /** The names from the root down to a node, «Bauteile ▸ Aktiv ▸ Transistor». */
    public function path(int $node): string
    {
        $tree  = $this->tree();
        $names = [];

        for ($at = $node, $seen = []; isset($tree[$at]) && ! isset($seen[$at]); $at = $tree[$at]['parent']) {
            $seen[$at] = true;
            array_unshift($names, $tree[$at]['name']);
        }

        // The root itself is «Model» for every thing and says nothing.
        array_shift($names);

        return implode(' ▸ ', $names);
    }

    /** @return array<int, string> the choices of «Verwendung», empty if it is not configured */
    public function uses(): array
    {
        $s = $this->structure();

        return $s->hasNode('lager_use') ? $this->model->children($s->node('lager_use')) : [];
    }

    public function ledger(): Ledger
    {
        return new Ledger($this->structure(), $this->table('lot'), $this->table('line'), $this->table('transaction'));
    }

    /**
     * Only these records of a node — one query, not the whole node (CD-7). Falls back to the whole node if the
     * modeller cannot read single records.
     *
     * @param list<int> $records
     */
    public function recordsOf(string $node, array $records): Table
    {
        return $records === [] ? new Table([]) : ($this->tables[$this->structure()->node($node)] ?? $this->model->records($records) ?? $this->table($node));
    }

    /** @return array<int, string> record id → its label */
    public function labels(string $node): array
    {
        $table = $this->table($node);
        $field = $this->structure()->field('label');
        $out   = [];

        foreach ($table->ids() as $id) {
            $out[$id] = (string) ($table->first($id, $field) ?? '#' . $id);
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * The goods receipt's proposal per purchase of the order, and why a purchase is locked.
     *
     * @return array<int, array{lock: string|null, model: int|null, proposal: ReceiptChoice|null}>
     */
    public function receiptPlan(int $order): array
    {
        $s = $this->structure();

        return (new ReceiptPlan($s))->of($order, $this->table('order'), $this->table('purchase'), Ledger::receivedPurchases($s, $this->table('lot')), NotStocked::purchases($s, $this->table('purchase')));
    }

    /** @return array<int, true> purchases some lot already names (D-956) */
    public function receivedPurchases(): array
    {
        return Ledger::receivedPurchases($this->structure(), $this->table('lot'));
    }

    /**
     * Writes a change list built by the core in one call, all or nothing.
     *
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer
     */
    public function write(array $changes): array
    {
        return $this->model->write($changes);
    }

    public function viewSettings(): ViewSettings
    {
        $raw = get_option(self::VIEWS, []);

        return ViewSettings::fromArray(is_array($raw) ? $raw : []);
    }

    public function inboundSince(): string
    {
        $since = (string) get_option(self::INBOUND_SINCE, self::INBOUND_SINCE_DEFAULT);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1 ? $since : self::INBOUND_SINCE_DEFAULT;
    }

    public function inboundArchiveDays(): int
    {
        $days = (int) get_option(self::INBOUND_ARCHIVE_DAYS, self::INBOUND_ARCHIVE_DAYS_DEFAULT);

        return $days > 0 ? $days : self::INBOUND_ARCHIVE_DAYS_DEFAULT;
    }

    public function inboundArchive(string $today): InboundArchive
    {
        return new InboundArchive($this->inboundSince(), $this->inboundArchiveDays(), $today);
    }

    public function testSettings(): TestSettings
    {
        $raw = get_option(self::TESTS, []);

        return TestSettings::fromArray(is_array($raw) ? $raw : []);
    }

    public function locationSettings(): LocationSettings
    {
        $raw = get_option(self::LOCATIONS, []);

        return LocationSettings::fromArray(is_array($raw) ? $raw : []);
    }

    /**
     * The tab's branches with their names and subtrees — one `read-tree` per listed branch.
     *
     * @return array<int, array{name: string, steps: array<int, int>}>
     */
    public function branches(): array
    {
        $out = [];

        foreach (array_keys($this->locationSettings()->branches) as $branch) {
            $out[$branch] = $this->model->subtree($branch);
        }

        return $out;
    }

    /**
     * D-972: the place proposed for each purchase of the plan — the thing's own Standard-Lagerort, else its branch's.
     * D-1111: «Lagerort für Test» instead, where the lot will wait for its test (D-1002); a place saved with «Zuordnung
     * speichern» is his own choice and stays.
     *
     * @param array<int, array{lock: string|null, model: int|null, proposal: ReceiptChoice|null}> $plan
     * @return array<int, int> purchase → location record
     */
    public function receiptLocations(array $plan): array
    {
        $s        = $this->structure();
        $settings = $this->locationSettings();
        $rules    = new LocationRules($settings->branches, array_map(static fn (array $b): array => $b['steps'], $this->branches()), $settings->unreferenced, $s->value('location_default'));
        $lager    = $this->table('lager');
        $things   = $this->things();
        $models   = array_values(array_unique(array_filter(array_column($plan, 'model'))));
        $nodes    = $this->model->nodesOf($models);
        $out      = [];
        $planned  = $this->plannedPlaces();
        $tests    = $this->testSettings();
        // A test place that is no place any more proposes nothing; the tree is read only when there is one.
        $testing  = $tests->place !== null && $this->table('location')->has($tests->place);
        $queue    = new TestQueue($s);
        $parents  = $testing ? $this->groupParents() : [];

        foreach ($plan as $purchase => $row) {
            $thing  = $row['model'];
            $record = $thing === null ? null : $things->lagerOf($thing);
            $own    = $record !== null && $s->hasField('lager_location') ? (int) $lager->first($record, $s->field('lager_location')) : 0;
            $node   = $thing === null ? null : ($nodes[$thing] ?? 0);
            $usual  = $rules->propose($own > 0 ? $own : null, $node);

            // The lot is made by this receipt, so it has no condition yet: '' — new goods would carry «neu» (D-1002).
            $out[$purchase] = $planned[$purchase] ?? ($testing ? $queue->receiptPlace($usual, $node ?: null, '', $parents, $tests) : $usual);
        }

        return $out;
    }

    /**
     * The places chosen with «Zuordnung speichern» before anything is booked (his report 2026-11-01: «er speichert nicht den
     * lagerort»). Working state of the receipt form, not a fact of the model: it goes once the purchase is booked.
     *
     * @return array<int, int> purchase id → place id
     */
    public function plannedPlaces(): array
    {
        $raw = get_option(self::PLANNED_PLACES, []);

        return is_array($raw) ? array_filter(array_map('intval', $raw), static fn (int $place): bool => $place > 0) : [];
    }

    /** @param array<int, int> $places purchase id → place id; 0 forgets the purchase's place */
    public function planPlaces(array $places): void
    {
        $known = $this->table('location');
        $all   = $this->plannedPlaces();

        foreach ($places as $purchase => $place) {
            if ($place > 0 && $known->has($place)) {
                $all[$purchase] = $place;
            } else {
                unset($all[$purchase]);
            }
        }

        update_option(self::PLANNED_PLACES, $all, false);
    }

    /**
     * @param list<InventoryRow> $rows
     * @return array<string, mixed>
     */
    public function bookInventory(array $rows, string $today, string $title): array
    {
        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        $this->checkThings(array_values(array_filter(array_map(static fn (InventoryRow $r): ?int => $r->thing, $rows))));
        $this->checkGroups(array_values(array_filter(array_map(static fn (InventoryRow $r): ?NewThing => $r->new, $rows))));

        // The name of a row only labels its lot and line: an existing thing lends its own, a new one is named as typed.
        $labels = $this->recordLabels(array_values(array_filter(array_map(static fn (InventoryRow $r): ?int => $r->thing, $rows))));
        $named  = array_map(
            static fn (InventoryRow $r): InventoryRow => new InventoryRow(
                $r->thing,
                $r->new,
                $r->new?->name ?? ($labels[(int) $r->thing] ?? ''),
                $r->quantity,
                $r->value,
                $r->note
            ),
            $rows
        );

        return $this->model->write((new Bookings($this->structureWithKinds(), $today))->inventory($named, $title));
    }

    /**
     * @param array<int, ReceiptChoice> $choices purchase id → its thing
     * @return array<string, mixed>
     */
    public function bookReceipt(int $order, array $choices, string $today, string $title): array
    {
        $s      = $this->structure();
        $places = $this->table('location');

        foreach ($choices as $choice) {
            if ($choice->location !== null && ! $places->has($choice->location)) {
                throw StockError::notALocation($choice->location);
            }
        }

        $this->checkThings(array_values(array_filter(array_map(static fn (ReceiptChoice $c): ?int => $c->thing, $choices))));
        $this->checkGroups(array_values(array_filter(array_map(static fn (ReceiptChoice $c): ?NewThing => $c->new, $choices))));

        $changes = (new Bookings($this->structureWithKinds(), $today))->receipt(
            $order,
            $choices,
            $this->table('order'),
            $this->table('purchase'),
            $this->table('money'),
            // D-1058: a purchase closed without stock counts as done for the order's status.
            Ledger::receivedPurchases($s, $this->table('lot')) + NotStocked::purchases($s, $this->table('purchase')),
            $title
        );

        $result = $this->model->write($changes);
        // Booked: the lot holds the place now, the plan has done its work.
        $this->planPlaces(array_fill_keys(array_keys($choices), 0));

        return $result;
    }

    /**
     * D-1058: closes one purchase of an order without stock, with its reason — or opens it again.
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function closeWithoutStock(int $order, int $purchase, string $reason): array
    {
        $s = $this->structure();

        if (! $s->hasField('purchase_not_stocked')) {
            throw StockError::notConfigured('fields.purchase_not_stocked');
        }

        return $this->model->write((new NotStocked($s))->close($order, $purchase, $reason, $this->table('order'), $this->table('purchase'), Ledger::receivedPurchases($s, $this->table('lot'))));
    }

    /** @return array<string, mixed> the modeller's answer */
    public function reopenForStock(int $order, int $purchase): array
    {
        $s = $this->structure();

        return $this->model->write((new NotStocked($s))->reopen($order, $purchase, $this->table('order'), $this->table('purchase'), Ledger::receivedPurchases($s, $this->table('lot'))));
    }

    /** D-1030: the storage places as a tree, read once per request. */
    public function locationTree(): LocationTree
    {
        if ($this->locationTree !== null) {
            return $this->locationTree;
        }

        $s      = $this->structure();
        $places = $this->table('location');

        return $this->locationTree = LocationTree::from($s, $places, $this->recordLabels(LocationTree::projectIds($s, $places)), $this->placeTypes());
    }

    /** D-1123: the kinds of place; none while the model has no node for them. */
    public function placeTypes(): PlaceTypes
    {
        $s = $this->structure();

        if (! $s->hasNode('location_type')) {
            return new PlaceTypes();
        }

        return $this->placeTypes ??= PlaceTypes::from($s, $this->table('location_type'), $s->hasNode('location_type_part') ? $this->table('location_type_part') : new Table([]));
    }

    /**
     * D-1123: a new kind of place, by its name; its other fields are filled in the record editor.
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function createPlaceType(string $name): array
    {
        $s    = $this->structure();
        $name = trim($name);

        if ($name === '' || ! $s->hasNode('location_type')) {
            throw StockError::placeWithoutName();
        }

        $values = [(string) $s->field('label') => $name] + ($s->hasField('type_movable') ? [(string) $s->field('type_movable') => '0'] : []);

        return $this->model->write([
            ['op' => 'create_record', 'ref' => 'art', 'node' => (string) $s->node('location_type')],
            ['op' => 'set_values', 'record' => '@art', 'values' => $values],
        ]);
    }

    /** @return array<int, string> every place by its path and project, in tree order — what a place chooser offers */
    public function places(): array
    {
        return $this->locationTree()->labels();
    }

    /** @return array<int, string> the projects a box can belong to; empty while the structure names no project node */
    public function projects(): array
    {
        return $this->structure()->hasNode('project') ? $this->labels('project') : [];
    }

    /** @return array<string, mixed> the modeller's answer */
    public function createPlace(string $name, int $parent, bool $movable, int $project, bool $reserved = false, int $type = 0): array
    {
        return $this->model->write((new LocationChanges($this->structure()))->create($name, $parent, $movable, $project, $this->locationTree(), $this->table('location'), $reserved, $type, $this->placeTypes()));
    }

    /**
     * «Umhängen»: the place with all it holds; into a reserved area only what is not reserved yet (D-1082).
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function movePlace(int $place, int $parent, string $today = '', string $title = ''): array
    {
        $tree    = $this->locationTree();
        $changes = (new LocationChanges($this->structure()))->move($place, $parent, $tree, $this->table('location'));
        $this->reservedPlaces()->checkHang($place, $parent, $this->ledger(), $this->boundLots());

        // D-1091: a box that moves is a Vorgang «Umlagerung» in the same step; a fixed place is a change of the structure only.
        if ($this->relocates() && $tree->isMovable($place) && $tree->parentOf($place) !== $parent) {
            $changes = [...$changes, ...(new Bookings($this->structureWithKinds(), $today === '' ? current_time('Y-m-d') : $today))->relocate($place, $tree->parentOf($place), $parent, $title === '' ? 'Umlagerung' : $title)];
        }

        return $this->model->write($changes);
    }

    /** D-1091: whether the model knows the Vorgang «Umlagerung» (its kind and the field for the box). */
    public function relocates(): bool
    {
        $s = $this->structure();

        return $s->hasValue('kind_relocation') && $s->hasField('transaction_carrier') && $s->hasField('line_location');
    }

    /**
     * D-1091: the moves of one box, newest first — date, from and to (0: top level).
     *
     * @return list<array{date: string, from: int, to: int}>
     */
    public function relocationsOf(int $box): array
    {
        if (! $this->relocates()) {
            return [];
        }

        $s     = $this->structure();
        $deals = $this->table('transaction');
        $lines = $this->table('line');
        $out   = [];

        foreach ($deals->ids() as $deal) {
            if ((int) $deals->first($deal, $s->field('transaction_kind')) !== $s->value('kind_relocation') || (int) $deals->first($deal, $s->field('transaction_carrier')) !== $box) {
                continue;
            }

            $move = ['date' => (string) $deals->first($deal, $s->field('transaction_date')), 'from' => 0, 'to' => 0];

            foreach ($deals->all($deal, $s->field('transaction_lines')) as $line) {
                $move[(float) $lines->first((int) $line, $s->field('line_quantity')) < 0 ? 'from' : 'to'] = (int) $lines->first((int) $line, $s->field('line_location'));
            }

            $out[] = $move;
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $out;
    }

    /** D-1082: the places reserved for production, computed from the tree read once. */
    public function reservedPlaces(): ReservedPlaces
    {
        return ReservedPlaces::from($this->structure(), $this->table('location'), $this->locationTree());
    }

    /**
     * D-1082: sets or takes off «reserviert für Produktion». Setting it over goods a reservation names is refused.
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function reservePlace(int $place, bool $reserved): array
    {
        if (! $this->structure()->hasField('location_reserved')) {
            throw StockError::notConfigured('fields.location_reserved');
        }

        if ($reserved) {
            $this->reservedPlaces()->checkFlag($place, $this->ledger(), $this->boundLots());
        }

        return $this->model->write((new LocationChanges($this->structure()))->reserve($place, $reserved, $this->table('location')));
    }

    /**
     * The lots a reservation names (D-1083); they go into no reserved area.
     *
     * @return array<int, true>
     */
    public function boundLots(): array
    {
        return array_fill_keys(array_keys($this->reservations()->boundLots()), true);
    }

    /** D-1083: the reservations of subjects, read from the Vorgänge once per request. */
    public function reservations(): Reservations
    {
        return Reservations::from($this->structure(), $this->table('transaction'), $this->table('line'), $this->ledger());
    }

    /**
     * D-1083: what is reserved of each thing — by a subject's reservation and by lying in a reserved area (D-1082).
     *
     * @return array<int, float> thing → quantity
     */
    public function reservedByThing(): array
    {
        $out = $this->reservations()->reservedByThing();

        foreach ($this->reservedPlaces()->reservedByThing($this->ledger()) as $thing => $quantity) {
            $out[$thing] = ($out[$thing] ?? 0.0) + $quantity;
        }

        return $out;
    }

    /**
     * The records a reservation or a consumption can be for: projects and sales, as far as the structure names their
     * nodes. Build runs and repairs are no Bezug (D-1088): a repair is a reason for a consumption.
     *
     * @return array<string, array<int, string>> group name → record id → label
     */
    public function subjects(): array
    {
        $s   = $this->structure();
        $out = [];

        if ($s->hasNode('project')) {
            $out[__('Projekte', 'taxmod-stock')] = $this->labels('project');
        }

        if ($s->hasNode('sale')) {
            $out[__('Verkäufe', 'taxmod-stock')] = $this->labels('sale');
        }

        return array_filter($out);
    }

    /**
     * D-1088: what a consumption can name as its purpose — the subjects; before the model has the Bezug field only the
     * projects, which the Vorgang's «Projekt» holds.
     *
     * @return array<string, array<int, string>> group name → record id → label
     */
    public function purposes(): array
    {
        $subjects = $this->subjects();

        return $this->structure()->hasField('transaction_reference') ? $subjects : array_intersect_key($subjects, [__('Projekte', 'taxmod-stock') => true]);
    }

    /**
     * D-1083: a position for a subject's reservation — into its open reservation, or a new one with date and deadline.
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function reserve(int $subject, string $deadline, int $thing, string $quantity, ?int $lot, string $today, string $title): array
    {
        $s = $this->structure();

        if (! $s->hasValue('kind_reservation')) {
            throw StockError::notConfigured('values.kind_reservation');
        }

        if ($subject <= 0 || $this->model->nodesOf([$subject]) === []) {
            throw StockError::noSubject();
        }

        $this->checkThings([$thing]);
        $rows         = [new ReservationRow($thing, $quantity, $lot)];
        $reservations = $this->reservations();
        $reservations->check($rows, $this->ledger(), $this->reservedPlaces());

        return $this->model->write((new Bookings($this->structureWithKinds(), $today))->reserve($subject, $deadline, $rows, $reservations->forSubject($subject), $this->table('transaction'), $title));
    }

    /**
     * D-1036 for a reservation: whole, or one position (`$line`); who and when stand in the Storno.
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function cancelReservation(int $deal, ?int $line, string $now, string $who): array
    {
        $reservations = $this->reservations();
        $positions    = $reservations->toCancel($deal, $line);
        /* translators: %s: the name of the reservation taken back */
        $title        = sprintf(__('Storno: %s', 'taxmod-stock'), (string) ($this->table('transaction')->first($deal, $this->structure()->field('label')) ?? '#' . $deal));

        return $this->model->write((new Bookings($this->structureWithKinds(), $now))->cancelReservation($reservations->subjectOf($deal), $positions, $title, $who . ', ' . $now));
    }

    public function notStockedReason(int $purchase): string
    {
        $s = $this->structure();

        return $s->hasField('purchase_not_stocked') ? (string) $this->table('purchase')->first($purchase, $s->field('purchase_not_stocked')) : '';
    }

    public function canCloseWithoutStock(): bool
    {
        return $this->structure()->hasField('purchase_not_stocked');
    }

    /**
     * The material of some purchases kept without a receipt (the interim state of a goods receipt).
     *
     * @param array<int, int> $things purchase id → thing, 0 to take it away
     * @param array<int, int> $places purchase id → the place chosen for it, kept until it is booked
     * @param array<int, NewThing> $new purchase id → a new material in a group, from its dialog (D-1062, D-1069)
     * @return array<string, mixed> the modeller's answer; nothing written when nothing changed
     */
    public function assignPurchases(int $order, array $things, array $places = [], array $new = []): array
    {
        $this->checkThings(array_values(array_filter($things)));
        $things = $new + $things;

        $this->checkGroups(array_values(array_filter($things, static fn (int|NewThing $t): bool => $t instanceof NewThing)));
        $changes = (new Bookings($this->structureWithKinds(), current_time('Y-m-d')))->assign($order, $things, $this->table('order'), $this->table('purchase'));
        $this->planPlaces($places);

        return $changes === [] ? ['applied' => []] : $this->model->write($changes);
    }

    /**
     * Concept R3: a lot purchase told apart into its parts, each with its material; the receipt books them after.
     *
     * @param list<SplitRow> $rows
     * @return array<string, mixed> the modeller's answer
     */
    public function splitPurchase(int $order, int $purchase, array $rows): array
    {
        $this->checkThings(array_values(array_filter(array_map(static fn (SplitRow $r): ?int => $r->thing, $rows))));
        $this->checkGroups(array_values(array_filter(array_map(static fn (SplitRow $r): ?NewThing => $r->new, $rows))));

        return $this->model->write((new Bookings($this->structureWithKinds(), current_time('Y-m-d')))->split($order, $purchase, $rows, $this->table('order'), $this->table('purchase'), $this->table('money')));
    }

    /**
     * D-1018: the firms a source can name, grouped by the node they live in under «Organisation» (maker, seller …), each node
     * read once.
     *
     * @return array<string, array<int, string>> node name → record id → firm name
     */
    public function firms(): array
    {
        $s = $this->structure();

        if (! $s->hasNode('firm')) {
            return [];
        }

        $name = $s->hasField('firm_name') ? $s->field('firm_name') : $s->field('label');
        $out  = [];

        foreach ($this->model->tree($s->node('firm')) as $node => $row) {
            $table = $this->tableOf($node);

            foreach ($table->ids() as $id) {
                $out[$row['name']][$id] = (string) ($table->first($id, $name) ?? '#' . $id);
            }

            if (isset($out[$row['name']])) {
                asort($out[$row['name']], SORT_NATURAL | SORT_FLAG_CASE);
            }
        }

        return $out;
    }

    /**
     * D-1019: the units and the prefixes a base unit is made of, each one read-tree; hidden ones left out.
     *
     * @return array{units: array<int, string>, prefixes: array<int, string>}
     */
    public function baseUnits(): array
    {
        $s   = $this->structure();
        $out = ['units' => [], 'prefixes' => []];

        foreach (['units' => 'base_units', 'prefixes' => 'prefixes'] as $kind => $key) {
            if (! $s->hasNode($key)) {
                continue;
            }

            foreach ($this->model->tree($s->node($key)) as $id => $row) {
                if (! $row['hidden'] && in_array($row['class'], ['Unit', 'Constant'], true)) {
                    $out[$kind][$id] = $row['name'];
                }
            }
        }

        return $out;
    }

    /**
     * D-1018: the sources on a Lager record, as its form shows them.
     *
     * @return list<SourceRow>
     */
    public function sourcesOf(?int $lagerRecord): array
    {
        $s = $this->structure();

        if ($lagerRecord === null || ! $s->hasNode('source') || ! $s->hasField('lager_sources')) {
            return [];
        }

        $parts = $this->table('source');
        $out   = [];

        foreach ($this->table('lager')->all($lagerRecord, $s->field('lager_sources')) as $part) {
            $firm  = $parts->first((int) $part, $s->field('source_firm'));
            $out[] = new SourceRow(
                (int) $part,
                $firm === null ? null : (int) $firm,
                (string) ($parts->first((int) $part, $s->field('source_number')) ?? ''),
                $s->hasField('source_content') ? (string) ($parts->first((int) $part, $s->field('source_content')) ?? '') : ''
            );
        }

        return $out;
    }

    /** @return array<string, mixed> the modeller's answer */
    public function saveLager(int $thing, LagerInput $input): array
    {
        $this->checkThings([$thing]);

        if ($input->location !== null && ! $this->table('location')->has($input->location)) {
            throw StockError::notALocation($input->location);
        }

        $firms = array_replace([], ...array_values($this->firms()));

        foreach ($input->sources ?? [] as $row) {
            if ($row->firm !== null && ! isset($firms[$row->firm])) {
                throw StockError::notAFirm($row->firm);
            }

            if ($row->content !== '' && (! is_numeric($row->content) || (float) $row->content <= 0)) {
                throw StockError::notAPackContent($row->content);
            }
        }

        $units = $this->baseUnits();

        if (($input->unit !== null && ! isset($units['units'][$input->unit])) || ($input->prefix !== null && ! isset($units['prefixes'][$input->prefix]))) {
            throw StockError::notAUnit((int) ($input->unit ?? $input->prefix));
        }

        return $this->model->write((new LagerChanges($this->structure()))->save($thing, $this->things()->lagerOf($thing), $input));
    }

    /**
     * D-1039: a consumption takes from the place it names, else from where the lot lies — the place it came in first.
     * D-1088: reserved goods only for exactly their purpose — inside a reserved area its Bezug, for a lot a reservation
     * names that reservation's subject; any other purpose stays refused (D-1082).
     *
     * @return array<string, mixed>
     */
    public function bookConsumption(int $thing, ?int $lot, string $quantity, Purpose $purpose, string $today, string $title, ?int $place = null, string $reason = ''): array
    {
        $ledger = $this->ledger();
        $lot  ??= $ledger->proposedLot($thing);

        if ($ledger->thingOf($lot) !== $thing) {
            throw StockError::noLot($thing);
        }

        if ($purpose->subject > 0 && ! in_array($purpose->subject, array_merge(...array_map('array_keys', array_values($this->purposes()))), true)) {
            throw StockError::noSubject();
        }

        // A place where this lot does not lie (another lot picked in the form) falls back to where it does.
        $reserved = $this->reservedPlaces();
        $place    = $place !== null && $ledger->restAt($lot, $place) > 0.0 ? $place : null;
        $place  ??= $this->takenFrom($lot, $ledger, $reserved, $purpose);
        $reserved->checkConsumption($place ?? $ledger->homeOf($lot), $purpose);
        $this->reservations()->checkConsumption($lot, $quantity, $place ?? $ledger->homeOf($lot), $purpose, $ledger, $reserved);

        return $this->model->write((new Bookings($this->structureWithKinds(), $today))->consumption($lot, $quantity, $purpose, $title, $place, $reason));
    }

    /**
     * D-1088: the purpose a consumption at `$place` is preselected with — the Bezug of the reserved area it lies in
     * (its project, else «Produktion»); null where the place is free.
     */
    public function purposeAt(int $place): ?Purpose
    {
        $reserved = $this->reservedPlaces();

        if ($place <= 0 || $reserved->reservingPlaceOf($place) === null) {
            return null;
        }

        $project = $reserved->subjectAt($place);

        return $project > 0 ? Purpose::subject($project) : Purpose::production();
    }

    /**
     * D-1036 for a consumption: the goods come back where they were taken; who and when stand in the Storno.
     *
     * @return array<string, mixed>
     */
    public function cancelConsumption(int $deal, string $now, string $who): array
    {
        $s     = $this->structure();
        $deals = $this->table('transaction');
        $rows  = (new Storno($s))->consumptionRows($deal, $deals, $this->table('line'));
        /* translators: %s: the name of the Vorgang taken back */
        $title = sprintf(__('Storno: %s', 'taxmod-stock'), (string) ($deals->first($deal, $s->field('label')) ?? '#' . $deal));

        return $this->model->write((new Bookings($this->structureWithKinds(), $now))->cancelConsumption($rows, $title, $who . ', ' . $now));
    }

    /**
     * Where a consumption takes from when no place is named: where the lot came in if something lies there, else where
     * most lies — a reserved place only when nothing lies elsewhere or the purpose is its Bezug, so a refusal names it.
     */
    private function takenFrom(int $lot, Ledger $ledger, ReservedPlaces $reserved, Purpose $purpose): ?int
    {
        if (! $this->structure()->hasField('line_location')) {
            return null;
        }

        $places = array_filter($ledger->placesOf($lot), static fn (float $q, int $place): bool => $q > 0.0 && $place > 0, ARRAY_FILTER_USE_BOTH);
        $free   = array_filter($places, static fn (int $place): bool => $reserved->reservingPlaceOf($place) === null, ARRAY_FILTER_USE_KEY);
        $places = $free === [] ? $places : $free;

        if ($places === []) {
            return null;
        }

        if (isset($places[$ledger->homeOf($lot)])) {
            return $ledger->homeOf($lot);
        }

        arsort($places);

        return (int) array_key_first($places);
    }

    /**
     * «Umbuchen» (D-1039): a quantity of a lot from the place it lies at to another, as a Vorgang «Umbuchung» with the
     * lines «− old place, + new place» — also when the whole lot moves. Replaces writing the lot's «Lagerort» (0.10.x).
     *
     * @return array<string, mixed>
     */
    public function moveLot(int $lot, int $from, int $location, string $quantity, string $today, string $title): array
    {
        if (! $this->table('lot')->has($lot)) {
            throw StockError::noLot($lot);
        }

        if (! $this->table('location')->has($location)) {
            throw StockError::notALocation($location);
        }

        return $this->model->write((new Bookings($this->structureWithKinds(), $today))->transfer([new TransferRow($lot, $from, $location, $quantity)], $title, $this->ledger(), '', $this->reservedPlaces(), $this->boundLots()));
    }

    /**
     * «Umbuchen nach …» for several materials at once (his wish 2026-11-03: «3 material wählen alle umbuchen nach»): all
     * of each one, every lot from every place it lies at, to one place — one Vorgang «Umbuchung», one write.
     *
     * @param list<int> $things
     * @return array<string, mixed>
     */
    public function moveThings(array $things, int $location, string $today, string $title): array
    {
        if (! $this->table('location')->has($location)) {
            throw StockError::notALocation($location);
        }

        $ledger = $this->ledger();
        $rows   = [];

        foreach ($things as $thing) {
            foreach ($ledger->lotsOf($thing) as $lot) {
                foreach ($ledger->placesOf($lot) as $place => $quantity) {
                    if ($quantity > 0.0 && (int) $place !== $location) {
                        $rows[] = new TransferRow($lot, (int) $place, $location, rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'));
                    }
                }
            }
        }

        if ($rows === []) {
            throw StockError::nothingToBook();
        }

        return $this->model->write((new Bookings($this->structureWithKinds(), $today))->transfer($rows, $title, $ledger, '', $this->reservedPlaces(), $this->boundLots()));
    }

    /**
     * D-1036: an Umbuchung taken back as a Storno — the goods go back where they lay; who did it and when stand in the
     * Storno's description and date.
     *
     * @return array<string, mixed>
     */
    public function cancelTransfer(int $deal, string $now, string $who): array
    {
        $s     = $this->structure();
        $deals = $this->table('transaction');
        $rows  = (new Storno($s))->transferRows($deal, $deals, $this->table('line'), $this->ledger());
        /* translators: %s: the name of the Vorgang taken back */
        $title = sprintf(__('Storno: %s', 'taxmod-stock'), (string) ($deals->first($deal, $s->field('label')) ?? '#' . $deal));

        // The way back is an Umbuchung too: it may not carry reserved goods into a reserved area (D-1082).
        return $this->model->write((new Bookings($this->structureWithKinds(), $now))->transfer($rows, $title, $this->ledger(), $who . ', ' . $now, $this->reservedPlaces(), $this->boundLots()));
    }

    /** D-1027: purchasing — demand, source, order per supplier. */
    public function purchasing(): Purchasing
    {
        return new Purchasing($this->structureWithKinds(), current_time('Y-m-d'));
    }

    /**
     * «Nachbestellen»: the things into an open demand or a new one; each thing must be a material in the tree.
     *
     * @param array<int, string> $quantities thing → quantity
     * @return array<string, mixed>
     */
    public function reorder(array $quantities, ?int $demand, string $newName): array
    {
        $this->checkThings(array_keys($quantities));
        $labels = $this->recordLabels(array_keys($quantities));
        $rows   = [];

        foreach ($quantities as $thing => $quantity) {
            $rows[$thing] = ['label' => $labels[$thing] ?? '', 'quantity' => $quantity];
        }

        return $this->model->write($this->purchasing()->reorder($rows, $demand, $newName, $this->table('order'), $this->table('purchase')));
    }

    /**
     * «Bezugsquelle wählen»: a firm of the register or a merchant typed in; the firm's name becomes the order's «Händler».
     *
     * @return array<string, mixed>
     */
    public function sourcePosition(int $demand, int $purchase, ?int $firm, string $merchant, string $number, ?string $unitPrice): array
    {
        $names = array_replace(...array_values($this->firms() ?: [[]]));

        if ($firm !== null) {
            $merchant = $names[$firm] ?? throw StockError::notAFirm($firm);
        } else {
            // A merchant typed in or named by an offer («Mouser») takes the register's name when one starts with it
            // («Mouser Electronics»), so both land in the same draft.
            foreach ($names as $name) {
                if (trim($merchant) !== '' && stripos($name, trim($merchant)) === 0) {
                    $merchant = $name;
                    break;
                }
            }
        }

        return $this->model->write($this->purchasing()->source($demand, $purchase, $merchant, $number, $unitPrice, $this->table('order'), $this->table('purchase')));
    }

    /** @return array<string, mixed> */
    public function unsourcePosition(int $draft, int $purchase): array
    {
        return $this->model->write($this->purchasing()->unsource($draft, $purchase, $this->table('order'), $this->table('purchase')));
    }

    /** @return array<string, mixed> */
    public function setPositionQuantity(int $order, int $purchase, string $quantity): array
    {
        return $this->model->write($this->purchasing()->setQuantity($order, $purchase, $quantity, $this->table('order')));
    }

    /** @return array<string, mixed> */
    public function removePosition(int $order, int $purchase): array
    {
        return $this->model->write($this->purchasing()->removeLine($order, $purchase, $this->table('order')));
    }

    /** @return array<string, mixed> */
    public function markOrdered(int $draft, string $number): array
    {
        return $this->model->write($this->purchasing()->markOrdered($draft, $number, $this->table('order')));
    }

    /**
     * Back from «bestellt»: refused once the order has arrived (its «Eingegangen am», as the cockpit reads it).
     *
     * @return array<string, mixed>
     */
    public function orderBackToDraft(int $order, string $receivedOn): array
    {
        return $this->model->write($this->purchasing()->backToDraft($order, $this->table('order'), $receivedOn));
    }

    /** @return array<string, mixed> */
    public function discardOrder(int $order): array
    {
        return $this->model->write($this->purchasing()->discard($order, $this->table('order')));
    }

    /** @return array<string, mixed> */
    public function restoreOrder(int $order): array
    {
        return $this->model->write($this->purchasing()->restore($order, $this->table('order')));
    }

    /** @param list<int> $things each must be a record under the things' root */
    private function checkThings(array $things): void
    {
        if ($things === []) {
            return;
        }

        $tree  = $this->tree();
        $nodes = $this->model->nodesOf($things);

        foreach ($things as $thing) {
            if (! isset($nodes[$thing], $tree[$nodes[$thing]])) {
                throw StockError::notAThing($thing);
            }
        }
    }

    /** @param list<NewThing> $new each goes into a node under the things' root */
    private function checkGroups(array $new): void
    {
        $tree = $this->tree();

        foreach ($new as $thing) {
            if (! isset($tree[$thing->node])) {
                throw StockError::notAGroup($thing->node);
            }
        }
    }
}
