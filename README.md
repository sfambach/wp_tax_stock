# Taxmod Stock

Stock keeping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: stage 1 built, purchasing added, 0.4.2.** Concept (German):
[`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md),
decisions D-956, D-957, D-958, D-972, D-995, D-996, D-997, D-1001, D-1004.

## What it does

- **The thing is the material (D-995).** A record in the tree under `Model` is the material; its record id is the
  material number. Lager, lots, purchases and sales are own records pointing to it.
- **No menu of its own** (D-1028, D-1085): the Taxmod menu shows Modeller, Logistik, maintenance and Configuration; everything of the stock and of the connection modules opens behind «Logistik».
- **Tiles** (D-1149, needs the modeller from 0.1.153): every tile — the cockpit, the area overviews, a material's Bestand —
  is the modeller's shared tile. Below the due tiles the cockpit shows the **key figures** chosen on any node of the model
  (add-on «Kennzahl (Kachel)» in the node's mask: count or sum per month, filter, date field). Concept:
  [`docs/pakete/kacheln/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/kacheln/konzept.md).
- **Zeitraum** (D-1167, needs the modeller from 0.1.165): Logistik › Cockpit › Zeitraum shows one day, month or year —
  switched and stepped back and forth — with tiles per kind of Vorgang against the period before, a chart of in and out,
  the Vorgänge of the period, the things moved most, and the model's key figures for the same period.
