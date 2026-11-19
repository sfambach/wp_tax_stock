<?php declare(strict_types=1);

/**
 * D-1474 against a local WordPress: Stammdaten › Auswahllisten adds, renames, merges and deletes a value through the modeller's
 * door, refuses to delete a chosen value, and «Rückgängig» takes the last change back.
 *
 *     php scripts/dev/choice-lists-check.php [path/to/wordpress]
 *
 * Uses the modeller's no-write bracket: nothing survives the run. Needs the Taxonomy Modeller 0.2.152 active (WP_ROOT, TAXMOD_ROOT).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxmodStock\Core\StockError;
use TaxmodStock\WordPress\Admin\ChoiceListsPage;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$stock = new StockService();
$pruefe($stock->canKeepChoiceLists(), 'the modeller offers the choice-list door (0.2.152)');

if (! $stock->canKeepChoiceLists()) {
    exit(1);
}

$root  = $stock->choiceRoot();
$apply = static fn (array $changes): array => (array) wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);
$bau   = $apply([
    ['op' => 'create_node', 'ref' => 'liste', 'name' => '__Farben', 'parent' => $root, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'rot', 'name' => '__Rot', 'parent' => '@liste'],
    ['op' => 'create_node', 'ref' => 'rost', 'name' => '__Rostrot', 'parent' => '@liste'],
    ['op' => 'create_node', 'ref' => 'teile', 'name' => '__Teile', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'farbe', 'node' => '@teile', 'name' => '__Farbe', 'target' => '@liste', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'satz', 'node' => '@teile', 'values' => ['@farbe' => '@rost']],
]);
$r = $bau['refs'] ?? [];
$pruefe(isset($r['liste'], $r['satz']), 'a list below the Konstanten with a chosen value is built');

$silber = $stock->addChoice((int) $r['liste'], '__Silber');
$pruefe(isset($stock->choiceTree()[$silber]) && $stock->choiceTree()[$silber]['parent'] === (int) $r['liste'], '«+ Wert» adds the value to the list');
$pruefe($stock->choiceUses([(int) $r['rost'], $silber]) === [(int) $r['rost'] => 1], 'uses: the chosen value counts 1, the new one nothing');

$umbenannt = $stock->renameChoice($silber, '__Silbern');
$pruefe($umbenannt > 0 && $stock->choiceTree()[$silber]['name'] === '__Silbern', '«Umbenennen» renames, one act');
$stock->undoChoice($umbenannt);
$pruefe($stock->choiceTree()[$silber]['name'] === '__Silber', 'the rename is undone by its number');

try {
    $stock->trashChoice((int) $r['rost']);
    $pruefe(false, 'a chosen value is not deleted');
} catch (StockError $e) {
    $pruefe(isset($stock->choiceTree()[(int) $r['rost']]), 'a chosen value is not deleted, the modeller says why');
}

try {
    $stock->mergeChoice((int) $r['rost'], (int) $r['teile']);
    $pruefe(false, 'a merge out of the list is refused');
} catch (StockError) {
    $pruefe(true, 'a merge out of the list is refused');
}

$zusammen = $stock->mergeChoice((int) $r['rost'], (int) $r['rot']);
$pruefe($zusammen > 0 && ! isset($stock->choiceTree()[(int) $r['rost']]) && $stock->choiceUses([(int) $r['rot']]) === [(int) $r['rot'] => 1], '«Zusammenführen»: the record chooses Rot, Rostrot left the list');
$stock->undoChoice($zusammen);
$pruefe(isset($stock->choiceTree()[(int) $r['rost']]) && $stock->choiceUses([(int) $r['rost']]) === [(int) $r['rost'] => 1], 'the merge is undone by its number');

$geloescht = $stock->trashChoice($silber);
$pruefe($geloescht > 0 && ! isset($stock->choiceTree()[$silber]), '«Löschen» takes the unused value out of the list');

$_GET = ['page' => ChoiceListsPage::PAGE, 'tab' => (string) $r['liste']];
$seite = (new ChoiceListsPage($stock))->render();
$pruefe(str_contains($seite, '__Rot') && str_contains($seite, '__Rostrot') && str_contains($seite, 'Verwendet') && str_contains($seite, 'name="booking" value="neu"'), 'the list page shows its values, their uses and «+ Wert»');
$pruefe(substr_count($seite, ' disabled title="') === 1, 'only the chosen value has its delete button switched off');

$_GET = ['page' => ChoiceListsPage::PAGE];
$pruefe(str_contains((new ChoiceListsPage($stock))->render(), '__Farben'), 'the Konstanten open as tiles, one per list or category');

echo $fehler === 0 ? "\nAll checks passed.\n" : "\n{$fehler} check(s) failed.\n";
exit($fehler === 0 ? 0 : 1);
