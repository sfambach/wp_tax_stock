<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What the goods receipt proposes for each purchase of an order, and which purchases stay locked.
 *
 * A purchase that names materials goes in as those. Otherwise its «Modell» decides: the material that already
 * refers to it, or a new one that will. Without either the user chooses (D-967 and the owner's word of
 * 2026-10-21: «wenn nein kann der benutzer suchen oder ein neues anlegen»).
 *
 * ```mermaid
 * flowchart LR
 *   K["Kauf"] -->|Material set| H["keep"]
 *   K -->|Modell| F{"material with this Bezug?"}
 *   F -->|yes| E["existing"]
 *   F -->|no| N["new, Bezug = Modell"]
 *   K -->|neither| U["user chooses"]
 * ```
 */
final class ReceiptPlan
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * @param array<int, true> $received purchases some lot already names
     * @return array<int, array{lock: string|null, model: int|null, materials: list<int>, proposal: ReceiptChoice|null}>
     */
    public function of(int $order, Table $orders, Table $purchases, MaterialNames $names, array $received): array
    {
        $s   = $this->structure;
        $out = [];

        foreach ($orders->all($order, $s->field('order_lines')) as $id) {
            $id        = (int) $id;
            $model     = (int) $purchases->first($id, $s->field('purchase_model'));
            $model     = $model > 0 ? $model : null;
            $materials = array_map('intval', $purchases->all($id, $s->field('purchase_material')));

            $lock = match (true) {
                isset($received[$id])                                              => 'received',
                $purchases->first($id, $s->field('purchase_undetermined')) === '1' => 'purchase_undetermined',
                default                                                            => null,
            };

            $proposal = match (true) {
                $materials !== []                         => ReceiptChoice::keep(),
                $model === null                           => null,
                $names->forThing($model) !== null         => ReceiptChoice::existing((int) $names->forThing($model)),
                default                                   => ReceiptChoice::create($model),
            };

            $out[$id] = ['lock' => $lock, 'model' => $model, 'materials' => $materials, 'proposal' => $proposal];
        }

        return $out;
    }
}
