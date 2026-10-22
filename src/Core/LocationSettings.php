<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The Lager tab's assignment of branches under `Model` to places, as stored in one option (D-972).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/konzept.md §3.1
 */
final class LocationSettings
{
    /** @param array<int, int> $branches branch node → location record */
    private function __construct(public readonly array $branches, public readonly ?int $unreferenced)
    {
    }

    /** @param array<string, mixed> $raw the stored option, or the tab's rows already turned into this shape */
    public static function fromArray(array $raw): self
    {
        $branches = [];

        foreach (is_array($raw['branches'] ?? null) ? $raw['branches'] : [] as $node => $location) {
            $node     = filter_var($node, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $location = filter_var($location, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($node !== false && $location !== false) {
                $branches[$node] = $location;
            }
        }

        $unreferenced = filter_var($raw['unreferenced'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return new self($branches, $unreferenced === false ? null : $unreferenced);
    }

    /** @return array{branches: array<int, int>, unreferenced: int|null} */
    public function toArray(): array
    {
        return ['branches' => $this->branches, 'unreferenced' => $this->unreferenced];
    }
}
