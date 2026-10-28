# Core

What stock is, computed from the model's records: the structure of ids (`Structure`), the stock per lot and thing
(`Ledger`), the things that are the materials (`Things`, D-995), the change lists of a booking (`Bookings`) and of a
thing's Lager record (`LagerChanges`, D-997), what the goods receipt proposes per purchase (`ReceiptPlan`,
`ReceiptChoice`, `NewThing`) and where it puts it (`LocationRules`, `LocationSettings`, D-972). Loads no WordPress
(CD-1); tested in `tests/Core`.

Concept: [`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md) §3.1, §3.5, §3.5a, §4;
decisions D-995, D-996, D-997.
