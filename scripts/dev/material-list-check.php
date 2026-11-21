<?php declare(strict_types=1);

/**
 * The material list (his screenshot 2026-11-21 17:14 «boar eh was für ne anzeige»): a narrow actions column of its own,
 * group and place a share each, and the Umbuchen square in every row with stock — locked where several lots lie, drawn by
 * the same building block as the active one (D-1511 rule 3). D-1568: the search also finds the other names of a material,
 * read once for the list; without the field in the structure the list runs as before.
 *
 *     php scripts/dev/material-list-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/src/WordPress/Admin/StockPage.php');
$structure = (string) file_get_contents($root . '/src/Core/Structure.php');
$red  = 0;

foreach ([
    'the list does not name its narrow actions column'    => str_contains($page, 'ListView::onePage(count($units), self::PER_PAGE), self::MATERIAL_ACTIONS)'),
    'group has no share of the width'                      => str_contains($page, "new ListColumn('group', __('Gruppe', 'taxmod-stock'), width: '15%')"),
    'place has no share of the width'                      => str_contains($page, "new ListColumn('places', __('Lagerort', 'taxmod-stock'), width: '15%')"),
    'several lots leave the Umbuchen square out'           => str_contains($page, "ListPage::iconButton(self::icon('ICON_TRANSFER'), __('Umbuchen', 'taxmod-stock'), disabled:"),
    // D-1568: the other names come in one read for the whole list (CD-7), and only where the structure names the field.
    'the search does not read the other names once for the list' => str_contains($page, "\$this->stock->fieldWords(array_keys(\$all), ['thing_aliases'])['thing_aliases'] ?? []"),
    'a row does not carry its other names as search words'       => str_contains($page, "'words' => isset(\$aliases[\$id]) ? [\$aliases[\$id]] : []"),
    'the other names are not an optional structure field'        => str_contains($structure, "        'thing_aliases',\n"),
] as $what => $green) {
    if (! $green) {
        echo "FAIL {$what}\n";
        $red++;
    }
}

echo $red === 0 ? "material-list-check: green\n" : "material-list-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
