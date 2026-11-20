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
        self::assertSame([['strip' => 'a', 'columns' => 40, 'pieces' => 6, 'cuts' => [6 => 6], 'rest' => 4, 'scrap' => 0], ['strip' => 'b', 'columns' => 40, 'pieces' => 4, 'cuts' => [6 => 4], 'rest' => 16, 'scrap' => 0]], $plan->uses);
        self::assertSame(['a' => 4, 'b' => 16], $plan->rests());
    }

    #[Test]
    public function a_rest_that_fits_is_cut_before_a_whole_strip_and_one_too_short_is_left(): void
    {
        $plan = StripCut::plan(3, 4, ['new' => 40, 'r16' => 16, 'r3' => 3, 'r4' => 4]);

        self::assertSame([['strip' => 'r4', 'columns' => 4, 'pieces' => 1, 'cuts' => [4 => 1], 'rest' => 0, 'scrap' => 0], ['strip' => 'r16', 'columns' => 16, 'pieces' => 2, 'cuts' => [4 => 2], 'rest' => 8, 'scrap' => 0]], $plan->uses);
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
    public function all_lengths_of_an_order_share_one_plan_longest_first(): void
    {
        // Pin-Aufbereitung P1: 2 × 1×5, 1 × 1×6 and 3 × 1×2 from a rest 1×6 and two 1×40.
        $plan = StripCut::mixed([5 => 2, 6 => 1, 2 => 3], ['r6' => 6, 'a' => 40, 'b' => 40], 0, 2);

        self::assertSame(0, $plan->missing);
        self::assertSame(1, $plan->opened(40), 'one whole strip opened');
        self::assertSame([6 => 1], $plan->uses[0]['cuts'], 'the 1×6 takes the rest 1×6 whole');
        self::assertSame([5 => 2, 2 => 3], $plan->uses[1]['cuts']);
        self::assertSame(['a' => 24], $plan->rests());
    }

    #[Test]
    public function a_rest_of_one_pin_is_avoided_where_another_strip_takes_the_piece(): void
    {
        // P2: a 1×3 from a rest 1×4 would leave one pin — the 1×40 is cut instead.
        $plan = StripCut::plan(1, 3, ['r4' => 4, 'a' => 40], 0, 2);

        self::assertSame(['a' => 37], $plan->rests());
        self::assertSame([], $plan->scrap());
    }

    #[Test]
    public function a_rest_shorter_than_the_minimum_that_cannot_be_avoided_is_scrap(): void
    {
        $plan = StripCut::plan(1, 39, ['a' => 40], 0, 2);

        self::assertSame([], $plan->rests());
        self::assertSame(['a' => 1], $plan->scrap());
    }

    #[Test]
    public function a_shorter_rest_than_the_strip_is_preferred_over_opening_a_new_one(): void
    {
        // His order: «erst passende, dann kleinere Reste zerlegen, dann 40er» — 3 × 1×2 from a 1×6 rather than from a 1×40.
        $plan = StripCut::plan(3, 2, ['a' => 40, 'r6' => 6], 0, 2);

        self::assertSame(0, $plan->opened(40));
        self::assertSame([], $plan->rests());
    }

    #[Test]
    public function no_piece_or_no_column_is_refused(): void
    {
        $this->expectException(StockError::class);
        StripCut::plan(0, 6, ['a' => 40]);
    }
}
