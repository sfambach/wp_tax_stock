<?php declare(strict_types=1);

/**
 * D-1438, the question «Platz leer?» after an Umbuchen (his word 2026-11-21: «die null durchgangs abfrage dialog ist immer
 * hinter dem umbuchen dialog evtl umbuche vorher verstecken»): drawn after the page and alone — every other open dialog closes.
 *
 *     php scripts/dev/source-question-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/src/WordPress/Admin/StockPage.php');
$red  = 0;

foreach ([
    'the question is drawn before the page, under its dialogs' => str_contains($page, "['html' => \$html . \$question . \$this->script("),
    'the question is still added at the top'                   => ! str_contains($page, '$html .= $this->sourceEmptyQuestion();'),
    'other open dialogs stay open behind the question'         => str_contains($page, 'document.querySelectorAll(".taxmod-dialog-switch:checked").forEach(function(sw){if(sw!==me){sw.checked=false;}});'),
] as $what => $green) {
    if (! $green) {
        echo "FAIL {$what}\n";
        $red++;
    }
}

echo $red === 0 ? "source-question-check: green\n" : "source-question-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
