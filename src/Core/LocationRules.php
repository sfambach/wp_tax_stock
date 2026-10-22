<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Where a goods receipt puts a purchase: the proposal the owner may change (D-972).
 *
 * Which branch of `Model` goes where is data from the Lager tab, never a node named in code. The nearest
 * listed branch above the thing's node wins, so «Grafikkarten» may go elsewhere than the rest of «PC».
 *
 * ```mermaid
 * flowchart LR
 *   M{"material has a Standard-Lagerort?"} -->|yes| A["that place"]
 *   M -->|no| T{"thing under a listed branch?"}
 *   T -->|yes| B["place of the nearest branch"]
 *   T -->|no thing at all| V["place for «ohne Bezug»"]
 *   T -->|no| D["default place"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §3.1
 */
final class LocationRules
{
    /**
     * @param array<int, int>             $branches branch node → location record
     * @param array<int, array<int, int>> $subtrees branch node → (node in its subtree → steps below the branch)
     */
    public function __construct(
        private readonly array $branches,
        private readonly array $subtrees,
        private readonly ?int $unreferenced,
        private readonly int $fallback,
    ) {
    }

    /** The place of the nearest listed branch above `$node`, or null if none lists it. */
    public function forNode(int $node): ?int
    {
        $best = null;
        $near = PHP_INT_MAX;

        foreach ($this->branches as $branch => $location) {
            $steps = $this->subtrees[$branch][$node] ?? ($node === $branch ? 0 : null);

            if ($steps !== null && $steps < $near) {
                [$best, $near] = [$location, $steps];
            }
        }

        return $best;
    }

    /** @param int|null $thingNode the node of the purchase's thing; null when it has none */
    public function propose(?int $materialDefault, ?int $thingNode): int
    {
        return match (true) {
            $materialDefault !== null => $materialDefault,
            $thingNode === null       => $this->unreferenced ?? $this->fallback,
            default                   => $this->forNode($thingNode) ?? $this->fallback,
        };
    }
}
