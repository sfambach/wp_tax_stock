<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\StockError;

/**
 * The words for a refused booking — the core says why, the boundary says it in German (AR-2).
 */
final class Messages
{
    public static function of(StockError $error): string
    {
        // A structure pasted before 0.1.2 lacks the purchase's «Modell»; only the goods receipt needs it.
        if ($error->reason === 'not_configured' && $error->detail === 'fields.purchase_model') {
            return __('Der Wareneingang braucht fields.purchase_model (das Feld «Modell» der Käufe) in der Lager-Struktur. Bitte die Struktur aus structure-live.json neu einfügen (Taxonomy Modeller › Configuration › Lager).', 'taxmod-stock');
        }

        return match ($error->reason) {
            /* translators: %s: key of the missing id, e.g. nodes.lager */
            'not_configured'            => sprintf(__('Die Lager-Struktur ist nicht vollständig eingestellt: %s fehlt (Taxonomy Modeller › Configuration › Lager).', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            'purchase_undetermined'     => sprintf(__('Kauf #%s ist noch nicht näher bestimmt — erst bestimmen, dann einbuchen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            'purchase_without_thing'    => sprintf(__('Kauf #%s hat kein Material — eines wählen, suchen oder «neu anlegen».', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            /* translators: %s: record id of the purchase */
            'not_stocked_without_reason' => sprintf(__('Kauf #%s: Bitte einen Grund angeben, warum er nicht eingelagert wird.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            'not_closed_without_stock'  => sprintf(__('Kauf #%s ist nicht als «nicht eingelagert» abgeschlossen.', 'taxmod-stock'), $error->detail),
            'place_without_name'        => __('Ein neuer Lagerort braucht einen Namen.', 'taxmod-stock'),
            /* translators: %s: record id of the place */
            'place_type_loop'           => sprintf(__('Platzart #%s enthält sich über ihre Teile selbst.', 'taxmod-stock'), $error->detail),
            'place_into_itself'         => sprintf(__('Lagerort #%s kann nicht in sich selbst oder in etwas liegen, das in ihm liegt.', 'taxmod-stock'), $error->detail),
            'already_received'          => sprintf(__('Kauf #%s ist schon eingegangen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id */
            'not_a_thing'               => sprintf(__('#%s ist kein Material im Baum.', 'taxmod-stock'), $error->detail),
            /* translators: %s: node id */
            'not_a_group'               => sprintf(__('Knoten %s ist keine Gruppe unter Model.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id */
            'not_a_location'            => sprintf(__('#%s ist kein Lagerort.', 'taxmod-stock'), $error->detail),
            'not_a_firm'                => sprintf(__('#%s ist keine Firma unter «Organisation».', 'taxmod-stock'), $error->detail),
            'not_a_pack_content'        => sprintf(__('«%s» ist kein Packungsinhalt: eine Zahl größer als null.', 'taxmod-stock'), $error->detail),
            'not_a_unit'                => sprintf(__('#%s ist keine Basiseinheit und kein Vorsatz.', 'taxmod-stock'), $error->detail),
            'not_a_lot'                 => sprintf(__('Kauf #%s ist kein Konvolut: Aufteilen geht nur bei «noch nicht näher bestimmt», und es braucht mindestens eine Zeile.', 'taxmod-stock'), $error->detail),
            'thing_without_name'        => __('Ein neues Material braucht eine Bezeichnung.', 'taxmod-stock'),
            'thing_without_group'       => __('Ein neues Material braucht eine Materialgruppe im Baum.', 'taxmod-stock'),
            /* translators: %s: record id of the purchase */
            'not_in_order'              => sprintf(__('Kauf #%s gehört nicht zu dieser Bestellung.', 'taxmod-stock'), $error->detail),
            /* translators: %s: what was typed */
            'not_a_quantity'            => sprintf(__('«%s» ist keine gültige Menge.', 'taxmod-stock'), $error->detail),
            'nothing_to_book'           => __('Nichts zu buchen: keine Zeile ausgefüllt.', 'taxmod-stock'),
            /* translators: %s: record id of the thing */
            'no_lot'                    => sprintf(__('Material #%s hat keinen passenden Bestandsposten.', 'taxmod-stock'), $error->detail),
            'already_booked'            => __('Von dieser Bestellung ist schon etwas eingebucht — sie bleibt angekommen.', 'taxmod-stock'),
            'nothing_to_unbook'         => __('Von dieser Bestellung ist nichts eingebucht.', 'taxmod-stock'),
            /* translators: %s: record id of the lot */
            'lot_already_used'          => sprintf(__('Bestandsposten #%s wurde seit dem Wareneingang verbraucht oder gezählt — der Wareneingang bleibt.', 'taxmod-stock'), $error->detail),
            'no_tracking_number'        => __('Keine Sendungsnummer eingegeben.', 'taxmod-stock'),
            /* translators: %s: role of the order field, e.g. received_at */
            'no_import_targets'         => sprintf(__('Das Feld «%s» der Bestellungen fehlt in den Ziel-Ids des Eingangs (Taxonomy Modeller › Configuration › Import).', 'taxmod-stock'), $error->detail),
            'test_without_reason'       => __('«Teilweise OK» und «Wartung nötig» brauchen eine Begründung: was geht nicht, was ist zu tun.', 'taxmod-stock'),
            /* translators: %s: record id of the order */
            'not_a_demand'              => sprintf(__('#%s ist kein offener Bedarf (Bestellung «Entwurf» ohne Händler).', 'taxmod-stock'), $error->detail),
            'demand_without_name'       => __('Ein neuer Bedarf braucht einen Namen, z. B. das Projekt.', 'taxmod-stock'),
            'no_merchant'               => __('Bitte einen Händler wählen oder eintragen.', 'taxmod-stock'),
            /* translators: %s: record id of the order */
            'not_a_draft'               => sprintf(__('Bestellung #%s steht nicht im passenden Status für diesen Schritt.', 'taxmod-stock'), $error->detail),
            /* translators: %s: the modeller's message */
            'would_drop'                => sprintf(__('Nicht verschoben: in der neuen Gruppe fehlen Felder, deren Werte dabei verloren gingen: %s. Erst die Werte leeren oder die Felder an der Gruppe anlegen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the lot */
            'same_place'                => sprintf(__('Posten #%s liegt schon dort — bitte einen anderen Ort wählen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: lot and place, «lot@place» */
            'not_enough_at'             => sprintf(__('So viel liegt dort nicht (Posten@Ort %s) — höchstens die Menge, die an diesem Ort liegt, umbuchen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the Vorgang */
            'already_cancelled'         => sprintf(__('Vorgang #%s ist schon storniert oder selbst ein Storno.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the Vorgang */
            'not_cancellable'           => sprintf(__('Vorgang #%s lässt sich hier nicht stornieren.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the lot */
            'already_reserved'          => sprintf(__('Posten #%s ist schon reserviert und darf nicht in einen für die Produktion reservierten Bereich.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the place */
            'reserved_for_production'   => sprintf(__('Lagerort #%s ist reserviert: verbraucht wird dort nur für seinen Bezug (sein Projekt, sonst «für Produktion»), für alles andere erst aus dem Bereich umbuchen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the lot */
            'reserved_for_other'        => sprintf(__('Posten #%s ist reserviert: verbraucht werden darf nur, was frei ist, oder für genau den Bezug der Reservierung.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id */
            'not_a_reservation'         => sprintf(__('#%s ist keine Reservierung.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the lot */
            'not_enough_free'           => sprintf(__('Posten #%s hat nicht so viel frei — ein Teil liegt in einem reservierten Bereich oder ist schon reserviert.', 'taxmod-stock'), $error->detail),
            'no_subject'                => __('Kein gültiger Bezug: ein Projekt oder ein Verkauf.', 'taxmod-stock'),
            /* translators: %s: what was typed */
            'not_a_date'                => sprintf(__('«%s» ist kein gültiges Datum.', 'taxmod-stock'), $error->detail),
            'model_refused'             => sprintf(__('Der Taxonomy Modeller hat die Buchung abgelehnt, nichts wurde geschrieben: %s', 'taxmod-stock'), $error->detail),
            'parts_referenced'          => self::partsReferenced($error->detail),
            default                     => $error->getMessage(),
        };
    }

    /** D-1099: who still points at a position of the head, one «Position #… ← #… («Feld»)» each. */
    private static function partsReferenced(string $detail): string
    {
        [$head, $list] = explode('|', $detail, 2) + [1 => ''];
        $who           = [];

        foreach (array_filter(explode(';', $list)) as $entry) {
            [$part, $record, $field] = explode(':', $entry, 3) + [1 => '', 2 => ''];
            /* translators: 1: record id of the position, 2: record id that points at it, 3: name of its field */
            $who[] = sprintf(__('Position #%1$s ← #%2$s («%3$s»)', 'taxmod-stock'), $part, $record, $field);
        }

        /* translators: 1: record id of the head (order, Vorgang), 2: list of who points at its positions */
        return sprintf(__('#%1$s lässt sich nicht löschen: auf seine Positionen verweisen noch andere Sätze — %2$s. Erst diese Verweise entfernen; nichts wurde geschrieben.', 'taxmod-stock'), $head, implode('; ', $who));
    }

    public static function lockReason(string $reason): string
    {
        return match ($reason) {
            'received'                  => __('schon eingegangen', 'taxmod-stock'),
            'not_stocked'               => __('nicht eingelagert', 'taxmod-stock'),
            'purchase_undetermined'     => __('noch nicht näher bestimmt', 'taxmod-stock'),
            'purchase_without_thing'    => __('kein Material', 'taxmod-stock'),
            default                     => $reason,
        };
    }
}
