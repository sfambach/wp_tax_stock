<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which groups are tested after the goods receipt (D-1002; a group property since D-1259), and the place that holds what
 * waits for its test (D-1111, from the option).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC7
 */
final class TestSettings
{
    /**
     * @param list<int> $branches the groups tested, each already resolved from the group property (D-1259)
     * @param int|null  $place    D-1111: the location record «Lagerort für Test»; null when none is set
     */
    private function __construct(public readonly array $branches, public readonly ?int $place = null)
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

        $place = filter_var($raw['place'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return new self($branches, $place === false ? null : $place);
    }

    /** @return array{branches: list<int>, place: int|null} */
    public function toArray(): array
    {
        return ['branches' => $this->branches, 'place' => $this->place];
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
