# Taxmod Stock

Stock keeping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: concept, nothing built.** The draft concept (German) lives with the modeller:
[`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md).

## Idea in short

- The Taxonomy Modeller stays the data structure (parts, projects, bills of materials, orders, sales).
- This module owns the processes: goods receipt, consumption, build runs, stock count, transfer, reservation, demand.
- Every process writes stock movements as records into the model through the modeller's `taxmod/apply` path.
- Stock on hand is the sum of movements, never a stored number.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
