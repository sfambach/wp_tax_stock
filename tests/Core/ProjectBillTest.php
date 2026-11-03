<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\ProjectBill;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1139: the parts list of a project against what lies in its box. */
final class ProjectBillTest extends TestCase
{
    #[Test]
    public function the_newest_revision_of_each_board_makes_the_list(): void
    {
        $bill = $this->bill();

        self::assertSame([12], $bill->revisions);
        self::assertSame([100 => 3.0, 101 => 1.0], $bill->needed);
    }

    #[Test]
    public function it_names_what_is_missing_and_what_is_too_much(): void
    {
        $rows = $this->bill()->compare([100 => 1.0, 102 => 2.0]);

        self::assertSame([
            ['thing' => 100, 'needed' => 3.0, 'present' => 1.0, 'missing' => 2.0, 'surplus' => 0.0],
            ['thing' => 101, 'needed' => 1.0, 'present' => 0.0, 'missing' => 1.0, 'surplus' => 0.0],
            ['thing' => 102, 'needed' => 0.0, 'present' => 2.0, 'missing' => 0.0, 'surplus' => 2.0],
        ], $rows);
        self::assertSame(2, $this->bill()->missingParts([100 => 1.0]));
        self::assertSame(0, $this->bill()->missingParts([100 => 3.0, 101 => 1.0]));
    }

    #[Test]
    public function without_the_fields_there_is_no_list(): void
    {
        self::assertFalse(ProjectBill::isModelled(Fixture::structure()));
        self::assertTrue(ProjectBill::isModelled($this->structure()));
    }

    private function bill(): ProjectBill
    {
        $s = $this->structure();

        return ProjectBill::of(
            $s,
            new Table([1 => [$s->field('project_boards') => ['5']]]),
            new Table([5 => [$s->field('board_revisions') => ['11', '12']]]),
            new Table([11 => [$s->field('revision_positions') => ['20']], 12 => [$s->field('revision_positions') => ['21', '22', '23']]]),
            new Table([
                20 => [$s->field('position_part') => ['999'], $s->field('position_count') => ['9']],
                21 => [$s->field('position_part') => ['100'], $s->field('position_count') => ['2']],
                22 => [$s->field('position_part') => ['101'], $s->field('position_count') => ['1']],
                23 => [$s->field('position_part') => ['100'], $s->field('position_count') => ['1']],
            ]),
            1
        );
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['fields'] += ['project_boards' => 400, 'board_revisions' => 401, 'revision_positions' => 402, 'position_part' => 403, 'position_count' => 404];

        return Structure::fromArray($raw);
    }
}
