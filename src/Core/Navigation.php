<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * The stock interface's own navigation along the flow of goods (D-1029, design sections 2 and 7.1): areas, each with its
 * tabs as sub-items, and which of them the current page and tab open. Labels come from the caller, already translated.
 *
 * ```mermaid
 * flowchart LR
 *   A["area"] -->|first item| I1["item = page + tab"]
 *   A --> I2["item"]
 *   R["request: page, tab"] -->|exact, else first item of the page| I2
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1029, D-1053
 */
final class Navigation
{
    /**
     * @param list<array{key: string, label: string, items: list<array{key: string, label: string, page: string, tab: string, url?: string}>}> $areas
     *        an item with `url` leaves the stock interface (a page of another module or of the modeller)
     */
    public function __construct(private readonly array $areas)
    {
    }

    /** @return list<array{key: string, label: string, items: list<array{key: string, label: string, page: string, tab: string, url?: string}>}> areas without items are left out */
    public function areas(): array
    {
        return array_values(array_filter($this->areas, static fn (array $area): bool => $area['items'] !== []));
    }

    /**
     * The item the request opens: the one with this page and tab, else — a page opened without a tab, or on a tab that has no
     * item of its own — the first item of that page.
     *
     * @return array{area: string, item: string}|null null when the page is not part of the stock interface
     */
    public function active(string $page, string $tab): ?array
    {
        $first = null;

        foreach ($this->areas() as $area) {
            foreach ($area['items'] as $item) {
                if (isset($item['url']) || $item['page'] !== $page) {
                    continue;
                }

                if ($item['tab'] === $tab) {
                    return ['area' => $area['key'], 'item' => $item['key']];
                }

                $first ??= ['area' => $area['key'], 'item' => $item['key']];
            }
        }

        return $first;
    }

    /** @return list<string> every page of the stock interface, once */
    public function pages(): array
    {
        $pages = [];

        foreach ($this->areas() as $area) {
            foreach ($area['items'] as $item) {
                if (! isset($item['url'])) {
                    $pages[$item['page']] = true;
                }
            }
        }

        return array_keys($pages);
    }
}
