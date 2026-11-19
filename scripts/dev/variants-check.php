<?php declare(strict_types=1);

/**
 * D-1478/D-1479 against a local WordPress: «Als Varianten zusammenfassen» from the stock — one material is refused, two become
 * the variants of a new parent, and the modeller names each variant with its parent for the lists.
 *
 *     php scripts/dev/variants-check.php [path/to/wordpress]
 *
 * Uses the modeller's no-write bracket: nothing survives the run. Needs the Taxonomy Modeller 0.2.155 active (WP_ROOT, TAXMOD_ROOT).
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
use TaxmodStock\WordPress\ModelGateway;
use TaxmodStock\WordPress\StockService;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

// The small Stock structure the other checks build (lib/struktur.php): its «__Teile» is a group the stock takes as material.
$kind = 'aggregation';
require __DIR__ . '/lib/struktur.php';
$teile = (int) ($r['teile'] ?? 0);
$pruefe($teile > 0, 'the check structure stands, with a group of materials');

$stock = new StockService();
$pruefe($stock->knowsVariants(), 'the modeller knows variants (0.2.154)');

if (! $stock->knowsVariants() || $teile === 0) {
    exit(1);
}

$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$apply = static function (array $changes) use ($pruefe): array {
    $answer = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);

    if (is_wp_error($answer)) {
        $pruefe(false, 'apply — ' . $answer->get_error_message());

        return [];
    }

    return (array) $answer;
};
$bau   = $apply([
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__Displays', 'parent' => $teile],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__Controller'],
    ['op' => 'add_field', 'ref' => 'farbe', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__Farbe'],
    ['op' => 'add_field', 'ref' => 'von', 'node' => '@m', 'target' => '@m', 'name' => '__Variante von', 'kind' => 'aggregation'],
    ['op' => 'set_addons', 'node' => $text?->id ?? 0, 'field' => '@farbe', 'addons' => [['name' => 'variant']]],
    ['op' => 'set_addons', 'node' => '@m', 'field' => '@von', 'addons' => [['name' => 'variant_of']]],
    ['op' => 'create_record', 'ref' => 'a', 'node' => '@m', 'values' => ['@name' => 'SSD1306', '@farbe' => 'blau']],
    ['op' => 'create_record', 'ref' => 'b', 'node' => '@m', 'values' => ['@name' => 'SSD1306', '@farbe' => 'weiß']],
]);
$r = $bau['refs'] ?? [];
$pruefe(isset($r['a'], $r['b']), 'two displays that differ only in colour');
// The tree read before the build does not know the new group.
$stock = new StockService();

try {
    $stock->makeVariants([(int) ($r['a'] ?? 0)]);
    $pruefe(false, 'one material alone is refused');
} catch (StockError $e) {
    $pruefe($e->reason === 'variants_need_two', 'one material alone is refused');
}

try {
    $answer = $stock->makeVariants([(int) ($r['a'] ?? 0), (int) ($r['b'] ?? 0)]);
    $links  = (new ModelGateway())->variantParents([(int) ($r['m'] ?? 0)]);
    $parent = (int) ($links[(int) $r['a']] ?? 0);
    $pruefe($parent > 0 && ($links[(int) $r['b']] ?? 0) === $parent && ! empty($answer['act']), 'both become variants of one new parent, one act');
} catch (\Throwable $e) {
    $pruefe(false, 'both become variants of one new parent, one act — ' . $e->getMessage());
}

$lager = new \TaxmodStock\Core\LagerMerge($stock->structure());
$pruefe($lager->answer((int) ($r['a'] ?? 0), (int) ($r['b'] ?? 0), $stock->table('lager')) === [], 'D-1480: materials without a Lager record of their own — the stock has nothing to say');

echo $fehler === 0 ? "\nAll checks passed.\n" : "\n{$fehler} check(s) failed.\n";
exit($fehler === 0 ? 0 : 1);