- **Page «Wareneingang»** (D-1001), the goods receipt cockpit: every order in one stage, a tab per stage —
  Bestellt → Versendet → Angekommen → Eingebucht. The stage is computed from what the inbox already wrote to the order
  (status, «Eingegangen am», tracking numbers) and the DHL module's last answer (filter `taxmod_dhl_tracking`); nothing
  extra is stored. Buttons: «Angekommen» (sets «Eingegangen am»), «Zurück», a tracking number by hand, «DHL ↻». In
  «Angekommen» each order opens to the booking form of «Lager › Wareneingang». Filter «Überfällig» (14 days without a
  tracking number, 21 days on the way). Concept:
  [`docs/pakete/lager/cockpit-wareneingang.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md).
- **Page «Lager»**, drawn with the modeller's shared list page (D-989, needs the
  modeller from 0.1.65); the tabs are the menu group «Bestand» (Material, Inventur, Verbrauch, Reservierungen,
  Lagerorte); «Wareneingang» (Eingang → Einbuchen) and «Lagerstruktur» (Einstellungen) stand without them:
  - **Wareneingang** — the orders as a list, each opening to its purchases; receive determined purchases (partial
    receipts allowed). Each purchase goes in as its «Modell» or as another thing picked in the modeller's record chooser
    (search or tree, both over the groups of things; D-1011); the choice is written into the purchase's «Modell». Each gets a proposed place
    (D-972). «Zuordnung speichern» keeps the chosen materials without booking anything.
  - **Material** — the things per group in the shared table template, the group's technical data as columns, lots
    below each row. From 0.34.0 (D-1147, needs the modeller from 0.1.151) the list waits for a search, a filter or a
    group before it draws anything; «Alle anzeigen» shows everything, page by page. The lots of a row — like the
    positions on «Einkauf» and the purchases on «Wareneingang» — are loaded only when the row is opened. «Öffnen» shows one thing: a fixed head (title image, Nr, Bezeichnung, Beschreibung, path) and the
    tabs Technische Daten, Einkauf, Lager (Standard-Lagerort, Mindestbestand, Einzelstück), Bestand (tiles, lots,
    Vorgänge, «Umbuchen», «Ausbuchen») and Verkauf (D-997).
    «Umbuchen» (D-1039, from 0.11.0) books a Vorgang «Umbuchung» with the lines «− old place, + new place», for a whole
    lot or part of it; a lot then lies at several places, its quantity per place is the sum of its lines there (a line
    without a place lies where its lot came in). «Stornieren» takes an Umbuchung back with a counter-booking (D-1036).
    A place can be «reserviert für Produktion» (D-1082, from 0.12.0, set on «Lagerstruktur»): what lies on it or below
    it is reserved, computed from where it lies. Strict: freed only by moving it out, consumed only after that, and goods
    already reserved are not moved or hung into such an area. The Bestand tab shows «Reserviert» and «Verfügbar».
  - **Several people at once** (D-1148, from 0.35.1, needs the modeller from 0.1.152): every booking form — stock, goods receipt,
    purchasing — runs one at a time. Two pickers who both see «1 left» cannot both take it: the second waits, reads the stock
    again and is refused with the stock's own message if it is gone.
  - **Reservierungen** (D-1083, from 0.13.0) — one reservation per subject (project, sale): a Vorgang «Reservierung» with
    Bezug, date, deadline and positions (material, quantity, lot 0..1). It lowers the available stock, not the stock;
    its lines move nothing. «Reservieren» on the tab and on a material's Bestand tab; «Stornieren» whole or per position
    (D-1036). Each reserved place shows its one reservation, computed from what lies there, read-only.
    A purchase «noch nicht näher bestimmt» (a lot, Konvolut) is first told apart with «Aufteilen» (concept R3): the
    purchase becomes its first part, every further part a purchase of the same order, each with its material.
  - **Lagerorte** (D-1090, from 0.14.0) — the places as the modeller's tree on the left (search, fold, a click opens a
    place; needs the modeller from 0.1.123), on the right what lies there and below it, one row per lot and place with
    «Umbuchen» and «Ausbuchen». A row dragged onto a place in the tree asks for the Umbuchung (D-1039).
  - **Bestand für Leser über MCP** (D-1209, from 0.59.0) — the ability `taxmod-stock/stock` answers what lies at a place
    and every place inside it, or how much there is of given materials: one row per lot and place with quantity, and the
    total per material, from the ledger — instead of loading every lot and transaction.
  - **Lagerstruktur** (Logistik → Einstellungen, D-1085, D-1090) — the same tree; on the right the place with
    «Bearbeiten» (the modeller's form), «Verschieben» (the target in the same tree, the place and what lies in it left
    out), «Neuer Unterort» and «Für Produktion reservieren», each in the shared dialog; «+ Neuer Lagerort» above the tree.
    A place dragged onto another (or onto «oberste Ebene») is hung there after a dialog, with everything in it.
    Below the place, its **Unterorte** (from 0.32.0) in the shared table template with checkboxes and two bulk actions:
    «Etiketten drucken …» (one label each, as on the place itself; print service or one PDF) and «Verschieben nach …»
    (each with everything in it, one write; a box as a Vorgang «Umlagerung»).
    A box is a carrier, not stock (D-1091): its move is a Vorgang «Umlagerung» (field «Ladungsträger», two positions
    without a lot, − old place, + new place), listed at the box; no lot is rebooked. Needs `values.kind_relocation` and
    `fields.transaction_carrier` in the structure; without them the box is hung as before, without a Vorgang.
  - **Gruppenbestand** (D-1174, from 0.47.0, needs the modeller from 0.1.167 for the fixed tree; before that a choice list) —
    a node of the material tree is a **material group**. The material tree stands on the left; a click on a group shows,
    on the right, every material in it and in the groups below it, in the material list's table (Nr, Material, Gruppe,
    Lagerort, Bestand, Mindestbestand; checkboxes, bulk «Umbuchen», filter, lots loaded when a row opens), with tiles above:
    materials, with stock, below minimum, total quantity (left out when the materials count in different units).
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
- **Tab «Lager» on Configuration** — only the technical part: the ids of the Stock nodes and fields, pasted as JSON.
  `structure-live.json` holds those of fambach.net; a structure pasted before 0.2.0 still reads.
- **Logistik → Kontakte** (D-1184, D-1185) — the model's contact branch (Organisation with Hersteller/Lieferant, Person).
  Its nodes are the items of the area «Kontakte» (a node with nodes below it is a menu group that opens the node itself);
  the area itself lists all contacts. Every node shows a tile per node directly below it with its number of contacts, then
  the shared list (Nr., Name, Gruppe, Bezugsquellen); a row opens to the materials whose sources name it, «Bearbeiten»
  opens the modeller's record editor, «+ Neuer Kontakt» creates one in the chosen node by its name (a person's first name
  too) and can be taken back right after. The root is `nodes.contact` of the structure (3083 on fambach.net), otherwise
  the firms' node.
- **Logistik → Einstellungen → Grundeinstellungen** (D-1085) — the business settings, inside the logistics navigation:
  «Kein Material» (branches under Model that hold no material, left out of search, tree and groups; also settable over
  REST as `taxmod_stock_not_material`), «Einlagerung nach Typ» (rows «branch node id → place», D-972), the test after the
  goods receipt with its «Lagerort für Test» (D-1111; option `taxmod_stock_tests` = `{branches: [node ids], place: location
  record id}`, also settable over REST) and the view of the tabs. Same option names as before on the Configuration tab.
- **Test place at the receipt** (D-1111, from 0.18.0): «Einlagern» proposes «Lagerort für Test» for a purchase whose material
  lies in a tested branch (the rule of «Zu testen», D-1002), unless a place was saved for it; the material text in the row
  opens the same dialog as its «Material» button.
- **Module pages inside the navigation** (D-1085): the connection modules' pages under the Taxmod menu open at
  `admin.php?page=taxmod-lager-modul&module=<slug>` inside the logistics frame and leave the WP menu. A module may hand over
  its page with the filter `taxmod_logistics_pages` (`slug`, `label`, `area` settings|outbound, `capability`, `render`
  returning a string); otherwise its own page callback is drawn and its output captured.

## How it works

- The modeller stays the data structure. This module owns the processes and never touches the modeller's classes:
  it reads with `taxmod/read-node` and `taxmod/read-tree` and writes with `taxmod/write-data`, one call per booking,
  all or nothing.
- Stock is never stored: it is the sum of the movement lines per lot and per thing.
- **Positions belong to their head** (D-1097, D-1098, D-1099, from 0.17.0). Whether «Positionen» of Vorgänge and of
  Bestellungen is an aggregation or a composition is read from the model's fields, so the module works before and after
  the switch. As a composition, new Pos lines and purchases are written as parts inside the head's `set_values`, a position
  that changes its order (Bedarf ↔ Händler-Entwurf) is re-hung with `move_part` and keeps its id, a removed position is one
  `remove_record`, and «Zurück» from «Eingebucht» removes the receipt Vorgang and lets the modeller take its lines with it.
  A head whose position another record still points at is not deleted; the notice names who. Needs the modeller from
  0.1.125 once a list is a composition.

## Development

- `composer install`, then `vendor/bin/phpunit` (core, no WordPress).
- `php scripts/dev/stock-check.php`, `php scripts/dev/inbound-check.php` and `php scripts/dev/navigation-check.php` against a local WordPress with the modeller active; nothing it writes survives. The first two run twice, with «Positionen» as aggregation and as composition (D-1097); `TAXMOD_STOCK_KIND=aggregation|composition` runs one.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
