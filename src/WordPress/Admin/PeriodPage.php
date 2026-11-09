<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\Core\Tile\Period;
use Taxmod\Core\Tile\PeriodChart;
use Taxmod\Core\Tile\PeriodSpan;
use Taxmod\Core\Tile\Tile;
use Taxmod\Core\Tile\TileTone;
use Taxmod\WordPress\Admin\Tiles;
use TaxmodStock\Core\PeriodActivity;
use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\StockService;

/**
 * «Zeitraum» in the cockpit (D-1167): one day, month or year of the stock, switched and stepped by plain links — what
 * happened, against the period before, as tiles, a chart of in and out, the Vorgänge and the things moved most, and the key
 * figures of the model for the same period.
 *
 * ```mermaid
 * flowchart LR
 *   R["?span=day|month|year&day=YYYY-MM-DD"] --> P["Period"]
 *   P --> A["PeriodActivity: Vorgänge of the period and the one before"]
 *   A --> T["tiles per kind"] & C["chart in/out"] & L["list + most moved"]
 *   P --> K["Tiles::metrics(period)"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/kacheln/konzept.md §6
 */
final class PeriodPage
{
    /** How many Vorgänge the list shows; a day rarely has more, a year always. */
    private const LIST_LIMIT = 50;

    /** How many things the list «most moved» names. */
    private const TOP = 10;

    public function __construct(private readonly StockService $stock = new StockService())
    {
    }

    /** Where the period stands on the cockpit (D-1241). */
    public const ANCHOR = 'taxmod-period';

    /** The cockpit at its period; the switch adds span and day to it, the browser jumps to the period. */
    public static function url(): string
    {
        return add_query_arg(['page' => CockpitPage::PAGE], admin_url('admin.php')) . '#' . self::ANCHOR;
    }

