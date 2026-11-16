<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\StockError;
use TaxmodStock\Core\StripCut;

/** D-1398: every piece whole from one strip; the rest goes back; rests and shorter strips first. */
final class StripCutTest extends TestCase
{
    #[Test]
    public function ten_pieces_1x6_from_1x40_open_two_strips_and_leave_4_and_16(): void
    {
        $plan = StripCut::plan(10, 6, ['a' => 40, 'b' => 40, 'c' => 40]);

        self::assertSame(0, $plan->missing);
        self::assertSame([['strip' => 'a', 'columns' => 40, 'pieces' => 6, 'rest' => 4], ['strip' => 'b', 'columns' => 40, 'pieces' => 4, 'rest' => 16]], $plan->uses);
        self::assertSame(['a' => 4, 'b' => 16], $plan->rests());
    }

    #[Test]
    public function a_rest_that_fits_is_cut_before_a_whole_strip_and_one_too_short_is_left(): void
    {
        $plan = StripCut::plan(3, 4, ['new' => 40, 'r16' => 16, 'r3' => 3, 'r4' => 4]);

        self::assertSame([['strip' => 'r4', 'columns' => 4, 'pieces' => 1, 'rest' => 0], ['strip' => 'r16', 'columns' => 16, 'pieces' => 2, 'rest' => 8]], $plan->uses);
    }

    #[Test]
    public function loss_per_cut_counts_between_pieces_not_behind_the_last(): void
    {
        self::assertSame(['a' => 4], StripCut::plan(4, 8, ['a' => 40], 1)->rests(), '40 − 4 × 9');
        self::assertSame([], StripCut::plan(2, 8, ['a' => 17], 1)->rests(), '8 + 1 + 8 uses all 17');
    }

    #[Test]
    public function what_no_strip_serves_is_named_missing(): void
    {
        self::assertSame(4, StripCut::plan(10, 6, ['a' => 40])->missing);
    }

    #[Test]
    public function no_piece_or_no_column_is_refused(): void
    {
        $this->expectException(StockError::class);
        StripCut::plan(0, 6, ['a' => 40]);
    }
}
