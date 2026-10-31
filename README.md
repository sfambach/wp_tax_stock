# Taxmod Stock

Stock keeping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: stage 1 built, purchasing added, 0.4.2.** Concept (German):
[`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md),
decisions D-956, D-957, D-958, D-972, D-995, D-996, D-997, D-1001, D-1004.

## What it does

- **The thing is the material (D-995).** A record in the tree under `Model` is the material; its record id is the
  material number. Lager, lots, purchases and sales are own records pointing to it.
- **No menu of its own** (D-1028): Wareneingang, Lager and Einkauf hang under the Taxonomy Modeller menu, like every Taxmod module.
- **Page «Wareneingang»** (D-1001), the goods receipt cockpit: every order in one stage, a tab per stage —
  Bestellt → Versendet → Angekommen → Eingebucht. The stage is computed from what the inbox already wrote to the order
  (status, «Eingegangen am», tracking numbers) and the DHL module's last answer (filter `taxmod_dhl_tracking`); nothing
  extra is stored. Buttons: «Angekommen» (sets «Eingegangen am»), «Zurück», a tracking number by hand, «DHL ↻». In
  «Angekommen» each order opens to the booking form of «Lager › Wareneingang». Filter «Überfällig» (14 days without a
  tracking number, 21 days on the way). Concept:
  [`docs/pakete/lager/cockpit-wareneingang.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md).
- **Page «Lager»**, drawn with the modeller's shared list page (D-989, needs the
  modeller from 0.1.65), four tabs, «Wareneingang» first:
  - **Wareneingang** — the orders as a list, each opening to its purchases; receive determined purchases (partial
    receipts allowed). Each purchase goes in as its «Modell» or as another thing picked in the modeller's record chooser
    (search or tree, both over the groups of things; D-1011); the choice is written into the purchase's «Modell». Each gets a proposed place
    (D-972). «Zuordnung speichern» keeps the chosen materials without booking anything.
  - **Material** — the things per group in the shared table template, the group's technical data as columns, lots
    below each row. «Öffnen» shows one thing: a fixed head (title image, Nr, Bezeichnung, Beschreibung, path) and the
    tabs Technische Daten, Einkauf, Lager (Standard-Lagerort, Mindestbestand, Einzelstück), Bestand (tiles, lots,
    Vorgänge, «Umbuchen», «Ausbuchen») and Verkauf (D-997).
    A purchase «noch nicht näher bestimmt» (a lot, Konvolut) is first told apart with «Aufteilen» (concept R3): the
    purchase becomes its first part, every further part a purchase of the same order, each with its material.
  - **Inventur** — counted stock without a purchase, on an existing thing or a new one in a group.
  - **Verbrauch** — take a quantity from a lot; the oldest lot with a rest is proposed.
- **Page «Einkauf»** (D-1027), purchasing in three steps: a **demand** without a supplier (an order
  «Entwurf» without «Händler»), a **source** per position (firm, article number, price — the position moves into that
  supplier's draft and keeps pointing at its demand, field «Bedarf» of the purchase), and **one order per supplier**, which
  he orders at the shop himself and then marks «bestellt» with its number; from there the cockpit takes over. Tabs:
  Nachbestellen (below the minimum, counting what is wanted or ordered) · Bedarf · Bestellen (with a cart list
  «article number quantity» for the shop's quick order) · Bestellt · Verworfen. Every step has its way back: position back
  into the demand, «bestellt» back to draft while nothing arrived, discard (cancelled, not deleted) and restore.
  The button **«Nachbestellen»** on every material opens the dialog: quantity (minimum − stock − open, at least 1) and an
  open demand or a new one. **«Angebote»** on an open position asks the supplier modules (filter `taxmod_supplier_offers`,
  first [Taxmod Mouser](https://github.com/sfambach/wp_tax_api_mouser)) for offers — number, stock, price for the quantity —
  and «Übernehmen» makes one the position's source. Concept:
  [`docs/pakete/lager/einkauf.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/einkauf.md).
- **Tab «Lager» on Configuration** — the ids of the Stock nodes and fields, pasted as JSON; and «Kein Material»: branches
  under Model that hold no material (e.g. the source register), left out of search, tree and groups (also settable over
  REST as `taxmod_stock_not_material`).
  `structure-live.json` holds those of fambach.net; a structure pasted before 0.2.0 still reads. Below it,
  «Einlagerung nach Typ»: rows «branch node id → place».

## How it works

- The modeller stays the data structure. This module owns the processes and never touches the modeller's classes:
  it reads with `taxmod/read-node` and `taxmod/read-tree` and writes with `taxmod/write-data`, one call per booking,
  all or nothing.
- Stock is never stored: it is the sum of the movement lines per lot and per thing.

## Development

- `composer install`, then `vendor/bin/phpunit` (core, no WordPress).
- `php scripts/dev/stock-check.php` and `php scripts/dev/inbound-check.php` against a local WordPress with the modeller active; nothing it writes survives.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
