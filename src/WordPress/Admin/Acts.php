<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;

/**
 * D-1254: the Lager's handlers end through the modeller's `ListPage::act()`, `json()`, `fail()`, `nonce()`, `ajaxNonce()`.
 * Only a pass-through: on a modeller without them, the old WordPress behaviour.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md
 */
final class Acts
{
    public static function run(callable $handler): void
    {
        if (! method_exists(ListPage::class, 'act')) {
            $handler();

            return;
        }

        ListPage::act(static function () use ($handler): string {
            $handler();

            return '';
        });
    }

    public static function json(callable $handler): void
    {
        method_exists(ListPage::class, 'json') ? ListPage::json($handler) : $handler();
    }

    public static function deny(string $message, int $status = 403): never
    {
        if (method_exists(ListPage::class, 'fail')) {
            ListPage::fail($message);
        }

        wp_die(esc_html($message), '', ['response' => $status, 'back_link' => true]);
    }

    public static function nonce(string $action): void
    {
        method_exists(ListPage::class, 'nonce') ? ListPage::nonce($action) : check_admin_referer($action);
    }

    public static function ajaxNonce(string $action): void
    {
        method_exists(ListPage::class, 'ajaxNonce') ? ListPage::ajaxNonce($action) : check_ajax_referer($action);
    }
}
