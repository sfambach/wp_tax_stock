<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which branches under `Model` are tested after the goods receipt (D-1002), as stored in one option.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC7
 */
final class TestSettings
{
    /** @param list<int> $branches */
    private function __construct(public readonly array $branches)
    {
    }

    /** @param array<string, mixed> $raw the stored option, or the tab's rows already turned into this shape */
    public static function fromArray(array $raw): self
    {
        $branches = [];

        foreach (is_array($raw['branches'] ?? null) ? $raw['branches'] : [] as $node) {
            $node = filter_var($node, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($node !== false && ! in_array($node, $branches, true)) {
                $branches[] = $node;
            }
        }

        return new self($branches);
    }

    /** @return array{branches: list<int>} */
    public function toArray(): array
    {
        return ['branches' => $this->branches];
    }

    /**
     * Whether a node lies in a tested branch: the node itself or one of its ancestors is listed.
     *
     * @param array<int, int> $parents node → parent, the tree of the groups
     */
    public function covers(int $node, array $parents): bool
    {
        for ($at = $node, $seen = []; $at > 0 && ! isset($seen[$at]); $at = $parents[$at] ?? 0) {
            if (in_array($at, $this->branches, true)) {
                return true;
            }

            $seen[$at] = true;
        }

        return false;
    }
}
