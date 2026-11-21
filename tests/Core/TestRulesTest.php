<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;
use TaxmodStock\Core\TestRules;

/** D-1541: the test rules handed to the modeller's rule book — name (optional), node, position, filters «Feld · Operator · Wert». */
final class TestRulesTest extends TestCase
{
    #[Test]
    public function the_records_become_the_rule_books_shape_and_an_operator_not_built_is_passed_on(): void
    {
        $s       = $this->structure();
        $f       = static fn (string $key): int => $s->field($key);
        $rules   = new Table([
            70 => [$f('label') => [' SMD prüfen '], $f('test_rule_node') => ['20'], $f('test_rule_position') => ['2'], $f('test_rule_filters') => ['80', '81']],
            71 => [$f('test_rule_position') => ['1']],
        ]);
        $filters = new Table([
            80 => [$f('test_filter_field') => ['555'], $f('test_filter_value') => [' SMD '], $f('test_filter_operator') => ['600']],
            81 => [$f('test_filter_field') => ['556'], $f('test_filter_value') => ['5'], $f('test_filter_operator') => ['601']],
        ]);
        $book    = TestRules::fromTables($s, $rules, $filters);

        self::assertSame([
            ['id' => 70, 'name' => 'SMD prüfen', 'position' => 2, 'conditions' => [TestRules::NODE => 20], 'filters' => [
                ['field' => 555, 'value' => 'SMD', 'operator' => TestRules::EQUALS],
                ['field' => 556, 'value' => '5', 'operator' => '601'],
            ]],
            ['id' => 71, 'name' => '', 'position' => 1, 'conditions' => [TestRules::NODE => 0], 'filters' => []],
        ], $book->book);
        self::assertSame([555, 556], $book->fields());
        self::assertSame([70], array_column($book->holding(), 'id'), 'a rule without its node never holds');
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
        $raw['nodes']  += ['test_rule' => 60, 'test_rule_filter' => 61];
        $raw['fields'] += ['test_rule_node' => 160, 'test_rule_position' => 161, 'test_rule_filters' => 162, 'test_filter_field' => 163, 'test_filter_value' => 164, 'test_filter_operator' => 165];
        $raw['values'] += ['operator_equals' => 600];

        return Structure::fromArray($raw);
    }
}
