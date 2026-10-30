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
            'model_refused'             => sprintf(__('Der Taxonomy Modeller hat die Buchung abgelehnt, nichts wurde geschrieben: %s', 'taxmod-stock'), $error->detail),
            default                     => $error->getMessage(),
        };
    }

    public static function lockReason(string $reason): string
    {
        return match ($reason) {
            'received'                  => __('schon eingegangen', 'taxmod-stock'),
            'purchase_undetermined'     => __('noch nicht näher bestimmt', 'taxmod-stock'),
            'purchase_without_thing'    => __('kein Material', 'taxmod-stock'),
            default                     => $reason,
        };
    }
}
