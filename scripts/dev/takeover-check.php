<?php declare(strict_types=1);

/**
 * D-1579 against a local WordPress: a new, empty group takes materials over from the group above it — by a filter of the rule
 * book (D-1552), with the unticked left behind, in one act through the move with its value check (D-1073); the button only at
 * an empty group.
 *
 *     php scripts/dev/takeover-check.php [path/to/wordpress]
 *
 * Uses the modeller's no-write bracket: nothing survives the run. Needs the Taxonomy Modeller active (WP_ROOT, TAXMOD_ROOT).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\TakeOver;
use TaxmodStock\WordPress\Admin\Pages;
use TaxmodStock\WordPress\Admin\StockPage;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$kind = 'aggregation';
require __DIR__ . '/lib/struktur.php';
$teile = (int) ($r['teile'] ?? 0);
$pruefe($teile > 0, 'the check structure stands, with a group of materials');

if ($teile === 0) {
    exit(1);
}

$text   = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$answer = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__Displays', 'parent' => $teile],
    ['op' => 'add_field', 'ref' => 'farbe', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__Farbe'],
    ['op' => 'create_node', 'ref' => 'blau', 'name' => '__Blaue', 'parent' => '@m'],
    ['op' => 'create_record', 'ref' => 'a', 'node' => '@m', 'values' => ['@farbe' => 'blau']],
    ['op' => 'create_record', 'ref' => 'b', 'node' => '@m', 'values' => ['@farbe' => 'weiß']],
    ['op' => 'create_record', 'ref' => 'c', 'node' => '@m', 'values' => ['@farbe' => 'blau']],
]]);
$r = is_wp_error($answer) ? [] : (array) ($answer['refs'] ?? []);
$pruefe(isset($r['a'], $r['b'], $r['c'], $r['blau']), 'three displays in «__Displays», an empty «__Blaue» below it');
[$m, $blau, $a, $b, $c, $farbe] = [(int) ($r['m'] ?? 0), (int) ($r['blau'] ?? 0), (int) ($r['a'] ?? 0), (int) ($r['b'] ?? 0), (int) ($r['c'] ?? 0), (int) ($r['farbe'] ?? 0)];

$stock = new StockService();
$pruefe($stock->takeOverOffered($blau), 'the empty group is offered the take-over');
$pruefe(! $stock->takeOverOffered($m), 'a group holding materials is not');

$page = (new StockPage($stock))->groupMaterials($blau, ['page' => Pages::MATERIAL_GROUPS, 'taxmod_node' => (string) $blau]);
$pruefe(str_contains($page, 'uebernehmen=1') && str_contains($page, '__Displays'), 'the empty group shows «Aus «__Displays» übernehmen …»');

$take = TakeOver::fromRows($m, [['field' => $farbe, 'op' => 'equals', 'value' => 'blau']], [$farbe]);
$pruefe($stock->takeOverMatches($take) === [$a, $c] || $stock->takeOverMatches($take) === [$c, $a], 'the filter «__Farbe gleich blau» lets the two blue ones through');
$pruefe(count($stock->takeOverMatches(TakeOver::fromRows($m, [], [$farbe]))) === 3, 'no filter: everything in the group above');

try {
    $stock->takeOver($m, $take, []);
    $pruefe(false, 'a group holding materials is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'not_a_group', 'a group holding materials is refused');
}

try {
    $done = $stock->takeOver($blau, $take, TakeOver::skipped([], [$a, $c], [$a]));
    $after = new StockService();
    $at    = (new \TaxmodStock\WordPress\ModelGateway())->nodesOf([$a, $b, $c]) + [$a => 0, $b => 0, $c => 0];
    $pruefe(! empty($done['act']) && $at[$a] === $blau && $at[$c] === $m && $at[$b] === $m, 'one act: the ticked blue one moved, the unticked and the white one stayed');
    $pruefe(! $after->takeOverOffered($blau), 'afterwards the group is no longer empty — no button');
} catch (\Throwable $e) {
    $pruefe(false, 'the take-over moves the ticked — ' . $e->getMessage());
}

echo $fehler === 0 ? "\nAll checks passed.\n" : "\n{$fehler} check(s) failed.\n";
exit($fehler === 0 ? 0 : 1);
