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

    public function render(): string
    {
        $html = '<div class="wrap">' . ListPage::heading(__('Logistik', 'taxmod-stock'), (string) TAXMOD_STOCK_VERSION);

        try {
            $html .= $this->tiles();
        } catch (StockError $e) {
            $html .= '<div class="notice notice-error"><p>' . esc_html(Messages::of($e)) . '</p></div>';
        }

        return $html . '</div>';
    }

    private function tiles(): string
    {
        $s       = $this->inbound->stock()->structure();
        $archive = $this->inbound->stock()->inboundArchive(current_time('Y-m-d'));
        $counts  = array_count_values(array_map(static fn (InboundOrder $o): string => $o->stage($s, $archive)->value, $this->inbound->orders()));
        $tiles   = [
            [__('Angekommen, einbuchen', 'taxmod-stock'), $counts[InboundStage::Arrived->value] ?? 0, InboundPage::url(['tab' => InboundStage::Arrived->value])],
            [__('Unterwegs', 'taxmod-stock'), $counts[InboundStage::Shipped->value] ?? 0, InboundPage::url(['tab' => InboundStage::Shipped->value])],
            [__('Bestellt, nicht versendet', 'taxmod-stock'), $counts[InboundStage::Ordered->value] ?? 0, InboundPage::url(['tab' => InboundStage::Ordered->value])],
        ];

        if ($this->inbound->tests()->isConfigured()) {
            $tiles[] = [__('Zu testen', 'taxmod-stock'), count($this->inbound->lotsToTest()), InboundPage::url(['tab' => 'test'])];
        }

        $html = '<div class="taxmod-tiles">';

        foreach ($tiles as [$label, $count, $url]) {
            $html .= '<a class="taxmod-tile' . ($count === 0 ? ' is-empty' : '') . '" href="' . esc_url($url) . '"><span class="taxmod-tile-count">' . esc_html(number_format_i18n($count))
                . '</span><span class="taxmod-tile-label">' . esc_html($label) . '</span></a>';
        }

        return '<style>.taxmod-tiles{display:flex;flex-wrap:wrap;gap:12px;margin-top:16px}'
            . '.taxmod-tile{display:flex;flex-direction:column;min-width:160px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;text-decoration:none;color:#1d2327}'
            . '.taxmod-tile:focus{box-shadow:0 0 0 2px #2271b1;outline:none}.taxmod-tile-count{font-size:28px;font-weight:600;line-height:1.2}'
            . '.taxmod-tile.is-empty{border-left-color:#c3c4c7;color:#646970}.taxmod-tile.is-empty .taxmod-tile-count{font-size:20px}</style>'
            . $html . '</div>';
    }
}
