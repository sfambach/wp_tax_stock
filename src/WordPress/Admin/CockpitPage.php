<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
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
    public const PAGE = 'taxmod-lager-cockpit';

    public function __construct(private readonly InboundService $inbound = new InboundService())
    {
    }

    /** The query key naming the area (and, below it, the group) whose overview the page shows instead of the cockpit (D-1128). */
    public const AREA  = 'area';
    public const GROUP = 'group';

    public function render(): string
    {
        $area  = sanitize_key((string) ($_GET[self::AREA] ?? ''));
        $group = sanitize_text_field(wp_unslash((string) ($_GET[self::GROUP] ?? '')));
        $found = $area === '' || $area === 'cockpit' ? null : self::areaOf($area);
        $title = $found === null ? __('Logistik', 'taxmod-stock') : $found['label'] . ($group === '' ? '' : ' › ' . str_replace('/', ' › ', $group));
        $html  = '<div class="wrap">' . ListPage::heading($title, (string) TAXMOD_STOCK_VERSION);

        try {
            $html .= $found === null ? $this->tiles($this->dueTiles()) : $this->tiles($this->overviewTiles($found, $group));
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

            $tiles[] = [(string) $item['label'], $counts[(string) $item['tab']] ?? null, LogisticsShell::url($item)];
        }

        return $tiles;
    }

    /** @return array<string, int> orders per inbound stage, by the stage's tab */
    private function stageCounts(): array
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

    /** @param list<array{0: string, 1: int|null, 2: string}> $tiles label, count (null: none), address */
    private function tiles(array $tiles): string
    {
        $html = '<div class="taxmod-tiles">';

        foreach ($tiles as [$label, $count, $url]) {
            $html .= '<a class="taxmod-tile' . ($count === 0 ? ' is-empty' : '') . '" href="' . esc_url($url) . '">'
                . ($count === null ? '' : '<span class="taxmod-tile-count">' . esc_html(number_format_i18n($count)) . '</span>')
                . '<span class="taxmod-tile-label">' . esc_html($label) . '</span></a>';
        }

        return '<style>.taxmod-tiles{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px}'
            . '.taxmod-tile{display:flex;flex-direction:column;min-width:160px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;text-decoration:none;color:#1d2327}'
            . '.taxmod-tile:focus{box-shadow:0 0 0 2px #2271b1;outline:none}.taxmod-tile-count{font-size:28px;font-weight:600;line-height:1.2}'
            . '.taxmod-tile.is-empty{border-left-color:#c3c4c7;color:#646970}.taxmod-tile.is-empty .taxmod-tile-count{font-size:20px}</style>'
            . $html . '</div>';
    }
}
