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
use TaxmodStock\Core\ReceiptChoice;
use TaxmodStock\Core\ReceiptPlan;
use TaxmodStock\Core\SourceRow;
use TaxmodStock\Core\SplitRow;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TestSettings;
use TaxmodStock\Core\Things;
use TaxmodStock\Core\ViewSettings;

/**
 * Reads the Stock tables once per request and hands bookings to the gateway — the seam between page and core.
 */
final class StockService
{
    public const OPTION = 'taxmod_stock_structure';

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

    /**
     * Things whose text or group name holds every word of the search — only in the groups of things, never in the Lager's own
     * branch, so a purchase or a lot is no hit.
     *
     * @return list<array{id: int, word: string, node: string}>
     */
    public function searchThings(string $term, int $limit = 30): array
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
        return new Ledger($this->structure(), $this->table('lot'), $this->table('line'));
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

        return (new ReceiptPlan($s))->of($order, $this->table('order'), $this->table('purchase'), Ledger::receivedPurchases($s, $this->table('lot')));
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

        foreach ($plan as $purchase => $row) {
            $thing  = $row['model'];
            $record = $thing === null ? null : $things->lagerOf($thing);
            $own    = $record !== null && $s->hasField('lager_location') ? (int) $lager->first($record, $s->field('lager_location')) : 0;

            $out[$purchase] = $rules->propose($own > 0 ? $own : null, $thing === null ? null : ($nodes[$thing] ?? 0));
        }

        return $out;
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

        return $this->model->write((new Bookings($this->structure(), $today))->inventory($named, $title));
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

        $changes = (new Bookings($s, $today))->receipt(
            $order,
            $choices,
            $this->table('order'),
            $this->table('purchase'),
            $this->table('money'),
            Ledger::receivedPurchases($s, $this->table('lot')),
            $title
        );

        return $this->model->write($changes);
    }

    /**
     * The material of some purchases kept without a receipt (the interim state of a goods receipt).
     *
     * @param array<int, int> $things purchase id → thing
     * @return array<string, mixed> the modeller's answer; nothing written when nothing changed
     */
    public function assignPurchases(int $order, array $things): array
    {
        $this->checkThings(array_values($things));
        $changes = (new Bookings($this->structure(), current_time('Y-m-d')))->assign($order, $things, $this->table('order'), $this->table('purchase'));

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

        return $this->model->write((new Bookings($this->structure(), current_time('Y-m-d')))->split($order, $purchase, $rows, $this->table('order'), $this->table('purchase'), $this->table('money')));
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

    /** @return array<string, mixed> */
    public function bookConsumption(int $thing, ?int $lot, string $quantity, ?int $project, string $today, string $title): array
    {
        $ledger = $this->ledger();
        $lot  ??= $ledger->proposedLot($thing);

        if ($ledger->thingOf($lot) !== $thing) {
            throw StockError::noLot($thing);
        }

        return $this->model->write((new Bookings($this->structure(), $today))->consumption($lot, $quantity, $project, $title));
    }

    /**
     * «Umbuchen»: the whole lot moves to another place. ⚠️ INFERRED: D-997 names the button, not whether part of a
     * lot can move; a part would need a lot of its own and is left for when he asks.
     *
     * @return array<string, mixed>
     */
    public function moveLot(int $lot, int $location): array
    {
        $s = $this->structure();

        if (! $this->table('lot')->has($lot)) {
            throw StockError::noLot($lot);
        }

        if (! $this->table('location')->has($location)) {
            throw StockError::notALocation($location);
        }

        return $this->model->write([['op' => 'set_values', 'record' => $lot, 'values' => [(string) $s->field('lot_location') => (string) $location]]]);
    }

    /** D-1027: purchasing — demand, source, order per supplier. */
    public function purchasing(): Purchasing
    {
        return new Purchasing($this->structure(), current_time('Y-m-d'));
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
        if ($firm !== null) {
            $names    = array_replace(...array_values($this->firms() ?: [[]]));
            $merchant = $names[$firm] ?? throw StockError::notAFirm($firm);
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
