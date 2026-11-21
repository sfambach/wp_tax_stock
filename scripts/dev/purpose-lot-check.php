<?php declare(strict_types=1);

/**
 * D-1567: the Bezug of a consumption offers the group «Bestand» — a Bestandsposten picked with the record chooser
 * (D-1488) — and the service takes a lot as a Bezug.
 *
 *     php scripts/dev/purpose-lot-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root    = dirname(__DIR__, 2);
$page    = (string) file_get_contents($root . '/src/WordPress/Admin/StockPage.php');
$service = (string) file_get_contents($root . '/src/WordPress/StockService.php');
$red     = 0;

foreach ([
    'the Bezug has no group «Bestand»'                       => str_contains($page, "__('Bestand', 'taxmod-stock') . ' · '"),
    'the Bestandsposten is not picked with the record chooser' => str_contains($page, "recordPicker('purpose_lot'"),
    'the Ausbuchen form does not offer «Bestand»'             => str_contains($page, '$this->purposeRow($presetPlace, \'\', true)'),
    'the lot words are drawn more than once per page'         => substr_count($page, 'modellerAssets(false, true)') === 1 && str_contains($page, '$this->lotWordsDrawn = true'),
    'the form does not send the picked lot as the Bezug'      => str_contains($page, "absint(\$_POST['purpose_lot'] ?? 0)"),
    'the service refuses a lot as the Bezug'                  => str_contains($service, '$ledger->thingOf($purpose->subject) === null'),
] as $what => $green) {
    if (! $green) {
        echo "FAIL {$what} (D-1567)\n";
        $red++;
    }
}

echo $red === 0 ? "purpose-lot-check: green\n" : "purpose-lot-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
