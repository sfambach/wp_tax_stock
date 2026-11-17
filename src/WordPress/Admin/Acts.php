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
        ListPage::act(static function () use ($handler): string {
            $handler();

            return '';
        });
    }

    public static function json(callable $handler): void
    {
        ListPage::json($handler);
    }

    public static function deny(string $message, int $status = 403): never
    {
        ListPage::fail($message);
    }

    public static function nonce(string $action): void
    {
        ListPage::nonce($action);
    }

    public static function ajaxNonce(string $action): void
    {
        ListPage::ajaxNonce($action);
    }
}
