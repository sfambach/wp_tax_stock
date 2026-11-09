<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\Tiles;
use TaxmodStock\Core\InboundOrder;
use TaxmodStock\Core\InboundStage;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\InboundService;

/**
 * The entry of the stock interface, «Logistik» in the Taxmod menu (D-1029, D-1053): what is due, as tiles with a count, each
 * opening the list behind it. Starter tiles only; own selections come with the cockpit of design section 6.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1053
 */
final class CockpitPage
{
    public const PAGE = Pages::COCKPIT;

    public function __construct(private readonly InboundService $inbound = new InboundService())
    {
    }

    /** The query key naming the area (and, below it, the group) whose overview the page shows instead of the cockpit (D-1128). */
    public const AREA  = 'area';
    public const GROUP = 'group';

    /** How many of the last created records a tile names (D-1134: «od er5»). */
    private const LATEST = 5;

    public function render(): string
    {
        $area  = sanitize_key((string) ($_GET[self::AREA] ?? ''));
        $group = sanitize_text_field(wp_unslash((string) ($_GET[self::GROUP] ?? '')));
        $found = $area === '' || $area === 'cockpit' ? null : self::areaOf($area);

        $title = $found === null ? __('Logistik', 'taxmod-stock') : $found['label'] . ($group === '' ? '' : ' › ' . str_replace('/', ' › ', $group));
        $html  = '<div class="wrap">' . ListPage::heading($title, (string) TAXMOD_STOCK_VERSION);

        try {
            // *The cockpit adds the key figures chosen on any node of the model (D-1149); an overview keeps to its items.*
            // D-1241: the period (D-1167) stands below what is due, on the same page; it is no longer an item of its own.
            $html .= $found === null
                ? $this->tiles($this->dueTiles()) . Tiles::metricsGrid()
                    . '<h2 id="' . esc_attr(PeriodPage::ANCHOR) . '" class="taxmod-tiles-heading">' . esc_html__('Zeitraum', 'taxmod-stock') . '</h2>'
                    . (new PeriodPage($this->inbound->stock()))->render()
                : $this->tiles($this->overviewTiles($found, $group));
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . '</div>';
    }

    /** The address of an area's overview, or of a group inside it (`$group` = its path, joined by «/»). */
    public static function overviewUrl(string $area, string $group = ''): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE, self::AREA => $area, self::GROUP => $group]), admin_url('admin.php'));
    }

    /** @return array{key: string, label: string, items: list<array<string, mixed>>}|null */
    private static function areaOf(string $key): ?array
    {
        foreach (LogisticsShell::navigation()->areas() as $area) {
            if ($area['key'] === $key) {
                return $area;
            }
        }

        return null;
    }

    /**
     * D-1128, his word «auf dem hauptknoten eine übersicht … mit abstprung»: one tile per item of the area or group, a group
     * below it as one tile opening its own overview; a count where the list behind it has one.
     *
     * @param array{key: string, label: string, items: list<array<string, mixed>>} $area
     * @return list<array{0: string, 1: int|null, 2: string}>
     */
    private function overviewTiles(array $area, string $group): array
    {
        $depth  = $group === '' ? 0 : count(explode('/', $group));
        $counts = $area['key'] === 'inbound' ? $this->stageCounts() : [];
        $tiles  = [];
        $seen   = [];

        foreach ($area['items'] as $item) {
            $path = array_values((array) ($item['path'] ?? []));

            if ($depth > 0 && implode('/', array_slice($path, 0, $depth)) !== $group) {
                continue;
            }

            if (count($path) > $depth) {
                $sub = implode('/', array_slice($path, 0, $depth + 1));

                if (! isset($seen[$sub])) {
                    $seen[$sub] = true;
                    $tiles[]    = [$path[$depth], null, self::overviewUrl($area['key'], $sub)];
                }
                continue;
            }

            [$count, $latest] = $this->recordsOf((string) $item['key']) ?? [$counts[(string) $item['tab']] ?? null, []];
            $tiles[]          = [(string) $item['label'], $count, LogisticsShell::url($item), $latest];
        }

        return $tiles;
    }

    /**
     * D-1134, his word «anzahl paltzarten zuletzt ersteltl ? plätze im layoout zuletzt erstellten 3 plätze ? … od er5»:
     * how many records an item lists and the last ones created (record ids rise, so the largest are the newest).
     *
     * @return array{0: int, 1: list<string>}|null null for an item that lists no records of its own
     */
    private function recordsOf(string $item): ?array
    {
        $names = match ($item) {
            'place-types' => $this->inbound->stock()->placeTypes()->names(),
            'structure'   => $this->inbound->stock()->places(),
            default       => null,
        };

        if ($names === null) {
            return null;
        }

        krsort($names);

        return [count($names), array_values(array_slice($names, 0, self::LATEST, true))];
    }

    /**
     * Kept by the modeller until a record changes (D-1198); the day is part of what is counted, because the archive moves
     * with it.
     *
     * @return array<string, int> orders per inbound stage, by the stage's tab
     */
    private function stageCounts(): array
    {
        if (! method_exists(Tiles::class, 'cachedCounts')) {
            return $this->countStages();
        }

        return Tiles::cachedCounts(Tiles::MODEL, 'stock.inbound.stages.' . current_time('Y-m-d'), fn (): array => $this->countStages());
    }

    /** @return array<string, int> */
    private function countStages(): array
    {
        $s       = $this->inbound->stock()->structure();
        $archive = $this->inbound->stock()->inboundArchive(current_time('Y-m-d'));
        $counts  = array_count_values(array_map(static fn (InboundOrder $o): string => $o->stage($s, $archive)->value, $this->inbound->orders()));

        if ($this->inbound->tests()->isConfigured()) {
            $counts['test'] = count($this->inbound->lotsToTest());
        }

        return $counts;
    }

    /** @return list<array{0: string, 1: int|null, 2: string}> */
    private function dueTiles(): array
    {
        $counts = $this->stageCounts();
        $tiles  = [
            [__('Angekommen, einbuchen', 'taxmod-stock'), $counts[InboundStage::Arrived->value] ?? 0, InboundPage::url(['tab' => InboundStage::Arrived->value])],
            [__('Unterwegs', 'taxmod-stock'), $counts[InboundStage::Shipped->value] ?? 0, InboundPage::url(['tab' => InboundStage::Shipped->value])],
            [__('Bestellt, nicht versendet', 'taxmod-stock'), $counts[InboundStage::Ordered->value] ?? 0, InboundPage::url(['tab' => InboundStage::Ordered->value])],
        ];

        if (isset($counts['test'])) {
            $tiles[] = [__('Zu testen', 'taxmod-stock'), $counts['test'], InboundPage::url(['tab' => 'test'])];
        }

        return $tiles;
    }

    /**
     * The modeller's shared tiles (D-1149): a count of 0 is the shrunk empty tile, the last created stand as lines.
     *
     * @param list<array{0: string, 1: int|null, 2: string, 3?: list<string>}> $tiles label, count (null: none), address, last created
     */
    private function tiles(array $tiles): string
    {
        $shared = [];

        foreach ($tiles as $tile) {
            [$label, $count, $url] = $tile;
            $latest                = $tile[3] ?? [];
            $shared[]              = Tiles::counted($label, $count, $url, $latest === [] ? [] : [__('Zuletzt angelegt:', 'taxmod-stock'), ...$latest]);
        }

        return Tiles::grid($shared);
    }
}
