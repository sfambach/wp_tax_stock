<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TestRules;

/** D-1541: the test rules handed to the modeller's rule book — node and position. */
final class TestRulesTest extends TestCase
{
    #[Test]
    public function the_records_become_the_rule_books_shape(): void
    {
        $s     = $this->structure();
        $f     = static fn (string $key): int => $s->field($key);
        $rules = new Table([
            70 => [$f('test_rule_node') => ['20'], $f('test_rule_position') => ['2']],
            71 => [$f('test_rule_position') => ['1']],
        ]);

        self::assertSame([
            ['id' => 70, 'position' => 2, 'conditions' => [TestRules::NODE => 20]],
            ['id' => 71, 'position' => 1, 'conditions' => [TestRules::NODE => 0]],
        ], TestRules::fromTable($s, $rules)->book, 'a rule without a node holds for every material');
        self::assertTrue(TestRules::isConfigured($s) && ! TestRules::isConfigured(Fixture::structure()));
        self::assertTrue(TestRules::none()->isEmpty());
    }

    #[Test]
    public function a_material_counts_with_its_node_and_every_node_above(): void
    {
        self::assertSame([TestRules::NODE => [30, 20, 10]], TestRules::context(30, [30 => 20, 20 => 10]));
        self::assertSame([TestRules::NODE => [36, 37]], TestRules::context(36, [36 => 37, 37 => 36]), 'a loop in the tree ends');
        self::assertSame([TestRules::NODE => []], TestRules::context(null, []));
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['nodes']  += ['test_rule' => 60];
        $raw['fields'] += ['test_rule_node' => 160, 'test_rule_position' => 161];

        return Structure::fromArray($raw);
    }
}
