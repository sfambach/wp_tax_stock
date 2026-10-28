<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * What the goods receipt proposes for each purchase of an order, and which purchases stay locked.
 *
 * D-995: the thing is the material. A purchase with a «Modell» goes in as that thing; without one the user
 * chooses an existing thing or creates one in a group of the tree.
 *
 * ```mermaid
 * flowchart LR
 *   K["Kauf"] -->|Modell| H["keep: that thing"]
 *   K -->|none| U["user chooses or creates"]
 * ```
 */
final class ReceiptPlan
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * @param array<int, true> $received purchases some lot already names
     * @return array<int, array{lock: string|null, model: int|null, proposal: ReceiptChoice|null}>
     */
    public function of(int $order, Table $orders, Table $purchases, array $received): array
    {
        $s   = $this->structure;
        $out = [];

        foreach ($orders->all($order, $s->field('order_lines')) as $id) {
            $id    = (int) $id;
            $model = (int) $purchases->first($id, $s->field('purchase_model'));
            $model = $model > 0 ? $model : null;

            $lock = match (true) {
                isset($received[$id])                                              => 'received',
                $purchases->first($id, $s->field('purchase_undetermined')) === '1' => 'purchase_undetermined',
                default                                                            => null,
            };

            $out[$id] = ['lock' => $lock, 'model' => $model, 'proposal' => $model === null ? null : ReceiptChoice::keep()];
        }

        return $out;
    }
}
