# Taxmod Stock

Stock keeping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: stage 1 built (0.1.0), not released.** Concept (German):
[`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md),
decisions D-956, D-957, D-958.

## What it does

- **Page «Lager»** under the Taxonomy Modeller menu, four tabs:
  - **Bestand** — stock per material, with its lots (origin, value, rest) and the minimum stock.
  - **Inventur** — counted stock without a purchase; each row becomes a lot.
  - **Wareneingang** — pick an order, receive its determined purchases (partial receipts allowed).
  - **Verbrauch** — take a quantity from a lot; the oldest lot with a rest is proposed.
- **Tab «Lager» on Configuration** — the ids of the Stock nodes and fields, pasted as JSON.
  `structure-live.json` holds those of fambach.net.

## How it works

- The modeller stays the data structure. This module owns the processes and never touches the modeller's classes:
  it reads with `taxmod/read-node` and writes with `taxmod/write-data`, one call per booking, all or nothing.
- Stock is never stored: it is the sum of the movement lines per lot (Bestandsposten) and per material.

## Development

- `composer install`, then `vendor/bin/phpunit` (core, no WordPress).
- `php scripts/dev/stock-check.php` against a local WordPress with the modeller active; nothing it writes survives.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
