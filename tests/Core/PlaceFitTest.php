<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\FitLevel;
use TaxmodStock\Core\PlaceFit;
use TaxmodStock\Core\VolumeFit;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1158: the outer size of a box against the inner size of the place it goes into. */
final class PlaceFitTest extends TestCase
{
    #[Test]
    public function by_sides_a_box_fits_when_every_side_fits_turned_or_not(): void
    {
        $fit = $this->fit('sides');

        self::assertTrue($fit->fits(1, 2));
        self::assertFalse($fit->fits(3, 2));
    }

    #[Test]
    public function by_volume_the_long_thin_box_fits_too(): void
    {
        $fit = $this->fit();

        self::assertTrue($fit->fits(1, 2));
        self::assertTrue($fit->fits(3, 2));
        self::assertFalse((new VolumeFit())->fits([10.0, 10.0, 11.0], [10.0, 10.0, 10.0]));
    }

    #[Test]
    public function the_kind_it_goes_into_chooses_no_check_and_its_message(): void
    {
        $fit = $this->fit();

        self::assertNull($fit->fits(1, 5));
        self::assertSame(FitLevel::Error, $fit->levelOf(2));
        self::assertSame(FitLevel::Warning, $fit->levelOf(5));
    }

    #[Test]
    public function no_verdict_without_sizes_or_in_other_units(): void
    {
        $fit = $this->fit();

        self::assertNull($fit->fits(2, 1));
        self::assertNull($fit->fits(4, 2));
    }

    private function fit(string $rule = ''): PlaceFit
    {
        $raw = Fixture::structure()->toArray();
        $raw['nodes'] += ['location_type' => 20, 'dimension' => 22];
        $raw['values'] += ['fit_volume' => 90, 'fit_sides' => 91, 'fit_none' => 92, 'level_info' => 93, 'level_warning' => 94, 'level_error' => 95];
        $raw['fields'] += ['type_fit_rule' => 315, 'type_fit_level' => 316, 'type_outer' => 310, 'type_inner' => 311, 'dimension_length' => 312, 'dimension_width' => 313, 'dimension_height' => 314];
        $s      = Structure::fromArray($raw);
        $amount = $s->field('money_amount');
        $unit   = $s->field('money_unit');
        $sides  = static fn (int $first): array => [312 => [(string) $first], 313 => [(string) ($first + 1)], 314 => [(string) ($first + 2)]];
        $value  = static fn (string $n, string $u = 'mm'): array => [$amount => [$n], $unit => [$u]];

        return PlaceFit::from($s, new Table([
            1 => [310 => ['50']],
            2 => [311 => ['60'], 316 => ['95']] + ($rule === 'sides' ? [315 => ['91']] : []),
            5 => [311 => ['60'], 315 => ['92']],
            3 => [310 => ['70']],
            4 => [310 => ['80']],
        ]), new Table([50 => $sides(100), 60 => $sides(200), 70 => $sides(300), 80 => $sides(400)]), new Table([
            // Box 1: 150 × 50 × 140, i.e. turned against the place's 160 × 50 × 300.
            100 => $value('150'), 101 => $value('50'), 102 => $value('140'),
            200 => $value('50'), 201 => $value('300'), 202 => $value('160,5'),
            300 => $value('310'), 301 => $value('10'), 302 => $value('10'),
            400 => $value('1', 'cm'), 401 => $value('1', 'cm'), 402 => $value('1', 'cm'),
        ]));
    }
}
