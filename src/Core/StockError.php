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

    /** D-1030: a new place needs a name. */
    public static function placeWithoutName(): self
    {
        return new self('place_without_name', '');
    }

    /** D-1184: a new contact needs a name. */
    public static function contactWithoutName(): self
    {
        return new self('contact_without_name', '');
    }

    /** D-1184: the node is not in the contact branch. */
    public static function notAContactGroup(int $nodeId): self
    {
        return new self('not_a_contact_group', (string) $nodeId);
    }

    /** D-1184: only the contact just created by this user can be taken back. */
    public static function contactNotUndoable(int $recordId): self
    {
        return new self('contact_not_undoable', (string) $recordId);
    }

    /** D-1030: a place cannot sit inside itself or inside something it holds. */
    public static function placeIntoItself(int $place): self
    {
        return new self('place_into_itself', (string) $place);
    }

    /** D-1123: a type of place whose parts lead back to itself. */
    public static function placeTypeLoop(int $type): self
    {
        return new self('place_type_loop', (string) $type);
    }

    /** D-1058: closing a purchase without stock needs its reason. */
    public static function notStockedWithoutReason(int $purchaseId): self
    {
        return new self('not_stocked_without_reason', (string) $purchaseId);
    }

    public static function notClosedWithoutStock(int $purchaseId): self
    {
        return new self('not_closed_without_stock', (string) $purchaseId);
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

    /** D-1018: a source names a firm under «Organisation». */
    public static function notAFirm(int $recordId): self
    {
        return new self('not_a_firm', (string) $recordId);
    }

    /** D-1019: a pack holds a positive number of the base unit. */
    public static function notAPackContent(string $given): self
    {
        return new self('not_a_pack_content', $given);
    }

    /** D-1019: a base unit and its prefix come from «Base units» and «Prefixes». */
    public static function notAUnit(int $nodeId): self
    {
        return new self('not_a_unit', (string) $nodeId);
    }

    /** Concept R3: only a purchase «not yet determined» is split, and it needs at least one part. */
    public static function notALot(int $purchaseId): self
    {
        return new self('not_a_lot', (string) $purchaseId);
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

    /** D-1036: a counted lot booked since the count keeps its count until that booking is cancelled. */
    public static function lotBookedSinceCount(int $lot): self
    {
        return new self('lot_booked_since_count', (string) $lot);
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

    /** D-1027: a demand is an order «Entwurf» without a supplier. */
    public static function notADemand(int $orderId): self
    {
        return new self('not_a_demand', (string) $orderId);
    }

    public static function demandWithoutName(): self
    {
        return new self('demand_without_name', '');
    }

    public static function noMerchant(): self
    {
        return new self('no_merchant', '');
    }

    /** D-1027: the step needs an order in another status — a draft, an ordered one or a cancelled one. */
    public static function notADraft(int $orderId): self
    {
        return new self('not_a_draft', (string) $orderId);
    }

    /** D-1073: moving the material to that group would drop the values of these fields; asked before it happens. */
    public static function wouldDrop(string $fields): self
    {
        return new self('would_drop', $fields);
    }

    /** D-1039: an Umbuchung needs another place than the one the goods lie at. */
    public static function samePlace(int $lot): self
    {
        return new self('same_place', (string) $lot);
    }

    /** D-1039: an Umbuchung moves at most what lies at its place; the detail is «lot@place». */
    public static function notEnoughAt(int $lot, int $place): self
    {
        return new self('not_enough_at', $lot . '@' . $place);
    }

    /** D-1036: a Vorgang is cancelled once; a Storno is not cancelled again. */
    public static function alreadyCancelled(int $transaction): self
    {
        return new self('already_cancelled', (string) $transaction);
    }

    /** D-1036: only Vorgänge whose Storno the module can build are offered one. */
    public static function notCancellable(int $transaction): self
    {
        return new self('not_cancellable', (string) $transaction);
    }

    /** D-1082: goods reserved already may not go into a reserved area. */
    public static function alreadyReserved(int $lot): self
    {
        return new self('already_reserved', (string) $lot);
    }

    /** D-1082: nothing is consumed inside a reserved area before it is moved out. */
    public static function reservedForProduction(int $place): self
    {
        return new self('reserved_for_production', (string) $place);
    }

    /** D-1083: the record is no reservation. */
    public static function notAReservation(int $transaction): self
    {
        return new self('not_a_reservation', (string) $transaction);
    }

    /** D-1088: reserved goods are consumed only for their own Bezug. */
    public static function reservedForOther(int $lot): self
    {
        return new self('reserved_for_other', (string) $lot);
    }

    /** D-1083: a lot gives a reservation only what lies free of it. */
    /** D-1168: a resend's loss larger than the rest of its lot. */
    public static function lossTooLarge(int $lot): self
    {
        return new self('loss_too_large', (string) $lot);
    }

    public static function notEnoughFree(int $lot): self
    {
        return new self('not_enough_free', (string) $lot);
    }

    /** D-1083: a reservation needs its subject — a project or a sale (D-1088: build runs and repairs are no Bezug). */
    public static function noSubject(): self
    {
        return new self('no_subject', '');
    }

    /** D-1146: a build takes only what lies in its box; the detail is the thing. */
    public static function notEnoughForBuild(int $thing): self
    {
        return new self('not_enough_for_build', (string) $thing);
    }

    /** D-1146: a pick takes only what lies free at the place it names; the detail is «thing@place». */
    public static function notEnoughToPick(int $thing, int $place): self
    {
        return new self('not_enough_to_pick', $thing . '@' . $place);
    }

    /** D-1146: a build gives back at most what it took; the detail is the thing. */
    public static function notTakenForBuild(int $thing): self
    {
        return new self('not_taken_for_build', (string) $thing);
    }

    /** D-1143: a finished thing cancelled beyond what this order brought into stock and is still there. */
    public static function notProducedForOrder(int $thing): self
    {
        return new self('not_produced_for_order', (string) $thing);
    }

    /** D-1169: the place says «Verhindern», and what should go in does not fit; the detail is the place. */
    public static function doesNotFit(int $place): self
    {
        return new self('does_not_fit', (string) $place);
    }

    /** A date as the form sends it: Y-m-d. */
    public static function notADate(string $given): self
    {
        return new self('not_a_date', $given);
    }

    public static function modelRefused(string $message): self
    {
        return new self('model_refused', $message);
    }

    /**
     * The modeller's refusal, read for what the module can word: D-1099, his word «f3 verweigern» — a head whose positions
     * another record still points at is not deleted, and the modeller names who. Detail: «head|part:record:field;…».
     * Anything else stays {@see modelRefused()} with the modeller's own words.
     */
    public static function fromModel(string $message): self
    {
        if (preg_match('/Record (\d+) cannot be deleted: its parts are still referenced — (.+?)\. Remove those references first/u', $message, $m) !== 1) {
            return self::modelRefused($message);
        }

        preg_match_all('/part #(\d+) ← record #(\d+) \(«(.*?)»\)/u', $m[2], $who, PREG_SET_ORDER);

        return new self('parts_referenced', $m[1] . '|' . implode(';', array_map(static fn (array $w): string => $w[1] . ':' . $w[2] . ':' . $w[3], $who)));
    }

    /** D-1239: a label needs a name. */
    public static function labelWithoutName(): self
    {
        return new self('label_without_name', '');
    }

    /** D-1239: width, height, roll and copies are whole numbers above 0. */
    public static function notALabelSize(string $typed): self
    {
        return new self('not_a_label_size', $typed);
    }

    /** D-1239: a label a place type prints with is not removed; the detail names the place types. */
    public static function labelInUse(string $types): self
    {
        return new self('label_in_use', $types);
    }

    /** D-1239: no such roll. */
    public static function notARoll(string $id): self
    {
        return new self('not_a_roll', $id);
    }

    /** D-1239: no such label, or the layout cannot be drawn on it. */
    public static function notALabel(string $detail): self
    {
        return new self('not_a_label', $detail);
    }
}
