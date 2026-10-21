<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Why a booking cannot be made; the boundary turns it into a WP_Error (CD-10).
 *
 * The message is a code plus a detail, not user text: the boundary words it through the text domain (AR-2).
 */
final class StockError extends \RuntimeException
{
    private function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct($reason . ': ' . $detail);
    }

    public static function notConfigured(string $key): self
    {
        return new self('not_configured', $key);
    }

    public static function purchaseUndetermined(int $purchaseId): self
    {
        return new self('purchase_undetermined', (string) $purchaseId);
    }

    public static function purchaseWithoutMaterial(int $purchaseId): self
    {
        return new self('purchase_without_material', (string) $purchaseId);
    }

    public static function notInOrder(int $purchaseId): self
    {
        return new self('not_in_order', (string) $purchaseId);
    }

    public static function notAQuantity(string $given): self
    {
        return new self('not_a_quantity', $given);
    }

    public static function nothingToBook(): self
    {
        return new self('nothing_to_book', '');
    }

    public static function noLot(int $materialId): self
    {
        return new self('no_lot', (string) $materialId);
    }

    public static function modelRefused(string $message): self
    {
        return new self('model_refused', $message);
    }
}
