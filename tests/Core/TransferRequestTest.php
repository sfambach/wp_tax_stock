<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\TransferRequest;
use TaxmodStock\Core\TransferRow;

/** The rows of taxmod-stock/book-transfer are checked whole before anything is booked. */
final class TransferRequestTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows @return list<TransferRow> */
    private function rows(array $rows): array
    {
        $rest = [7 => [1 => 10.0], 8 => [1 => 2.5]];

        return TransferRequest::rows($rows, static fn (int $lot): bool => isset($rest[$lot]), static fn (int $place): bool => in_array($place, [1, 2, 3], true),
            static fn (int $lot, int $place): float => $rest[$lot][$place] ?? 0.0);
    }

    #[Test]
    public function each_row_moves_its_quantity_and_an_empty_one_moves_what_is_left(): void
    {
        $moves = $this->rows([['lot' => 7, 'from' => 1, 'to' => 2, 'quantity' => '4'], ['lot' => 7, 'from' => 1, 'to' => 3], ['lot' => 8, 'from' => 1, 'to' => 2, 'quantity' => '2,5']]);

        self::assertSame([[7, 1, 2, '4'], [7, 1, 3, '6'], [8, 1, 2, '2.5']], array_map(static fn (TransferRow $m): array => [$m->lot, $m->from, $m->to, $m->quantity], $moves));
    }

    #[Test]
    public function a_wrong_row_is_named_and_nothing_comes_back(): void
    {
        foreach ([
            'row 2: lot 9 does not exist'      => [['lot' => 7, 'from' => 1, 'to' => 2, 'quantity' => '1'], ['lot' => 9, 'from' => 1, 'to' => 2]],
            'row 1: place 4 is no storage'     => [['lot' => 7, 'from' => 1, 'to' => 4]],
            'row 1: from and to are the same'  => [['lot' => 7, 'from' => 1, 'to' => 1]],
            'row 2: lot 7 has 4 at place 1'    => [['lot' => 7, 'from' => 1, 'to' => 2, 'quantity' => '6'], ['lot' => 7, 'from' => 1, 'to' => 3, 'quantity' => '5']],
            'row 1: lot 8 has 0 at place 2'    => [['lot' => 8, 'from' => 2, 'to' => 1]],
        ] as $why => $rows) {
            try {
                $this->rows($rows);
                self::fail('refused: ' . $why);
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith($why, $e->getMessage());
            }
        }
    }
}
