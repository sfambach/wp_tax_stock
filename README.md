# Taxmod Stock

Stock keeping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: stage 1 built, 0.2.0.** Concept (German):
[`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md),
decisions D-956, D-957, D-958, D-972, D-995, D-996, D-997.

## What it does

- **The thing is the material (D-995).** A record in the tree under `Model` is the material; its record id is the
  material number. Lager, lots, purchases and sales are own records pointing to it.
- **Page «Lager»** under the Taxonomy Modeller menu, drawn with the modeller's shared list page (D-989, needs the
  modeller from 0.1.65), four tabs, «Wareneingang» first:
  - **Wareneingang** — the orders as a list, each opening to its purchases; receive determined purchases (partial
    receipts allowed). Each purchase goes in as its «Modell», as another thing searched for, or as a new thing created
    in a chosen group; the choice is written into the purchase's «Modell». Each gets a proposed place (D-972).
  - **Material** — the things per group in the shared table template, the group's technical data as columns, lots
    below each row. «Öffnen» shows one thing: a fixed head (title image, Nr, Bezeichnung, Beschreibung, path) and the
    tabs Technische Daten, Einkauf, Lager (Standard-Lagerort, Mindestbestand, Einzelstück), Bestand (tiles, lots,
    Vorgänge, «Umbuchen», «Ausbuchen») and Verkauf (D-997).
  - **Inventur** — counted stock without a purchase, on an existing thing or a new one in a group.
  - **Verbrauch** — take a quantity from a lot; the oldest lot with a rest is proposed.
- **Tab «Lager» on Configuration** — the ids of the Stock nodes and fields, pasted as JSON.
  `structure-live.json` holds those of fambach.net; a structure pasted before 0.2.0 still reads. Below it,
  «Einlagerung nach Typ»: rows «branch node id → place».

## How it works

- The modeller stays the data structure. This module owns the processes and never touches the modeller's classes:
  it reads with `taxmod/read-node` and `taxmod/read-tree` and writes with `taxmod/write-data`, one call per booking,
  all or nothing.
- Stock is never stored: it is the sum of the movement lines per lot and per thing.

## Development

- `composer install`, then `vendor/bin/phpunit` (core, no WordPress).
- `php scripts/dev/stock-check.php` against a local WordPress with the modeller active; nothing it writes survives.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
