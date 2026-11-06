<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * D-1184: the contacts of the model's contact branch as one list — every record of the chosen node and the nodes below it, with its
 * group and how many sources of the stock name it.
 *
 * ```mermaid
 * flowchart LR
 *   K["Kontakt"] --> O["Organisation"] --> L["Lieferant"]
 *   K --> P["Person"]
 *   R["rows(Organisation)"] -.->|holds| O & L
 * ```
 *
 * The tree comes from one read; hidden nodes are left out with everything below them.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1184
 */
final class Contacts
{
    /** A person's first name stands before the name; a firm has only the name. */
    public static function displayName(string $name, string $firstName, int $id): string
    {
        $word = trim(trim($firstName) . ' ' . trim($name));

        return $word === '' ? '#' . $id : $word;
    }

    /**
     * The nodes of the branch under `$chosen` that are not hidden, nor below a hidden one.
     *
     * @param array<int, array{name: string, parent: int, hidden: bool}> $tree node → row, the root included
     * @return array<int, true>
     */
    public static function branch(array $tree, int $chosen): array
    {
        $parents = [];

        foreach ($tree as $node => $row) {
            if (! $row['hidden']) {
                $parents[$node] = $row['parent'];
            }
        }

        if (! isset($parents[$chosen])) {
            return [];
        }

        return GroupStock::branch($parents, $chosen);
    }

    /**
     * The path of a node below the root, «Organisation ▸ Lieferant»; the root itself is ''.
     *
     * @param array<int, array{name: string, parent: int, hidden: bool}> $tree
     */
    public static function path(array $tree, int $root, int $node): string
    {
        $names = [];

        for ($at = $node, $seen = []; $at !== $root && isset($tree[$at]) && ! isset($seen[$at]); $at = $tree[$at]['parent']) {
            $seen[$at] = true;
            array_unshift($names, $tree[$at]['name']);
        }

        return implode(' ▸ ', $names);
    }

    /**
     * The rows of the list: sorted by name, narrowed by a search over name, group and number.
     *
     * @param array<int, array{name: string, parent: int, hidden: bool}> $tree
     * @param array<int, array<int, string>> $records node → record → display name
     * @param array<int, int> $sources contact → number of sources naming it
     * @return list<array{id: int, name: string, node: int, group: string, sources: int}>
     */
    public static function rows(array $tree, int $root, int $chosen, array $records, array $sources, string $search = ''): array
    {
        $rows = [];

        foreach (array_keys(self::branch($tree, $chosen)) as $node) {
            foreach ($records[$node] ?? [] as $id => $name) {
                $rows[] = ['id' => $id, 'name' => $name, 'node' => $node, 'group' => self::path($tree, $root, $node), 'sources' => $sources[$id] ?? 0];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);

        return self::filter($rows, $search);
    }

    /**
     * The rows a search over name, group and number keeps.
     *
     * @param list<array{id: int, name: string, node: int, group: string, sources: int}> $rows
     * @return list<array{id: int, name: string, node: int, group: string, sources: int}>
     */
    public static function filter(array $rows, string $search): array
    {
        $needle = mb_strtolower(trim($search));

        return $needle === '' ? $rows : array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['name'] . ' ' . $row['group'] . ' ' . $row['id']), $needle)));
    }
    /**
     * D-1185: the nodes below the root as menu items, in tree order. A node with nodes below it is a group of the menu (`path` ends
     * with its own name) and `head`: its own link opens it; the nodes below it stand inside it.
     *
     * @param array<int, array{name: string, parent: int, hidden: bool}> $tree
     * @return list<array{node: int, label: string, path: list<string>, head: bool}>
     */
    public static function menu(array $tree, int $root): array
    {
        $visible  = self::branch($tree, $root);
        $children = [];

        foreach ($tree as $node => $row) {
            if (isset($visible[$node]) && $node !== $root) {
                $children[$row['parent']][] = $node;
            }
        }

        $items = [];
        $walk  = static function (int $parent, array $path) use (&$walk, &$items, $children, $tree): void {
            foreach ($children[$parent] ?? [] as $node) {
                $head    = isset($children[$node]);
                $items[] = ['node' => $node, 'label' => $tree[$node]['name'], 'path' => $head ? [...$path, $tree[$node]['name']] : $path, 'head' => $head];

                if ($head) {
                    $walk($node, [...$path, $tree[$node]['name']]);
                }
            }
        };
        $walk($root, []);

        return $items;
    }

    /**
     * D-1185: how many contacts each node holds, those of the nodes below it included — for the tiles above the list.
     *
     * @param array<int, array{name: string, parent: int, hidden: bool}> $tree
     * @param list<array{node: int}> $rows
     * @return array<int, int>
     */
    public static function counts(array $tree, array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            for ($at = $row['node'], $seen = []; isset($tree[$at]) && ! isset($seen[$at]); $at = $tree[$at]['parent']) {
                $seen[$at]    = true;
                $counts[$at] = ($counts[$at] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The visible nodes directly below `$node`.
     *
     * @param array<int, array{name: string, parent: int, hidden: bool}> $tree
     * @return array<int, string> node → name
     */
    public static function childrenOf(array $tree, int $node): array
    {
        $out = [];

        foreach ($tree as $id => $row) {
            if ($row['parent'] === $node && $id !== $node && ! $row['hidden']) {
                $out[$id] = $row['name'];
            }
        }

        return $out;
    }
}
