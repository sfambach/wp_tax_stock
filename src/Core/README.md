# Core

What stock is, computed from the model's records: the structure of ids (`Structure`), the stock per lot and material
(`Ledger`), the change lists of a booking (`Bookings`) and of a material (`MaterialChanges`), what a material is
called (`MaterialNames`, D-967) what the goods receipt proposes per purchase (`ReceiptPlan`) and where it puts it (`LocationRules`, `LocationSettings`, D-972). Loads no WordPress (CD-1); tested in `tests/Core`.

Concept: [`docs/pakete/lager/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md) §3.1, §3.5, §3.5a, §4.
