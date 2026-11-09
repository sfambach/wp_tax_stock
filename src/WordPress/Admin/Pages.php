<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

/**
 * The address (`page=`) of every page of the stock interface, in one small class: the menu, the navigation and the module
 * list name all pages on every admin request, and naming them through the page classes compiled those whole classes —
 * about 400 KB — for every admin page view (D-1187).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/gestaltung-und-architektur.md
 */
final class Pages
{
    public const COCKPIT  = 'taxmod-lager-cockpit';
    public const PURCHASE = 'taxmod-stock-einkauf';
    public const INBOUND  = 'taxmod-logistik';
    public const STOCK    = 'taxmod-stock';
    public const CONTACTS = 'taxmod-lager-kontakte';
    public const SETTINGS = 'taxmod-lager-einstellungen';
    public const MODULE   = 'taxmod-lager-modul';
}
