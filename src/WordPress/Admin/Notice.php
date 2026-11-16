<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

/**
 * The one notice of the Lager's pages: an act remembers what it did for this user, the page it returns to shows it once.
 * Was five copies on the pages (code review 2026-11-16, plan-dubletten row 7).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/gestaltung-und-architektur.md R3
 */
final class Notice
{
    private const TYPES = ['success', 'info', 'warning', 'error'];

    public static function remember(string $type, string $text, string $key = StockPage::NOTICE): void
    {
        set_transient($key . get_current_user_id(), ['type' => $type, 'text' => $text], 60);
    }

    /** The remembered notice, once; without one, what the modeller said after saving here (D-1025) when `$said`. */
    public static function take(string $key = StockPage::NOTICE, bool $said = false): string
    {
        $notice = get_transient($key . get_current_user_id());

        if (! is_array($notice)) {
            $word = $said && isset($_GET['taxmod_message']) ? sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['taxmod_message']))) : '';

            return $word === '' ? '' : self::box($word === 'ok' ? 'success' : 'info', $word === 'ok' ? __('Gespeichert.', 'taxmod-stock') : $word);
        }

        delete_transient($key . get_current_user_id());

        return self::box((string) $notice['type'], (string) $notice['text']);
    }

    private static function box(string $type, string $text): string
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'error';

        return '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }
}
