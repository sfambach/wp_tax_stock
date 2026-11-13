<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\CutPlan;
use TaxmodStock\Core\CutSources;
use TaxmodStock\Core\StockError;

/** D-1328 (D9): a missing strip is cut from a longer one of the same kind; loss per cut after D-1178. */
final class CutTest extends TestCase
{
    #[Test]
    public function only_longer_strips_of_the_same_kind_are_proposed_shortest_first(): void
    {
        $kind  = CutSources::kindOf(5, null, 'p254', 'gerade', 'pm');
        $kinds = [8 => $kind, 40 => $kind, 20 => $kind, 41 => CutSources::kindOf(5, null, 'p254', 'gerade', 'stapelbar'), 42 => CutSources::kindOf(5, null, 'p254', 'abgewinkelt', 'pm'), 6 => $kind];
        $poles = [8 => 8, 40 => 40, 20 => 20, 41 => 40, 42 => 40, 6 => 6];
        $stock = [40 => [100 => [7 => 3.0]], 20 => [101 => [7 => 1.0, 9 => 2.0]], 41 => [102 => [7 => 5.0]], 42 => [103 => [7 => 5.0]], 6 => [104 => [7 => 9.0]]];

        $out = (new CutSources($kinds, $poles))->for([8 => 2.0], $stock, 9);

        self::assertSame([20, 40], array_column($out[8], 'thing'), 'shortest first; other Bauform, orientation and shorter strips never');
        self::assertSame(7, $out[8][0]['place'], 'nothing from the box it goes into');
    }

    #[Test]
    public function a_strip_without_pitch_or_orientation_gets_no_proposal(): void
    {
        self::assertNull(CutSources::kindOf(5, null, null, 'gerade', null));
        self::assertNull(CutSources::kindOf(5, null, 'p254', '', null));
        self::assertSame(CutSources::kindOf(5, null, 'p', 'o', null), CutSources::kindOf(5, '1', 'p', 'o', null), 'no rows counts as one row');
    }

    #[Test]
    public function cutting_strips_counts_loss_per_cut_and_leaves_rests(): void
    {
        $plan = CutPlan::of(3, 8, 40, 1, false);
        self::assertSame(1, $plan->strips);
        self::assertSame([13 => 1], $plan->rests, '40 − 3 × (8 + 1) = 13');

        $plan = CutPlan::of(5, 8, 40, 0, false);
        self::assertSame(1, $plan->strips);
        self::assertSame([], $plan->rests, 'five pieces use the whole strip');

        $plan = CutPlan::of(6, 10, 40, 1, false);
        self::assertSame(2, $plan->strips, 'three pieces fit one strip: (40 + 1) over 11');
        self::assertSame([7 => 2], $plan->rests, 'each strip leaves 40 − 3 × 11');
    }

    #[Test]
    public function a_strip_counted_in_pins_gives_pins_and_no_rest(): void
    {
        $plan = CutPlan::of(3, 8, 40, 1, true);
        self::assertSame(27, $plan->strips);
        self::assertSame([], $plan->rests);
    }

    #[Test]
    public function a_strip_not_longer_than_the_piece_is_refused(): void
    {
        $this->expectException(StockError::class);
        CutPlan::of(1, 40, 40, 0, false);
    }
}
