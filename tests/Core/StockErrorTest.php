<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\StockError;

/** The modeller's refusal, read for what the module can word itself (D-1099). */
final class StockErrorTest extends TestCase
{
    #[Test]
    public function a_head_whose_positions_are_still_referenced_names_head_and_who(): void
    {
        $e = StockError::fromModel('Change 0 (remove_record) refused, nothing was written: Record 90 cannot be deleted: its parts are still referenced — '
            . 'part #80 ← record #95 («Storno von»); part #81 ← record #60 («Kauf»). Remove those references first.');

        self::assertSame('parts_referenced', $e->reason);
        self::assertSame('90|80:95:Storno von;81:60:Kauf', $e->detail);
    }

    #[Test]
    public function any_other_refusal_keeps_the_modellers_words(): void
    {
        $e = StockError::fromModel('Change 2 (set_values) refused, nothing was written: No record 7.');

        self::assertSame('model_refused', $e->reason);
        self::assertSame('Change 2 (set_values) refused, nothing was written: No record 7.', $e->detail);
    }
}
