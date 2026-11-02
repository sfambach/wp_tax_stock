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
 *   R["request: page, tab, module"] -->|exact, else first item of the page and module, else of the page| I2
 * ```
 *
 * Every item is a page of the stock interface: a module's page is an item too, opened inside the frame by its `module`
 * (D-1085: nothing under Logistik leaves the logistics navigation).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1029, D-1053, D-1085
 */
final class Navigation
{
    /**
     * @param list<array{key: string, label: string, items: list<array{key: string, label: string, page: string, tab: string, module?: string}>}> $areas
     *        an item with `module` shows that module's page inside the frame
     */
    public function __construct(private readonly array $areas)
    {
    }

    /** @return list<array{key: string, label: string, items: list<array{key: string, label: string, page: string, tab: string, module?: string}>}> areas without items are left out */
    public function areas(): array
    {
        return array_values(array_filter($this->areas, static fn (array $area): bool => $area['items'] !== []));
    }

    /**
     * The item the request opens: the one with this page, tab and module, else — a page opened without a tab, or on a tab that
     * has no item of its own — the first item of that page and module, else the first item of that page.
     *
     * @return array{area: string, item: string}|null null when the page is not part of the stock interface
     */
    public function active(string $page, string $tab, string $module = ''): ?array
    {
        $first = null;
        $any   = null;

        foreach ($this->areas() as $area) {
            foreach ($area['items'] as $item) {
                if ($item['page'] !== $page) {
                    continue;
                }

                $found = ['area' => $area['key'], 'item' => $item['key']];
                $any ??= $found;

                if (($item['module'] ?? '') !== $module) {
                    continue;
                }

                if ($item['tab'] === $tab) {
                    return $found;
                }

                $first ??= $found;
            }
        }

        return $first ?? $any;
    }

    /** @return list<string> every page of the stock interface, once */
    public function pages(): array
    {
        $pages = [];

        foreach ($this->areas() as $area) {
            foreach ($area['items'] as $item) {
                $pages[$item['page']] = true;
            }
        }

        return array_keys($pages);
    }
}
