<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

use TaxmodStock\Core\Contacts;
use TaxmodStock\Core\Bookings;
use TaxmodStock\Core\BuildPlan;
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
use TaxmodStock\Core\MaterialCopy;
use TaxmodStock\Core\NamedCopy;
use TaxmodStock\Core\LocationTree;
use TaxmodStock\Core\LabelTemplates;
use TaxmodStock\Core\OutputPlan;
use TaxmodStock\Core\Pack;
use TaxmodStock\Core\FitLevel;
use TaxmodStock\Core\PlaceFit;
use TaxmodStock\Core\PlaceTypes;
use TaxmodStock\Core\ProjectBill;
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

    private ?Structure $structure = null;

    /** @var array<int, array{name: string, parent: int, class: string}>|null the tree under the things' root, read once */
    private ?array $tree = null;

    private ?Things $things = null;

    private ?LocationTree $locationTree = null;

    private ?PlaceTypes $placeTypes = null;

    private ?LabelTemplates $labelTemplates = null;

    /** @var list<string>|null D-1097: the position lists the model holds as compositions, read once */
    private ?array $composed = null;

    public function __construct(private readonly ModelGateway $model = new ModelGateway())
    {
    }

    public function structure(): Structure
    {
        // Once per service: it was parsed from the option and the bundled file on every call, dozens of times a page (D-1195).
        if ($this->structure !== null) {
            return $this->structure;
        }

        $raw     = get_option(self::OPTION, []);
        $file    = dirname(__DIR__, 2) . '/structure-live.json';
        $bundled = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->structure = Structure::fromArray(Structure::completed(is_array($raw) ? $raw : [], is_array($bundled) ? $bundled : []));
    }

    /**
     * The same service, reading only what belongs to one thing (D-1195): its purchases, lots, lot lines and their transactions,
     * its Lager record, the orders and amounts of those purchases, and its own record — instead of every record of those
     * nodes. For the material view; a modeller without the narrow read gets the service unchanged.
     */
    public function scopedTo(int $thing): self
    {
        $s       = $this->structure();
        $refs    = fn (array $records, string $field): ?array => $s->hasField($field) ? $this->model->referrers($records, $s->field($field)) : [];
        $lots    = $refs([$thing], 'lot_thing');
        $bought  = $refs([$thing], 'purchase_model');
        $lager   = $refs([$thing], 'lager_thing');

        if ($lots === null || $bought === null || $lager === null) {
            return $this;
        }

        $lines   = $refs($lots, 'line_lot') ?? [];
        $deals   = $refs($lines, 'transaction_lines') ?? [];
        $orders  = $refs($bought, 'order_lines') ?? [];
        $scoped  = new self($this->model);
        $read    = fn (array $ids): Table => $ids === [] ? new Table([]) : ($this->model->records($ids) ?? new Table([]));
        $nodes   = $this->model->nodesOf([$thing]);

        $scoped->structure = $s;
        $scoped->tables    = [
            $s->node('purchase')    => $purchases = $read($bought),
            $s->node('lot')         => $lotTable = $read($lots),
            $s->node('line')        => $read($lines),
            $s->node('transaction') => $read($deals),
            $s->node('lager')       => $read($lager),
            $s->node('order')       => $read($orders),
        ] + $this->tables;

        // The amounts its purchases and lots name, read by id.
        $money = [];

        foreach (['purchase_unit_price', 'purchase_landed_price', 'purchase_total_price'] as $field) {
            if ($s->hasField($field)) {
                foreach ($purchases->ids() as $id) {
                    $money[] = (int) $purchases->first($id, $s->field($field));
                }
            }
        }

        if ($s->hasField('lot_value')) {
            foreach ($lotTable->ids() as $id) {
                $money[] = (int) $lotTable->first($id, $s->field('lot_value'));
            }
        }

        if ($s->hasNode('money')) {
            $scoped->tables[$s->node('money')] = $read(array_values(array_unique(array_filter($money))));
        }

        if (isset($nodes[$thing]) && ! isset($scoped->tables[$nodes[$thing]])) {
            $scoped->tables[$nodes[$thing]] = $read([$thing]);
        }

        return $scoped;
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

        // Nodes already read answer from memory; the rest in one read of just these records, not of their whole nodes (D-1195).
        $rest = [];

        foreach ($byNode as $node => $ids) {
            if (! isset($this->tables[$node])) {
                array_push($rest, ...$ids);

                continue;
            }

            foreach ($ids as $id) {
                $out[$id] = (string) $this->tables[$node]->first($id, $field);
            }
        }

        if ($rest !== []) {
            $table = $this->model->records(array_values(array_unique($rest))) ?? new Table([]);

            foreach ($rest as $id) {
                $out[$id] = (string) $table->first($id, $field);
            }
        }

        return $out;
    }

    /**
     * The one place that knows a record's picture (D-1204): today the field «Titelbild»; if pictures become one ordered
     * list, the first of it — and only this changes. One read for all records asked.
     *
     * @param list<int> $records
     * @return array<int, int> record → attachment id, 0 where there is none
     */
    public function titleImages(array $records): array
    {
        $s = $this->structure();

        if ($records === [] || ! $s->hasField('title_image')) {
            return array_fill_keys($records, 0);
        }

        $table = $this->model->records(array_values(array_unique($records))) ?? new Table([]);
        $out   = [];

        foreach ($records as $id) {
            $value    = (string) $table->first($id, $s->field('title_image'));
            $out[$id] = str_starts_with($value, 'media:') ? absint(substr($value, 6)) : 0;
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
     * (the Lager's own branch, and those he names) left out with everything under them. With `$pickUrl` it is the tree
     * fixed on the page (D-1174).
     */
    public function groupChooser(string $name, int $preselected = 0, string $form = '', string $pickUrl = ''): string
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
            // D-1174: with a pick address the tree stands on the page and a click opens it, «{id}» for the group.
            'inline'      => $pickUrl !== '',
            'pick_url'    => $pickUrl,
        ], static fn (mixed $v): bool => $v !== '' && $v !== 0 && $v !== [] && $v !== false) + ['name' => $name, 'root' => $root]);
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
        return implode(' ▸ ', $this->pathNodes($node));
    }

    /** @return array<int, string> node → name from the root down to a node, the root itself left out */
    public function pathNodes(int $node): array
    {
        $tree  = $this->tree();
        $names = [];

        for ($at = $node, $seen = []; isset($tree[$at]) && ! isset($seen[$at]); $at = $tree[$at]['parent']) {
            $seen[$at] = true;
            $names     = [$at => $tree[$at]['name']] + $names;
        }

        // The root itself is «Model» for every thing and says nothing.
        return array_slice($names, 1, null, true);
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
    public function bookReceipt(int $order, array $choices, string $today, string $title, array $quantities = []): array
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

        // His wish 2026-11-05: «Menge 1, geliefert wurden 40» — a quantity corrected in the receipt is written to the purchase
        // in the same step, and the receipt counts it.
        [$fix, $purchases] = $this->quantityChanges($quantities, array_map('intval', $this->table('order')->all($order, $s->field('order_lines'))));

        $changes = [...$fix, ...(new Bookings($this->structureWithKinds(), $today))->receipt(
            $order,
            $choices,
            $this->table('order'),
            $purchases,
            $this->table('money'),
            // D-1058: a purchase closed without stock counts as done for the order's status.
            Ledger::receivedPurchases($s, $this->table('lot')) + NotStocked::purchases($s, $this->table('purchase')),
            $title,
            $this->packsOf(array_values(array_filter(array_map(fn (int $purchase, ReceiptChoice $c): ?int => $c->keep ? (int) $this->table('purchase')->first($purchase, $s->field('purchase_model')) : $c->thing, array_keys($choices), $choices))))
        )];

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

    /** D-1153, D-1159: the labels kept as records; none while the model has no node for them. */
    public function labelTemplates(): LabelTemplates
    {
        $s = $this->structure();

        if (! $s->hasNode('label_template')) {
            return new LabelTemplates();
        }

        if ($this->labelTemplates !== null) {
            return $this->labelTemplates;
        }

        // D-1160: the labels stand in categories, the sub-nodes of «Etiketten» — read all of them in one go (CD-7).
        $root       = $s->node('label_template');
        $categories = $this->model->children($root);
        $nodeOf     = $this->model->recordsOf(array_merge([$root], array_keys($categories)), 500);
        $table      = $nodeOf === [] ? $this->table('label_template') : ($this->model->records(array_keys($nodeOf)) ?? $this->table('label_template'));

        // D-1191, D-1192: the printers and the rolls, one read each; a label goes to a printer whose loaded roll allows it.
        $printers = $s->hasNode('label_printer') ? $this->table('label_printer') : null;
        $rolls    = $s->hasNode('label_roll') ? $this->table('label_roll') : null;

        return $this->labelTemplates = LabelTemplates::from($s, $table, array_map(static fn (int $node): string => $categories[$node] ?? '', $nodeOf), $nodeOf, $printers, $rolls);
    }

    /** @return array<int, string> D-1192: every roll by its name — what «Rolle wechseln» offers */
    public function rolls(): array
    {
        return $this->structure()->hasNode('label_roll') ? $this->labels('label_roll') : [];
    }

    /**
     * D-1192, his word 2026-11-06: «rolle wechseln funktion fragt ab welche neue rolle eingelegt wurde» — the printer now holds
     * that roll; one write, undone by changing back.
     */
    public function changeRoll(int $printer, int $roll): void
    {
        $s = $this->structure();
        if (! $s->hasNode('label_printer') || ! $s->hasField('printer_loaded_roll') || ! $this->table('label_printer')->has($printer) || ! isset($this->rolls()[$roll])) {
            throw StockError::nothingToBook();
        }

        $this->model->write([['op' => 'set_values', 'record' => (string) $printer, 'values' => [(string) $s->field('printer_loaded_roll') => (string) $roll]]]);
    }

    /**
     * D-1178: the packs of the things handled in packs; the others are left out.
     *
     * @param list<int> $things
     * @return array<int, Pack>
     */
    public function packsOf(array $things): array
    {
        $s = $this->structure();

        if (! $s->hasField('lager_pack_content')) {
            return [];
        }

        $known = $this->things();
        $lager = $this->table('lager');
        $out   = [];

        foreach (array_unique($things) as $thing) {
            $pack = Pack::of($s, $lager, $known->lagerOf($thing));

            if ($pack !== null) {
                $out[$thing] = $pack;
            }
        }

        return $out;
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
     * D-1158, D-1169: the places of $places whose kind does not fit into the kind of $parent, by the calculation that kind
     * chooses. A place without sizes, or one in other units, is left out.
     *
     * @param list<int> $places
     * @return list<int>
     */
    public function misfits(array $places, int $parent): array
    {
        $into = $this->typeOfPlace($parent);

        if ($into <= 0) {
            return [];
        }

        $fit = $this->placeFit();

        return array_values(array_filter($places, fn (int $place): bool => $fit->fits($this->typeOfPlace($place), $into) === false));
    }

    /** D-1158, D-1169: whether a new place of $type fits into $parent; null where nothing can be said. */
    public function fitsNew(int $type, int $parent): ?bool
    {
        $into = $this->typeOfPlace($parent);

        return $type <= 0 || $into <= 0 ? null : $this->placeFit()->fits($type, $into);
    }

    /** D-1169: how loudly the kind of $parent says that something does not fit. */
    public function fitLevelOf(int $parent): FitLevel
    {
        $into = $this->typeOfPlace($parent);

        return $into > 0 ? $this->placeFit()->levelOf($into) : FitLevel::Warning;
    }

    /** D-1169: refuses before anything is written when the kind of $parent says «Fehler» and something does not fit. */
    private function guardFit(array $places, int $type, int $parent): void
    {
        if ($this->typeOfPlace($parent) <= 0 || $this->fitLevelOf($parent) !== FitLevel::Error) {
            return;
        }

        if ($type > 0 ? $this->fitsNew($type, $parent) === false : $this->misfits($places, $parent) !== []) {
            throw StockError::doesNotFit($parent);
        }
    }

    private function typeOfPlace(int $place): int
    {
        $s = $this->structure();

        if ($place <= 0 || ! $s->hasNode('location_type') || ! $s->hasNode('dimension') || ! $s->hasField('location_place_type')) {
            return 0;
        }

        return (int) $this->table('location')->first($place, $s->field('location_place_type'));
    }

    private function placeFit(): PlaceFit
    {
        $s = $this->structure();

        return PlaceFit::from($s, $this->table('location_type'), $this->table('dimension'), $this->table('money'));
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

    /**
     * D-1130: «Kopieren» of a kind of place, under its new name.
     *
     * @return int the copy
     */
    public function copyPlaceType(int $type, string $name): int
    {
        $answer = $this->model->write((new LocationChanges($this->structure()))->copyType($type, $name, $this->placeTypes()));

        return (int) ($answer['refs']['kopie'] ?? 0);
    }

    /**
     * D-1130: «Kopieren» of a label template — layout and copies come along, the format stays the one it points at.
     *
     * @return int the copy
     */
    public function copyLabelTemplate(int $template, string $name): int
    {
        if (! $this->structure()->hasNode('label_template') || ! $this->table('label_template')->has($template)) {
            throw StockError::nothingToBook();
        }

        $answer = $this->model->write((new NamedCopy($this->structure()))->of($template, $name));

        return (int) ($answer['refs']['kopie'] ?? 0);
    }

    /**
     * D-1186, A4: the one way to choose a place — the modeller's record chooser over the place tree, with its search; a plain
     * select only while the model has no tree of places or the modeller offers no chooser. Empty means «none» (or the top level).
     *
     * @param list<int> $exclude places left out with everything under them
     */
    public function placeChooser(string $name, int $chosen = 0, string $form = '', array $exclude = []): string
    {
        try {
            $s = $this->structure();
            $this->places();
        } catch (StockError) {
            return '<select name="' . esc_attr($name) . '" disabled><option value="">—</option></select>';
        }

        if ($s->hasField('location_parent')) {
            $choice = (string) apply_filters('taxmod_record_chooser', '', array_filter([
                'name'         => $name,
                'node'         => $s->node('location'),
                'parent_field' => $s->field('location_parent'),
                'label_field'  => $this->placeWords(),
                'exclude'      => $exclude,
                'preselected'  => $chosen,
                'form'         => $form,
            ], static fn (mixed $v): bool => $v !== '' && $v !== 0 && $v !== []));

            if ($choice !== '') {
                return $choice;
            }
        }

        $html   = '<select name="' . esc_attr($name) . '"' . ($form === '' ? '' : ' form="' . esc_attr($form) . '"') . '><option value="">—</option>';
        $hidden = [];

        foreach ($exclude as $place) {
            $hidden = [...$hidden, ...$this->locationTree()->withAllBelow($place)];
        }

        foreach (array_diff_key($this->places(), array_flip($hidden)) as $id => $label) {
            $html .= '<option value="' . esc_attr((string) $id) . '"' . selected($chosen, $id, false) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * D-1150: the fields that name a place in the modeller's tree — its short mark, then its Bezeichnung.
     *
     * @return int|list<int>
     */
    public function placeWords(): int|array
    {
        $s = $this->structure();

        return $s->hasField('location_short') ? [$s->field('location_short'), $s->field('label')] : $s->field('label');
    }

    /** @return array<int, string> every place by its path and project, in tree order — what a place chooser offers */
    public function places(): array
    {
        return $this->locationTree()->labels();
    }

    /**
     * D-1139: every place that names a project itself, with that project.
     *
     * @return array<int, int> place → project record
     */
    public function projectBoxes(): array
    {
        $s = $this->structure();

        if (! $s->hasField('location_project')) {
            return [];
        }

        $places = $this->table('location');
        $out    = [];

        foreach ($places->ids() as $id) {
            $project = (int) $places->first($id, $s->field('location_project'));

            if ($project > 0) {
                $out[$id] = $project;
            }
        }

        return $out;
    }

    /** D-1139: a project's parts list; null while the model holds no way from a project to one. */
    public function projectBill(int $project): ?ProjectBill
    {
        $s = $this->structure();

        if (! ProjectBill::isModelled($s) || ! $s->hasNode('project') || ! $s->hasNode('board') || ! $s->hasNode('board_revision') || ! $s->hasNode('bom_position')) {
            return null;
        }

        return ProjectBill::of($s, $this->table('project'), $this->table('board'), $this->table('board_revision'), $this->table('bom_position'), $project);
    }

    /** @return array<int, float> D-1139: thing → pieces lying on the place and on every place in it */
    public function presentIn(int $place): array
    {
        return ProjectBill::present($this->ledger(), $this->locationTree()->withAllBelow($place));
    }

    /**
     * D-1199: the names of what lies on each place and on every place in it, for its label — the materials and the categories
     * they stand in. One ledger, one read for the nodes and one per node for the names, however many places (CD-7).
     *
     * @param list<int> $places
     * @return array<int, array{categories: list<string>, materials: list<string>}>
     */
    public function placeContents(array $places): array
    {
        $ledger = $this->ledger();
        $tree   = $this->locationTree();
        $byPlace = [];

        foreach ($places as $place) {
            $byPlace[$place] = array_keys(array_filter(ProjectBill::present($ledger, $tree->withAllBelow($place)), static fn (float $pieces): bool => $pieces > 0));
        }

        $things = array_values(array_unique(array_merge([], ...array_values($byPlace))));
        $nodes  = $things === [] ? [] : $this->nodesOf($things);
        $names  = $things === [] ? [] : $this->recordLabels($things, $nodes);
        $model  = $things === [] ? [] : $this->tree();
        $out    = [];

        foreach ($byPlace as $place => $ids) {
            $out[$place] = [
                'categories' => array_values(array_unique(array_map(static fn (int $thing): string => (string) ($model[$nodes[$thing] ?? 0]['name'] ?? ''), $ids))),
                'materials'  => array_values(array_map(static fn (int $thing): string => $names[$thing] ?? '', $ids)),
            ];
        }

        return $out;
    }

    /** @return array<int, string> the projects a box can belong to; empty while the structure names no project node */
    public function projects(): array
    {
        return $this->structure()->hasNode('project') ? $this->labels('project') : [];
    }

    /** @return array<string, mixed> the modeller's answer */
    public function createPlace(string $name, int $parent, bool $movable, int $project, bool $reserved = false, int $type = 0): array
    {
        $this->guardFit([], $type, $parent);

        return $this->model->write((new LocationChanges($this->structure()))->create($name, $parent, $movable, $project, $this->locationTree(), $this->table('location'), $reserved, $type, $this->placeTypes()));
    }

    /**
     * D-1130: «Kopieren» of a place, with its places inside or without; nothing that lies there comes along.
     *
     * @return int the copy of the place
     */
    public function copyPlace(int $place, string $name, bool $withBelow): int
    {
        $answer = $this->model->write((new LocationChanges($this->structure()))->copy($place, $name, $withBelow, $this->locationTree(), $this->table('location')));

        return (int) ($answer['refs']['kopie0'] ?? 0);
    }

    /**
     * D-1130: «Kopieren» of a material — its data and its Lager record, no stock; the sources without their numbers.
     *
     * @return int the copy of the thing
     */
    public function copyThing(int $thing): int
    {
        $this->checkThings([$thing]);

        $s      = $this->structure();
        $lager  = $this->things()->lagerOf($thing);
        $node   = (int) ($this->nodesOf([$thing])[$thing] ?? 0);
        $own    = $node > 0 ? $this->tableOf($node)->first($thing, $s->field('label')) : null;
        $label  = $own === null || trim($own) === '' ? null : sprintf(/* translators: %s: the name of the original */ __('%s (Kopie)', 'taxmod-stock'), $own);
        $answer = $this->model->write((new MaterialCopy($s))->copy($thing, $lager, $this->table('lager'), $this->sourcesOf($lager), $label));

        return (int) ($answer['refs']['kopie'] ?? 0);
    }

    /**
     * «Umhängen»: the place with all it holds; into a reserved area only what is not reserved yet (D-1082).
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function movePlace(int $place, int $parent, string $today = '', string $title = ''): array
    {
        $this->guardFit([$place], 0, $parent);
        $tree    = $this->locationTree();
        $changes = (new LocationChanges($this->structure()))->move($place, $parent, $tree, $this->table('location'));
        $this->reservedPlaces()->checkHang($place, $parent, $this->ledger(), $this->boundLots());

        // D-1091: a box that moves is a Vorgang «Umlagerung» in the same step; a fixed place is a change of the structure only.
        if ($this->relocates() && $tree->isMovable($place) && $tree->parentOf($place) !== $parent) {
            $changes = [...$changes, ...(new Bookings($this->structureWithKinds(), $today === '' ? current_time('Y-m-d') : $today))->relocate($place, $tree->parentOf($place), $parent, $title === '' ? 'Umlagerung' : $title)];
        }

        return $this->model->write($changes);
    }

    /**
     * «Verschieben» for the places ticked in a place's list of Unterorte: each is hung under $parent with all it holds, in
     * one write; a box among them is a Vorgang «Umlagerung» of its own (D-1091). Places already there are left alone.
     *
     * @param list<int> $places
     * @return array<string, mixed>
     */
    public function movePlaces(array $places, int $parent, string $today, string $title): array
    {
        $this->guardFit($places, 0, $parent);
        $tree     = $this->locationTree();
        $table    = $this->table('location');
        $rules    = new LocationChanges($this->structure());
        $flags    = $this->reservedPlaces();
        $ledger   = $this->ledger();
        $bound    = $this->boundLots();
        $relocate = $this->relocates();
        $bookings = $relocate ? new Bookings($this->structureWithKinds(), $today === '' ? current_time('Y-m-d') : $today) : null;
        $changes  = [];

        foreach (array_values(array_unique($places)) as $place) {
            if ($tree->parentOf($place) === $parent && $table->has($place)) {
                continue;
            }

            $changes = [...$changes, ...$rules->move($place, $parent, $tree, $table)];
            $flags->checkHang($place, $parent, $ledger, $bound);

            if ($bookings !== null && $tree->isMovable($place)) {
                $changes = [...$changes, ...$bookings->relocate($place, $tree->parentOf($place), $parent, $title === '' ? 'Umlagerung' : $title)];
            }
        }

        if ($changes === []) {
            throw StockError::nothingToBook();
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
    public function assignPurchases(int $order, array $things, array $places = [], array $new = [], array $quantities = []): array
    {
        $this->checkThings(array_values(array_filter($things)));
        $things = $new + $things;

        $this->checkGroups(array_values(array_filter($things, static fn (int|NewThing $t): bool => $t instanceof NewThing)));
        $inOrder = array_map('intval', $this->table('order')->all($order, $this->structure()->field('order_lines')));
        $changes = [...$this->quantityChanges($quantities, $inOrder)[0], ...(new Bookings($this->structureWithKinds(), current_time('Y-m-d')))->assign($order, $things, $this->table('order'), $this->table('purchase'))];
        $this->planPlaces($places);

        return $changes === [] ? ['applied' => []] : $this->model->write($changes);
    }

    /**
     * The purchase quantities typed in the receipt that differ from the stored ones: their writes, and the purchases as the
     * booking should read them. Only purchases of $within count; a quantity below 1 is refused.
     *
     * @param array<int, string> $quantities purchase → typed quantity
     * @param list<int>          $within
     * @return array{0: list<array<string, mixed>>, 1: Table}
     */
    private function quantityChanges(array $quantities, array $within): array
    {
        $s         = $this->structure();
        $purchases = $this->table('purchase');
        $changes   = [];
        $allowed   = array_flip($within);

        foreach ($quantities as $purchase => $typed) {
            $typed = trim($typed);

            if ($typed === '' || ! isset($allowed[$purchase])) {
                continue;
            }

            if (! ctype_digit($typed) || (int) $typed < 1) {
                throw StockError::notAQuantity($typed);
            }

            if ((string) (int) $purchases->first($purchase, $s->field('purchase_quantity')) === $typed) {
                continue;
            }

            $changes[] = ['op' => 'set_values', 'record' => (string) $purchase, 'values' => [(string) $s->field('purchase_quantity') => $typed]];
            $purchases = $purchases->with($purchase, $s->field('purchase_quantity'), $typed);
        }

        return [$changes, $purchases];
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
     * D-1182: the firms as the modeller's record tree — every node under «Firma» with its depth and its own firms, for the tree chooser
     * with «New here …». Hidden nodes are left out.
     *
     * @return list<array{depth: int, name: string, records: array<int, string>, node: int}>
     */
    public function firmTree(): array
    {
        $s = $this->structure();

        if (! $s->hasNode('firm')) {
            return [];
        }

        $name  = $s->hasField('firm_name') ? $s->field('firm_name') : $s->field('label');
        $tiefe = [];
        $out   = [];

        foreach ($this->model->tree($s->node('firm')) as $node => $row) {
            $tiefe[$node] = isset($tiefe[$row['parent']]) ? $tiefe[$row['parent']] + 1 : 0;

            if ($row['hidden']) {
                continue;
            }

            $table   = $this->tableOf($node);
            $records = [];

            foreach ($table->ids() as $id) {
                $records[$id] = (string) ($table->first($id, $name) ?? '#' . $id);
            }

            asort($records, SORT_NATURAL | SORT_FLAG_CASE);
            $out[] = ['depth' => $tiefe[$node], 'name' => $row['name'], 'records' => $records, 'node' => (int) $node];
        }

        return $out;
    }

    /** D-1184: the root of the contact branch — its own node, or the firms' node on a structure that does not name it. */
    public function contactRoot(): int
    {
        $s = $this->structure();

        return $s->hasNode('contact') ? $s->node('contact') : ($s->hasNode('firm') ? $s->node('firm') : 0);
    }

    /**
     * D-1184: the contact branch, one read-tree.
     *
     * @return array<int, array{name: string, parent: int, class: string, hidden: bool}>
     */
    public function contactTree(): array
    {
        $root = $this->contactRoot();

        return $root > 0 ? $this->model->tree($root) : [];
    }

    /**
     * D-1184: the contacts under `$node` with the number of stock sources naming each — one read per node and one of the sources (CD-7).
     *
     * @return list<array{id: int, name: string, node: int, group: string, sources: int}>
     */
    public function contacts(int $node, string $search = ''): array
    {
        $s    = $this->structure();
        $tree = $this->contactTree();
        $root = $this->contactRoot();

        if (! isset($tree[$node])) {
            return [];
        }

        $name    = $s->hasField('firm_name') ? $s->field('firm_name') : $s->field('label');
        $first   = $s->hasField('contact_first_name') ? $s->field('contact_first_name') : 0;
        $records = [];

        foreach (array_keys(Contacts::branch($tree, $node)) as $at) {
            $table = $this->tableOf($at);

            foreach ($table->ids() as $id) {
                $records[$at][$id] = Contacts::displayName((string) ($table->first($id, $name) ?? ''), $first > 0 ? (string) ($table->first($id, $first) ?? '') : '', $id);
            }
        }

        return Contacts::rows($tree, $root, $node, $records, $this->sourceCounts(), $search);
    }

    /**
     * D-1184: contact → how many sources («Bezugsquellen») of the stock name it.
     *
     * @return array<int, int>
     */
    private function sourceCounts(): array
    {
        $s = $this->structure();

        if (! $s->hasNode('source') || ! $s->hasField('source_firm')) {
            return [];
        }

        $parts = $this->table('source');
        $out   = [];

        foreach ($parts->ids() as $part) {
            $firm = (int) ($parts->first($part, $s->field('source_firm')) ?? 0);

            if ($firm > 0) {
                $out[$firm] = ($out[$firm] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * D-1184: the materials whose sources name this contact, thing → name, for the row's details.
     *
     * @return array<int, string>
     */
    public function suppliedBy(int $contact): array
    {
        $s = $this->structure();

        if (! $s->hasNode('source') || ! $s->hasField('source_firm') || ! $s->hasField('lager_sources')) {
            return [];
        }

        $parts = $this->table('source');
        $lager = $this->table('lager');
        $mine  = [];

        foreach ($parts->ids() as $part) {
            if ((int) ($parts->first($part, $s->field('source_firm')) ?? 0) === $contact) {
                $mine[$part] = true;
            }
        }

        $things = [];

        foreach ($mine === [] ? [] : $lager->ids() as $record) {
            foreach ($lager->all($record, $s->field('lager_sources')) as $part) {
                if (isset($mine[(int) $part]) && ($thing = (int) ($lager->first($record, $s->field('lager_thing')) ?? 0)) > 0) {
                    $things[] = $thing;
                }
            }
        }

        $names = $this->recordLabels(array_values(array_unique($things)));
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * D-1184: a new contact in a node of the contact branch, by its name (and a person's first name); the rest is filled in the editor.
     *
     * @return int the new record
     */
    public function createContact(int $node, string $name, string $firstName = ''): int
    {
        $s    = $this->structure();
        $name = trim($name);

        if ($name === '' || ! $s->hasField('firm_name')) {
            throw StockError::contactWithoutName();
        }

        if (Contacts::branch($this->contactTree(), $node) === [] || $node === $this->contactRoot()) {
            throw StockError::notAContactGroup($node);
        }

        $values = [(string) $s->field('firm_name') => $name];

        if (trim($firstName) !== '' && $this->hasContactFirstName($node)) {
            $values[(string) $s->field('contact_first_name')] = trim($firstName);
        }

        $answer = $this->model->write([
            ['op' => 'create_record', 'ref' => 'kontakt', 'node' => (string) $node],
            ['op' => 'set_values', 'record' => '@kontakt', 'values' => $values],
        ]);

        return (int) ($answer['refs']['kontakt'] ?? 0);
    }

    /** D-1184: whether the node's contacts carry a first name (a person). */
    public function hasContactFirstName(int $node): bool
    {
        $s = $this->structure();

        return $s->hasField('contact_first_name') && isset($this->model->fields($node)[$s->field('contact_first_name')]);
    }

    /**
     * D-1010 for D-1184: the contact just created is taken back again.
     *
     * @return array<string, mixed> the modeller's answer
     */
    public function removeContact(int $record): array
    {
        return $this->model->write([['op' => 'remove_record', 'record' => $record]]);
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
     * D-1146, for the manufacturing module (D-1142): what a build order takes from its box (more than 0) or gives back
     * (less than 0), in one Vorgang «Verbrauch» whose Bezug is the order. The box's own reservation (D-1082) is not checked:
     * a box reserved for production is there to be built from.
     *
     * @param array<int, float> $lines thing → pieces
     * @return int the Vorgang
     */
    public function bookBuild(int $reference, int $place, array $lines, string $today, string $title): int
    {
        if ($reference <= 0) {
            throw StockError::noSubject();
        }

        if (! $this->table('location')->has($place)) {
            throw StockError::notALocation($place);
        }

        $this->checkThings(array_keys(array_filter($lines, static fn (float $q): bool => $q > 0.0)));
        $rows   = BuildPlan::rows($this->ledger(), $place, $this->locationTree()->withAllBelow($place), $lines, $this->takenFor($reference));
        return $this->dealOf($this->model->write((new Bookings($this->structureWithKinds(), $today))->build($rows, $reference, $title)));
    }

    /**
     * D-1143: the finished things of a build order go into stock at $place — thing → pieces, less than 0 takes them back
     * from what this order brought in. Answers the Vorgang's id.
     *
     * @param array<int, float> $lines
     */
    public function bookOutput(int $reference, int $place, array $lines, string $today, string $title, bool $tested = false): int
    {
        if ($reference <= 0) {
            throw StockError::noSubject();
        }

        if (! $this->table('location')->has($place)) {
            throw StockError::notALocation($place);
        }

        $lines = array_filter($lines, static fn (float $q): bool => abs($q) > 1e-9);

        if ($lines === []) {
            throw StockError::nothingToBook();
        }

        $this->checkThings(array_keys($lines));
        $plan   = OutputPlan::rows($lines, $this->producedFor($reference));
        $labels = $this->recordLabels(array_column($plan['new'], 'thing'));
        $new    = array_map(static fn (array $r): array => $r + ['name' => $labels[$r['thing']] ?? $title], $plan['new']);

        return $this->dealOf($this->model->write((new Bookings($this->structureWithKinds(), $today))->output($new, $plan['back'], $place, $reference, $title, $tested)));
    }

    /**
     * D-1143: a new material in a group of the things' tree, by its name — for the finished board a build order brings in.
     */
    public function createThing(string $name, int $group): int
    {
        $this->checkGroups([new NewThing($group, $name)]);
        $answer = $this->model->write([
            ['op' => 'create_record', 'ref' => 'd', 'node' => (string) $group],
            ['op' => 'set_values', 'record' => '@d', 'values' => [(string) $this->structure()->field('label') => trim($name)]],
        ]);

        return (int) (((array) ($answer['refs'] ?? []))['d'] ?? 0) ?: throw StockError::nothingToBook();
    }

    /**
     * D-1146, the pick list of a build order: where the things lie free — not in the box `$except` or below it, not in a
     * reserved area (D-1082), not in a lot a reservation names (D-1083).
     *
     * @param list<int> $things
     * @return array<int, array<int, float>> thing → place → pieces, only more than nothing
     */
    public function freeStockByPlace(array $things, int $except = 0): array
    {
        $wanted   = array_flip($things);
        $skip     = $except > 0 ? array_flip($this->locationTree()->withAllBelow($except)) : [];
        $ledger   = $this->ledger();
        $reserved = $this->reservedPlaces();
        $bound    = $this->boundLots();
        $out      = [];

        foreach ($ledger->stockByPlace() as $lot => $at) {
            $thing = $ledger->thingOf($lot);

            if ($thing === null || ! isset($wanted[$thing]) || isset($bound[$lot])) {
                continue;
            }

            foreach ($at as $place => $quantity) {
                if (! isset($skip[$place]) && $reserved->reservingPlaceOf($place) === null) {
                    $out[$thing][$place] = ($out[$thing][$place] ?? 0.0) + $quantity;
                }
            }
        }

        return $out;
    }

    /**
     * D-1146: the picked lines go into the box, one Umbuchung naming the order; at each place the oldest free lot first.
     *
     * @param list<array{thing: int, from: int, quantity: float}> $lines
     * @return int the Vorgang
     */
    public function bookPick(int $reference, int $box, array $lines, string $today, string $title): int
    {
        if ($reference <= 0) {
            throw StockError::noSubject();
        }

        if (! $this->table('location')->has($box)) {
            throw StockError::notALocation($box);
        }

        $ledger = $this->ledger();
        $bound  = $this->boundLots();
        $rows   = [];

        foreach ($lines as $line) {
            $left = $line['quantity'];

            foreach ($ledger->lotsOf($line['thing']) as $lot) {
                $there = isset($bound[$lot]) ? 0.0 : $ledger->restAt($lot, $line['from']);

                if ($left > 0.000001 && $there > 0.000001) {
                    $part   = min($there, $left);
                    $rows[] = new TransferRow($lot, $line['from'], $box, rtrim(rtrim(number_format($part, 6, '.', ''), '0'), '.'));
                    $left  -= $part;
                }
            }

            if ($left > 0.000001) {
                throw StockError::notEnoughToPick($line['thing'], $line['from']);
            }
        }

        return $this->dealOf($this->model->write((new Bookings($this->structureWithKinds(), $today))->transfer($rows, $title, $ledger, '', $this->reservedPlaces(), $bound, $reference)));
    }

    /** @param array<string, mixed> $answer the modeller's answer to a booking @return int the Vorgang it created */
    private function dealOf(array $answer): int
    {
        foreach ((array) ($answer['refs'] ?? []) as $ref => $id) {
            if (preg_match('/^v\d+$/', (string) $ref) === 1) {
                return (int) $id;
            }
        }

        throw StockError::nothingToBook();
    }

    /** @return array<int, array<int, array<int, float>>> thing → lot → place → pieces the receipts naming `$reference` brought in and still lie there */
    private function producedFor(int $reference): array
    {
        $s = $this->structure();

        if (! $s->hasField('transaction_reference')) {
            return [];
        }

        $deals  = $this->table('transaction');
        $lines  = $this->table('line');
        $ledger = $this->ledger();
        $lots   = [];

        foreach ($deals->ids() as $deal) {
            if ((int) $deals->first($deal, $s->field('transaction_reference')) !== $reference || (int) $deals->first($deal, $s->field('transaction_kind')) !== $s->value('kind_receipt')) {
                continue;
            }

            foreach ($deals->all($deal, $s->field('transaction_lines')) as $line) {
                $lots[(int) $lines->first((int) $line, $s->field('line_lot'))] = true;
            }
        }

        $stock = $ledger->stockByPlace();
        $out   = [];

        foreach (array_keys($lots) as $lot) {
            $thing = $ledger->thingOf($lot);

            foreach ($thing === null ? [] : ($stock[$lot] ?? []) as $place => $quantity) {
                if ($quantity > 0.0) {
                    $out[$thing][$lot][$place] = $quantity;
                }
            }
        }

        return $out;
    }

    /** @return array<int, array<int, float>> thing → lot → pieces the Vorgänge naming `$reference` took, net of what came back */
    private function takenFor(int $reference): array
    {
        $s = $this->structure();

        if (! $s->hasField('transaction_reference')) {
            return [];
        }

        $deals  = $this->table('transaction');
        $lines  = $this->table('line');
        $ledger = $this->ledger();
        $out    = [];

        foreach ($deals->ids() as $deal) {
            if ((int) $deals->first($deal, $s->field('transaction_reference')) !== $reference || (int) $deals->first($deal, $s->field('transaction_kind')) !== $s->value('kind_consumption')) {
                continue;
            }

            foreach ($deals->all($deal, $s->field('transaction_lines')) as $line) {
                $lot   = (int) $lines->first((int) $line, $s->field('line_lot'));
                $thing = $ledger->thingOf($lot);

                if ($thing !== null) {
                    $out[$thing][$lot] = ($out[$thing][$lot] ?? 0.0) - (float) ($lines->first((int) $line, $s->field('line_quantity')) ?? 0);
                }
            }
        }

        return $out;
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
     * D-1168: the lots that still hold something, as a resend offers them — «Material · Datum · Rest n».
     *
     * @return array<int, string>
     */
    public function lotChoices(): array
    {
        $ledger = $this->ledger();
        $labels = $this->things()->all();
        $lots   = $this->table('lot');
        $date   = $this->structure()->field('lot_date');
        $out    = [];

        foreach (array_intersect_key($labels, array_flip($ledger->things())) as $thing => $label) {
            foreach ($ledger->lotsOf($thing) as $lot) {
                $rest = $ledger->restOf($lot);

                if ($rest > 0.0) {
                    $out[$lot] = $label . ' · ' . substr((string) $lots->first($lot, $date), 0, 10) . ' · ' . __('Rest', 'taxmod-stock') . ' ' . rtrim(rtrim(number_format($rest, 3, ',', ''), '0'), ',');
                }
            }
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * D-1168: goods sent a second time are a loss — a consumption from `$lot` with the sale as its Bezug (when the
     * structure knows sales) and the reason as its Grund. The same checks as any consumption (D-1088).
     */
    public function bookLoss(int $lot, string $quantity, int $sale, string $reason, string $today, string $title): int
    {
        $ledger = $this->ledger();
        $thing  = $ledger->thingOf($lot);

        if ($thing === null || $thing <= 0) {
            throw StockError::noLot(0);
        }

        // The piece went out: more than the lot holds cannot have gone.
        if ((float) \TaxmodStock\Core\Quantity::positive($quantity) > $ledger->restOf($lot)) {
            throw StockError::lossTooLarge($lot);
        }

        $known   = array_merge(...array_map('array_keys', array_values($this->purposes())));
        $purpose = $sale > 0 && in_array($sale, $known, true) ? Purpose::subject($sale) : Purpose::none();

        return $this->dealOf($this->bookConsumption($thing, $lot, $quantity, $purpose, $today, $title, null, $reason));
    }

    /** D-1168: the Storno of a loss booked by {@see bookLoss()}. */
    public function cancelLoss(int $deal, string $now, string $who): int
    {
        return $this->dealOf($this->cancelConsumption($deal, $now, $who));
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
     * D-1181: «Umbuchen» of a line standing for several lots of the same data at one place — the quantity from them, oldest
     * lot first, as one Vorgang «Umbuchung».
     *
     * @param list<int> $lots
     * @return array<string, mixed>
     */
    public function moveLots(array $lots, int $from, int $location, string $quantity, string $today, string $title): array
    {
        if (! $this->table('location')->has($location)) {
            throw StockError::notALocation($location);
        }

        $ledger = $this->ledger();
        $left   = (float) str_replace(',', '.', $quantity);
        $rows   = [];
        sort($lots);

        foreach ($lots as $lot) {
            if (! $this->table('lot')->has($lot)) {
                throw StockError::noLot($lot);
            }

            $take = min($left, $ledger->restAt($lot, $from));

            if ($take > 0.0) {
                $rows[] = new TransferRow($lot, $from, $location, rtrim(rtrim(number_format($take, 6, '.', ''), '0'), '.'));
                $left  -= $take;
            }
        }

        if ($rows === [] || $left > 1e-9) {
            throw StockError::nothingToBook();
        }

        return $this->model->write((new Bookings($this->structureWithKinds(), $today))->transfer($rows, $title, $ledger, '', $this->reservedPlaces(), $this->boundLots()));
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
     * D-1130: «Nochmal bestellen» — the materials and quantities of an order or demand as a new demand; the original stays as
     * it is. A position without a material has nothing to order again and stays behind.
     *
     * @return array<string, mixed>
     */
    public function reorderOrder(int $order): array
    {
        $s         = $this->structure();
        $orders    = $this->table('order');
        $purchases = $this->table('purchase');

        if (! $orders->has($order)) {
            throw StockError::notADemand($order);
        }

        $quantities = [];

        foreach ($orders->all($order, $s->field('order_lines')) as $line) {
            $thing = (int) ($purchases->first((int) $line, $s->field('purchase_model')) ?? 0);

            if ($thing > 0) {
                $quantities[$thing] = (string) ((int) ($quantities[$thing] ?? 0) + max(1, (int) ($purchases->first((int) $line, $s->field('purchase_quantity')) ?? 1)));
            }
        }

        $label = trim((string) $orders->first($order, $s->field('label')));

        return $this->reorder($quantities, null, sprintf(/* translators: %s: name of the original order */ __('%s (nochmal)', 'taxmod-stock'), $label === '' ? '#' . $order : $label));
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
