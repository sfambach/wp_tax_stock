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

    /** D-995: a purchase goes in as a thing — its «Modell», one chosen, or one created. */
    public static function purchaseWithoutThing(int $purchaseId): self
    {
        return new self('purchase_without_thing', (string) $purchaseId);
    }

    public static function alreadyReceived(int $purchaseId): self
    {
        return new self('already_received', (string) $purchaseId);
    }

    public static function notAThing(int $recordId): self
    {
        return new self('not_a_thing', (string) $recordId);
    }

    /** A new thing goes into a group of the tree under `Model`. */
    public static function notAGroup(int $nodeId): self
    {
        return new self('not_a_group', (string) $nodeId);
    }

    /** D-972: the chosen place is not a record of «Lagerorte». */
    public static function notALocation(int $recordId): self
    {
        return new self('not_a_location', (string) $recordId);
    }

    public static function thingWithoutName(): self
    {
        return new self('thing_without_name', '');
    }

    public static function thingWithoutGroup(): self
    {
        return new self('thing_without_group', '');
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

    public static function noLot(int $thingId): self
    {
        return new self('no_lot', (string) $thingId);
    }

    /** D-1001: «Zurück» from «Angekommen» while a purchase of the order is already in stock. */
    public static function alreadyBooked(): self
    {
        return new self('already_booked', '');
    }

    public static function nothingToUnbook(): self
    {
        return new self('nothing_to_unbook', '');
    }

    /** D-1009: a lot that was consumed or counted since the receipt keeps the receipt where it is. */
    public static function lotAlreadyUsed(int $lot): self
    {
        return new self('lot_already_used', (string) $lot);
    }

    public static function noTrackingNumber(): self
    {
        return new self('no_tracking_number', '');
    }

    /** D-1001: the cockpit reads the order fields from the modeller's import targets. */
    public static function noImportTargets(string $role): self
    {
        return new self('no_import_targets', $role);
    }

    /** D-1002: «Teilweise OK» and «Wartung nötig» say what is wrong. */
    public static function testWithoutReason(string $result): self
    {
        return new self('test_without_reason', $result);
    }

    public static function modelRefused(string $message): self
    {
        return new self('model_refused', $message);
    }
}