    public function render(): string
    {
        $period = Tiles::periodFromRequest(PeriodSpan::Day);
        $html   = Tiles::periodBar($period, self::url());

        try {
            $html .= $this->activity($period);
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . Tiles::metricsGrid(null, $period);
    }

    private function activity(Period $period): string
    {
        $s        = $this->stock->structure();
        $before   = $period->previous();
        $activity = new PeriodActivity(
            $s,
            $this->stock->table('transaction'),
            $this->stock->table('line'),
            $this->stock->table('lot'),
            min($before->start(), $period->chartStart()),
            $period->end()
        );
        $now      = $activity->countsByKind($period->start(), $period->end());
        $then     = $activity->countsByKind($before->start(), $before->end());
        $events   = $activity->events($period->start(), $period->end());
        $tiles    = [];

        foreach ($this->kinds() as $kind => $label) {
            $tiles[] = self::compared($label, (float) ($now[$kind] ?? 0), (float) ($then[$kind] ?? 0));
        }

        $sum = static fn (array $list, string $key): float => array_sum(array_column($list, $key));
        $old = $activity->events($before->start(), $before->end());

        $tiles[] = self::compared(__('Zugang (Menge)', 'taxmod-stock'), $sum($events, 'in'), $sum($old, 'in'));
        $tiles[] = self::compared(__('Abgang (Menge)', 'taxmod-stock'), $sum($events, 'out'), $sum($old, 'out'));

        if ($s->hasField('order_date')) {
            [$orders, $earlier] = $this->orders($period);
            $tiles[]            = self::compared(__('Bestellungen', 'taxmod-stock'), (float) $orders, (float) $earlier);
        }

        $steps = $period->steps();
        $flow  = $activity->flow($steps);
        $chart = PeriodChart::of(
            array_map(static fn (string $step): string => Tiles::stepName($period, $step), $steps),
            [__('Zugang', 'taxmod-stock') => $flow['in'], __('Abgang', 'taxmod-stock') => $flow['out']],
            $period->span === PeriodSpan::Day ? count($steps) - 1 : null
        );

        return Tiles::grid($tiles, sprintf(__('Lager · %s', 'taxmod-stock'), Tiles::periodName($period)))
            . '<h2 class="taxmod-tiles-heading">' . esc_html__('Verlauf', 'taxmod-stock') . '</h2>' . $chart
            . '<div class="taxmod-panels"><section class="taxmod-panel"><h3>' . esc_html__('Was ist passiert', 'taxmod-stock') . '</h3>' . $this->eventList($events) . '</section>'
            . '<section class="taxmod-panel"><h3>' . esc_html__('Meistbewegtes Material', 'taxmod-stock') . '</h3>' . $this->mostMoved($activity, $period) . '</section></div>';
    }

    /** A count against the one before: grey when both are nothing, the trend as ▲ or ▼. */
    private static function compared(string $label, float $now, float $then): Tile
    {
        $format = static fn (float $v): string => number_format_i18n($v, fmod($v, 1.0) === 0.0 ? 0 : 2);

        return new Tile(
            $label,
            '',
            $format($now),
            [sprintf(__('Davor: %s', 'taxmod-stock'), $format($then))],
            $now == 0 && $then == 0 ? TileTone::Empty : TileTone::Normal,
            trend: [1 => '▲', 0 => '', -1 => '▼'][$now <=> $then],
        );
    }

    /** @return array<int, string> kind value → its word, only the kinds this model has */
    private function kinds(): array
    {
        $s     = $this->stock->structure();
        $words = [
            'kind_receipt'     => __('Wareneingang', 'taxmod-stock'),
            'kind_consumption' => __('Verbrauch', 'taxmod-stock'),
            'kind_count'       => __('Inventur', 'taxmod-stock'),
            'kind_transfer'    => __('Umbuchung', 'taxmod-stock'),
            'kind_relocation'  => __('Box-Umlagerung', 'taxmod-stock'),
            'kind_reservation' => __('Reservierung', 'taxmod-stock'),
            'kind_sale'        => __('Verkauf', 'taxmod-stock'),
        ];
        $out   = [];

        foreach ($words as $key => $word) {
            if ($s->hasValue($key)) {
                $out[$s->value($key)] = $word;
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: int} orders dated in the period and in the one before */
    private function orders(Period $period): array
    {
        $s      = $this->stock->structure();
        $orders = $this->stock->table('order');
        $before = $period->previous();
        $count  = [0, 0];

        foreach ($orders->ids() as $order) {
            $date      = (string) $orders->first($order, $s->field('order_date'));
            $count[0] += $period->contains($date) ? 1 : 0;
            $count[1] += $before->contains($date) ? 1 : 0;
        }

        return $count;
    }

    /** @param list<array{deal: int, date: string, kind: int, in: float, out: float, lines: int, things: array<int, float>}> $events */
    private function eventList(array $events): string
    {
        if ($events === []) {
            return '<p class="description">' . esc_html__('Nichts gebucht.', 'taxmod-stock') . '</p>';
        }

        $kinds  = $this->kinds();
        $things = $this->stock->things();
        $rows   = '';

        foreach (array_slice($events, 0, self::LIST_LIMIT) as $e) {
            $names = array_map(
                static fn (int $thing): string => '<a href="' . esc_url(StockPage::thingUrl($thing, 'bestand')) . '">' . esc_html($things->has($thing) ? $things->name($thing) : '#' . $thing) . '</a>',
                array_keys($e['things'])
            );
            $rows .= '<tr><td>' . esc_html(wp_date('j.n.Y', (int) strtotime($e['date'] . ' 00:00:00 UTC'), new \DateTimeZone('UTC'))) . '</td>'
                . '<td>' . esc_html($kinds[$e['kind']] ?? '–') . '</td>'
                . '<td>' . implode(', ', $names) . '</td>'
                . '<td style="text-align:right">' . esc_html($e['in'] > 0 ? '+' . number_format_i18n($e['in'], fmod($e['in'], 1.0) === 0.0 ? 0 : 2) : '') . '</td>'
                . '<td style="text-align:right">' . esc_html($e['out'] > 0 ? '−' . number_format_i18n($e['out'], fmod($e['out'], 1.0) === 0.0 ? 0 : 2) : '') . '</td></tr>';
        }

        $more = count($events) > self::LIST_LIMIT
            /* translators: 1: shown, 2: all */
            ? '<p class="description">' . esc_html(sprintf(__('Die letzten %1$d von %2$d Vorgängen.', 'taxmod-stock'), self::LIST_LIMIT, count($events))) . '</p>'
            : '';

        return '<table class="widefat striped"><thead><tr><th style="width:6em">' . esc_html__('Datum', 'taxmod-stock') . '</th><th style="width:9em">' . esc_html__('Art', 'taxmod-stock')
            . '</th><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th style="width:5em;text-align:right">' . esc_html__('Zugang', 'taxmod-stock')
            . '</th><th style="width:5em;text-align:right">' . esc_html__('Abgang', 'taxmod-stock') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>' . $more;
    }

    private function mostMoved(PeriodActivity $activity, Period $period): string
    {
        $moved = $activity->mostMoved(self::TOP, $period->start(), $period->end());

        if ($moved === []) {
            return '<p class="description">' . esc_html__('Nichts bewegt.', 'taxmod-stock') . '</p>';
        }

        $things = $this->stock->things();
        $rows   = '';

        foreach ($moved as $thing => $quantity) {
            $rows .= '<tr><td><a href="' . esc_url(StockPage::thingUrl($thing, 'bestand')) . '">' . esc_html($things->has($thing) ? $things->name($thing) : '#' . $thing) . '</a></td>'
                . '<td style="text-align:right">' . esc_html(number_format_i18n($quantity, fmod($quantity, 1.0) === 0.0 ? 0 : 2)) . '</td></tr>';
        }

        return '<table class="widefat striped"><thead><tr><th>' . esc_html__('Material', 'taxmod-stock') . '</th><th style="width:7em;text-align:right">'
            . esc_html__('Bewegt', 'taxmod-stock') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }
}
