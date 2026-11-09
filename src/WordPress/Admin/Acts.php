<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;

/**
 * D-1254: no act of the Lager ends on an empty page — a failure becomes a message on the page it came from, a refusal too;
 * ajax answers say it as JSON. Uses the modeller's `ListPage::act()`/`fail()`, with the old behaviour on an older modeller.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md
 */
final class Acts
{
    /** An admin-post handler: it redirects and exits itself; anything thrown on the way becomes the message. */
    public static function run(callable $handler): void
    {
        if (! method_exists(ListPage::class, 'act')) {
            $handler();

            return;
        }

        ListPage::act(static function () use ($handler): string {
            $handler();

            return '';
        }, self::back());
    }

    /**
     * The page to go back to. ⚠️ *A form drawn by an ajax call (the receipt positions) names that call as its referer; then the
     * browser's own referer — the page it stands on — is the way back.*
     */
    private static function back(): string
    {
        $named = (string) wp_get_referer();

        if ($named !== '' && ! str_contains($named, 'admin-post.php') && ! str_contains($named, 'admin-ajax.php')) {
            return $named;
        }

        $page = esc_url_raw(wp_unslash((string) ($_SERVER['HTTP_REFERER'] ?? '')));

        return $page !== '' && wp_validate_redirect($page, '') !== '' ? $page : admin_url();
    }

    /** An ajax handler: anything thrown becomes an error answer the page shows in its message field. */
    public static function json(callable $handler): void
    {
        try {
            $handler();
        } catch (\Throwable $e) {
            error_log('Taxmod Lager ajax failed: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(['message' => $e->getMessage(), 'html' => '<div class="notice notice-error inline"><p>' . esc_html($e->getMessage()) . '</p></div>'], 500);
        }
    }

    /** A refusal (capability, nonce, check): back with the message instead of an empty page. */
    public static function deny(string $message, int $status = 403): never
    {
        if (method_exists(ListPage::class, 'fail')) {
            ListPage::fail($message, self::back());
        }

        wp_die(esc_html($message), '', ['response' => $status, 'back_link' => true]);
    }

    /** The nonce of an ajax call; a stale page gets an answer it can show, not «-1». */
    public static function ajaxNonce(string $action): void
    {
        $nonce = sanitize_text_field(wp_unslash((string) ($_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? '')));

        if (wp_verify_nonce($nonce, $action) === false) {
            $text = self::stale();
            wp_send_json_error(['message' => $text, 'html' => '<div class="notice notice-error inline"><p>' . esc_html($text) . '</p></div>'], 403);
        }
    }

    /** The nonce of a form; a stale page is said, not died on. */
    public static function nonce(string $action): void
    {
        if (wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_REQUEST['_wpnonce'] ?? ''))), $action) === false) {
            self::deny(self::stale());
        }
    }

    private static function stale(): string
    {
        return __('Die Seite war zu lange offen oder das Formular ist ungültig. Bitte neu laden und noch einmal versuchen.', 'taxmod-stock');
    }
}
