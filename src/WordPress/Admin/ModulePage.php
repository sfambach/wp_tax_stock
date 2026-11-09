<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\WordPress\Plugin;

/**
 * The pages of the connection modules (eBay, DHL, JLCPCB …) inside the stock interface (D-1085): «Nichts unter Logistik
 * springt mehr aus der Lager-Navigation heraus». A module page opens at `admin.php?page=taxmod-lager-modul&module=<slug>`,
 * framed by the logistics navigation.
 *
 * ```mermaid
 * flowchart LR
 *   M["submenu of the Taxmod menu"] -->|admin_menu, last| L["list of module pages"]
 *   F["filter taxmod_logistics_pages"] --> L
 *   L --> N["logistics navigation"]
 *   L -->|admin_head| H["entries leave the WP menu"]
 *   R["request page=taxmod-lager-modul&module=slug"] --> C{"render callback handed over?"}
 *   C -->|yes| S["its string"]
 *   C -->|no| O["the module's own page callback, captured"]
 * ```
 *
 * ⚠️ *INFERRED (kept from D-1059): a module is whatever hangs under the modeller's menu and is neither the modeller's own page
 * nor ours. A module may announce itself instead through the filter {@see FILTER} and hand over a render callback that
 * returns a string; until it does, its own page callback runs and its output is captured — the one place this plugin buffers
 * output (CD-8 exception: a third-party API that echoes).*
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1085
 */
final class ModulePage
{
    public const PAGE = Pages::MODULE;

    /**
     * Filter: `list<array{slug: string, label: string, area?: 'settings'|'outbound'|'print', path?: list<string>, capability?: string, render?: callable(): string}>`.
     * `path` puts the page under groups in the navigation, any depth (D-1121); area «print» stands under Einstellungen › Druck.
     * An entry with the slug of a menu entry replaces what was read from the menu.
     */
    public const FILTER = 'taxmod_logistics_pages';

    /** The navigation areas a module page may stand in. */
    private const AREAS = ['settings', 'outbound', 'print', 'production'];

    /** Pages of the Taxmod menu that are not modules: the modeller's (D-1085 level 1) and ours. */
    private const NOT_MODULES = [Plugin::PARENT, 'taxmod-inbox', 'taxmod-cleanup', 'taxmod-backup', 'taxmod-settings', Pages::COCKPIT, Pages::INBOUND, Pages::STOCK, Pages::PURCHASE, Pages::SETTINGS, Pages::CONTACTS, Pages::MODULE];

    /** Kept from the D-1053 navigation: the eBay shipping page stands under «Warenausgang», the other modules under «Einstellungen». */
    private const OUTBOUND = ['taxebay-shipping'];

    /** @var array<string, array{slug: string, label: string, area: string, capability: string, render: ?callable, menu: bool, path: list<string>}>|null */
    private static ?array $captured = null;

    /** admin_menu, after every module has hung its page under the Taxmod menu: the list is kept before admin_head empties the menu. */
    public static function capture(): void
    {
        self::$captured = self::read();
    }

    /** @return array<string, array{slug: string, label: string, area: string, capability: string, render: ?callable, menu: bool, path: list<string>}> */
    public static function all(): array
    {
        return self::$captured ?? self::read();
    }

    /** @return list<string> the module entries of the Taxmod menu, which leave the WP menu (D-1085: Modeller, Logistik, Wartung, Configuration stay) */
    public static function menuEntries(): array
    {
        return array_values(array_map(static fn (array $module): string => $module['slug'], array_filter(self::all(), static fn (array $module): bool => $module['menu'])));
    }

    public static function url(string $slug): string
    {
        return add_query_arg(['page' => self::PAGE, 'module' => $slug], admin_url('admin.php'));
    }

    /** The module the request asks for; '' when none. */
    public static function requested(): string
    {
        return sanitize_text_field(wp_unslash((string) ($_GET['module'] ?? '')));
    }

    /** load-<our page>: a module that works on its own load hook (saving, redirecting) still gets it. */
    public static function load(): void
    {
        $module = self::all()[self::requested()] ?? null;

        if ($module === null || $module['render'] !== null || ! current_user_can($module['capability'])) {
            return;
        }

        do_action('load-' . self::hook($module['slug']));
    }

    public function render(string $slug): string
    {
        $module = self::all()[$slug] ?? null;

        if ($module === null) {
            return '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Diese Seite gibt es nicht.', 'taxmod-stock') . '</p></div></div>';
        }

        if (! current_user_can($module['capability'])) {
            return '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Keine Berechtigung.', 'taxmod-stock') . '</p></div></div>';
        }

        if ($module['render'] !== null) {
            return (string) ($module['render'])();
        }

        $hook = self::hook($slug);

        if (! has_action($hook)) {
            return '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Das Modul zeichnet diese Seite nicht.', 'taxmod-stock') . '</p></div></div>';
        }

        // CD-8 exception: the module's page callback echoes; its output is taken as it is, the module escapes its own.
        ob_start();
        do_action($hook);

        return (string) ob_get_clean();
    }

    /** The action WordPress hung the module's page callback on. */
    private static function hook(string $slug): string
    {
        return (string) get_plugin_page_hookname($slug, Plugin::PARENT);
    }

    /** @return array<string, array{slug: string, label: string, area: string, capability: string, render: ?callable, menu: bool, path: list<string>}> */
    private static function read(): array
    {
        global $submenu;

        $modules = [];

        foreach ((array) ($submenu[Plugin::PARENT] ?? []) as $entry) {
            $slug = (string) ($entry[2] ?? '');

            if ($slug === '' || in_array($slug, self::NOT_MODULES, true) || str_contains($slug, 'cleanup') || str_contains($slug, 'backup') || str_contains($slug, 'inbox') || str_contains($slug, '.php')) {
                continue;
            }

            $modules[$slug] = [
                'slug'       => $slug,
                'label'      => wp_strip_all_tags((string) ($entry[0] ?? $slug)),
                'area'       => in_array($slug, self::OUTBOUND, true) ? 'outbound' : 'settings',
                'capability' => (string) ($entry[1] ?? 'manage_options'),
                'render'     => null,
                'menu'       => true,
                'path'       => [],
            ];
        }

        foreach ((array) apply_filters(self::FILTER, []) as $page) {
            $slug = is_array($page) ? sanitize_text_field((string) ($page['slug'] ?? '')) : '';

            if ($slug === '' || in_array($slug, self::NOT_MODULES, true)) {
                continue;
            }

            $known          = $modules[$slug] ?? null;
            $modules[$slug] = [
                'slug'       => $slug,
                'label'      => wp_strip_all_tags((string) ($page['label'] ?? $known['label'] ?? $slug)),
                'area'       => in_array($page['area'] ?? null, self::AREAS, true) ? (string) $page['area'] : ($known['area'] ?? 'settings'),
                'capability' => is_string($page['capability'] ?? null) && $page['capability'] !== '' ? $page['capability'] : ($known['capability'] ?? 'manage_options'),
                'render'     => is_callable($page['render'] ?? null) ? $page['render'] : null,
                'menu'       => $known !== null,
                // The groups above the page in the navigation, any depth (D-1121).
                'path'       => array_values(array_filter(array_map(static fn ($p): string => sanitize_text_field((string) $p), array_filter((array) ($page['path'] ?? []), 'is_scalar')), static fn (string $p): bool => $p !== '')),
            ];
        }

        return $modules;
    }
}
