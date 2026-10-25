# Taxmod Stock

Stock keeping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: stage 1 built, 0.1.3.** Concept (German):
[`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md),
decisions D-956, D-957, D-958, D-967, D-972.

## What it does

- **Page «Lager»** under the Taxonomy Modeller menu, drawn with the modeller's shared list page (D-989, needs the
  modeller from 0.1.65), five tabs, «Wareneingang» first:
  - **Bestand** — stock per material, with its lots (origin, value, rest) and the minimum stock.
  - **Material** — every material by the name from «Bezug Ding» (D-967), its number in a column of its own (D-988); create one, or edit
    its Bezug, own name (only without a Bezug), Einzelstück, Verwendung, Standard-Lagerort and Mindestbestand.
  - **Inventur** — counted stock without a purchase; each row becomes a lot.
  - **Wareneingang** — the orders as a list, each opening to its purchases (loaded when shown; open from the start by
    the setting «Gleich aufgeklappt»); receive its determined purchases (partial receipts allowed). Each purchase
    goes in as a material: the one that already refers to the purchase's «Modell», else a new one with that Bezug, or
    any other the user searches for; the choice is written into the purchase's «Material». Each purchase also gets a
    proposed place (D-972): the material's Standard-Lagerort, else the place of the branch under `Model` its thing lies
    in, else the default place; the user may change it.
  - **Verbrauch** — take a quantity from a lot; the oldest lot with a rest is proposed.
- **Tab «Lager» on Configuration** — the ids of the Stock nodes and fields, pasted as JSON.
  `structure-live.json` holds those of fambach.net. Below it, «Einlagerung nach Typ»: rows «branch node id → place»
  (the nearest listed branch wins) and the place for materials without a Bezug (consumables).

## How it works

- The modeller stays the data structure. This module owns the processes and never touches the modeller's classes:
  it reads with `taxmod/read-node` and writes with `taxmod/write-data`, one call per booking, all or nothing.
  ⚠️ One exception: to name a material after its «Bezug Ding», the module asks the modeller's record repository which
  node each referenced record lives in (one query), then reads each of those nodes once. No ability answers that yet.
- «Bezug Ding» is searched with the modeller's own record search (`taxmod_record_search`).
- Stock is never stored: it is the sum of the movement lines per lot (Bestandsposten) and per material.

## Development

- `composer install`, then `vendor/bin/phpunit` (core, no WordPress).
- `php scripts/dev/stock-check.php` against a local WordPress with the modeller active; nothing it writes survives.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
