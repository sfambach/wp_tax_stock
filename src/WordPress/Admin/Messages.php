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
            /* translators: %s: key of the missing id, e.g. nodes.material */
            'not_configured'            => sprintf(__('Die Lager-Struktur ist nicht vollständig eingestellt: %s fehlt (Taxonomy Modeller › Configuration › Lager).', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            'purchase_undetermined'     => sprintf(__('Kauf #%s ist noch nicht näher bestimmt — erst bestimmen, dann einbuchen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            'purchase_without_material' => sprintf(__('Kauf #%s hat kein Material — eines wählen oder «neu anlegen».', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id of the purchase */
            'already_received'          => sprintf(__('Kauf #%s ist schon eingegangen.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id */
            'not_a_material'            => sprintf(__('#%s ist kein Material.', 'taxmod-stock'), $error->detail),
            /* translators: %s: record id */
            'not_a_location'            => sprintf(__('#%s ist kein Lagerort.', 'taxmod-stock'), $error->detail),
            'material_without_name'     => __('Ein Material ohne «Bezug» braucht eine Bezeichnung.', 'taxmod-stock'),
            /* translators: %s: record id of the purchase */
            'not_in_order'              => sprintf(__('Kauf #%s gehört nicht zu dieser Bestellung.', 'taxmod-stock'), $error->detail),
            /* translators: %s: what was typed */
            'not_a_quantity'            => sprintf(__('«%s» ist keine gültige Menge.', 'taxmod-stock'), $error->detail),
            'nothing_to_book'           => __('Nichts zu buchen: keine Zeile ausgefüllt.', 'taxmod-stock'),
            /* translators: %s: record id of the material */
            'no_lot'                    => sprintf(__('Material #%s hat keinen passenden Bestandsposten.', 'taxmod-stock'), $error->detail),
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
            'purchase_without_material' => __('kein Material', 'taxmod-stock'),
            default                     => $reason,
        };
    }
}
