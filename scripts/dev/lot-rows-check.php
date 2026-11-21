<?php declare(strict_types=1);

/**
 * The lots of a material (his words 2026-11-21 «zwei felder und das untere kann ich nicht drucken», «kein absprung zum ort in
 * item»): an opened material row names its lots and prints their label; the place links to its page.
 *
 *     php scripts/dev/lot-rows-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/src/WordPress/Admin/StockPage.php');
$red  = 0;

foreach ([
    'an opened row does not name its lots'        => str_contains($page, "esc_html(implode(', ', array_map(static fn (int \$lot): string => '#' . \$lot, \$line['lots'])))"),
    'an opened row does not print its lots'       => str_contains($page, "\$this->lotLabel(\$thing, \$lot, \$name, \$charges[\$lot] ?? ''), \$line['lots'])"),
    'an opened row does not link its place'       => str_contains($page, "'ort' => \$line['place']], admin_url('admin.php'))) . '\">'"),
    'the lot table does not link its place'       => str_contains($page, "'ort' => \$place], admin_url('admin.php'))) . '\">' . esc_html(\$places[\$place]) . '</a>'"),
    // Stefan 21.11.2026: 1056 lots at one place killed «Platzbestand» — it draws one page of rows.
    'a place draws all its lots at once'           => str_contains($page, "\$rows  = ListView::page(\$rows, \$paged, self::PER_PAGE);")
        && str_contains($page, "ListPage::pagination(\$count, \$paged, self::PER_PAGE, static fn (array \$args): string => add_query_arg(\$args + ['page' => self::PAGE, 'tab' => 'lagerorte'"),
] as $what => $green) {
    if (! $green) {
        echo "FAIL {$what}\n";
        $red++;
    }
}

echo $red === 0 ? "lot-rows-check: green\n" : "lot-rows-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
