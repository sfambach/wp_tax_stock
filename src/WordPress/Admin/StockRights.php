<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Rights;

/**
 * Which right each part of the stock asks for (D-1585 step 3, D-1586): the stock's pages, its tabs and the kinds of its
 * one booking form. The menu entry, the navigation item and the handler read the same right from here.
 *
 * ```mermaid
 * flowchart LR
 *   K["booking kind / tab / page"] --> R["StockRights::of…()"]
 *   R --> N["navigation item shown?"]
 *   R --> H["handler allows?"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/rechte/stufe-3.md
 */
final class StockRights
{
    /** Goods arrival belongs to purchasing and to the stock: whoever holds either may open «Wareneingang» (assumption C1). */
    public const INBOUND = 'taxmod_stock_inbound';

    /** The tabs of «Lager» that shape the store itself, not its stock (assumption C2). */
    private const STRUCTURE_TABS = ['lagerstruktur', 'platzarten'];

    /** Kinds of the booking form by right; every kind not named books stock (assumption C3). */
    private const KINDS = [
        Rights::CONFIGURE => ['ort', 'platzart', 'ortloeschen', 'unterplaetze', 'kurzzeichen', 'reihenfolge', 'artwechsel', 'artkopieren', 'ortkopieren', 'orteverschieben', 'gruppeanlegen'],
        Rights::WRITE_DATA => ['lager', 'verschieben', 'sammelverschieben', 'sammelvarianten', 'uebernehmen', 'kopieren', 'bilduebernehmen', 'quellen'],
        self::INBOUND => ['positioneinlagern', 'wareneingang', 'aufteilen', 'nichteinlagern', 'einlagern', 'sammelnichteinlagern', 'wareneingangzurueck', 'angekommen', 'nichtangekommen', 'umhaengen'],
    ];

    /** The right of each page of the stock interface; its menu entry and its navigation item both read it from here. */
    private const PAGES = [
        Pages::COCKPIT         => Rights::VIEW,
        Pages::PURCHASE        => Rights::PURCHASE,
        Pages::INBOUND         => self::INBOUND,
        Pages::STOCK           => Rights::STOCK_BOOK,
        Pages::CONTACTS        => Rights::WRITE_DATA,
        Pages::CHOICES         => Rights::CONFIGURE,
        Pages::MATERIAL_GROUPS => Rights::MODEL,
        Pages::RULES           => Rights::CONFIGURE,
        Pages::FIELD_CATALOGUE => Rights::MODEL,
        Pages::SETTINGS        => Rights::VIEW,
        Pages::MODULE          => Rights::VIEW,
        Pages::TEST_RULES      => Rights::CONFIGURE,
    ];

    public static function boot(): void
    {
        add_filter(Rights::FILTER, static fn (array $rights): array => $rights + [self::INBOUND => __('Wareneingang', 'taxmod-stock')]);
        add_filter('user_has_cap', [self::class, 'derive']);
    }

    /**
     * `user_has_cap`: «Wareneingang» follows from purchasing or booking stock — no role needs it of its own.
     *
     * @param array<string, bool> $allcaps
     * @return array<string, bool>
     */
    public static function derive(array $allcaps): array
    {
        if (! empty($allcaps[Rights::PURCHASE]) || ! empty($allcaps[Rights::STOCK_BOOK])) {
            $allcaps[self::INBOUND] = true;
        }

        return $allcaps;
    }

    public static function ofKind(string $kind): string
    {
        foreach (self::KINDS as $right => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return $right;
            }
        }

        return Rights::STOCK_BOOK;
    }

    /** A page of another module hung into the navigation asks for what its menu entry asks; one nobody listed, for the model's right. */
    public static function ofPage(string $page): string
    {
        return self::PAGES[$page] ?? \Taxmod\WordPress\Admin\FullScreen::pages()[$page] ?? Rights::MODEL;
    }

    public static function ofTab(string $tab): string
    {
        return in_array($tab, self::STRUCTURE_TABS, true) ? Rights::CONFIGURE : Rights::STOCK_BOOK;
    }
}
