<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\RoundContact;

/** D-1535 (P4): a plain strip's round-contact counterpart — same kind, «Kontaktart» Rundkontakt. */
final class RoundContactTest extends TestCase
{
    private const ROUND = '149000110452';

    #[Test]
    public function the_round_strip_of_the_same_kind_and_poles_is_the_counterpart(): void
    {
        $kinds   = [1 => 'k', 2 => 'k', 3 => 'k', 4 => 'other'];
        $poles   = [1 => 40, 2 => 40, 3 => 40, 4 => 40];
        $contact = [1 => null, 2 => self::ROUND, 3 => null, 4 => self::ROUND];

        self::assertSame([1 => 2, 3 => 2], RoundContact::counterparts([1, 3], $kinds, $poles, $contact, self::ROUND), 'other kind never, plain never');
    }

    #[Test]
    public function without_equal_poles_the_shortest_longer_round_strip_is_taken(): void
    {
        $kinds = [1 => 'k', 2 => 'k', 3 => 'k', 5 => 'k'];
        $poles = [1 => 10, 2 => 40, 3 => 20, 5 => 8];
        $round = [1 => null, 2 => self::ROUND, 3 => self::ROUND, 5 => self::ROUND];

        self::assertSame([1 => 3], RoundContact::counterparts([1], $kinds, $poles, $round, self::ROUND));
    }

    #[Test]
    public function a_round_strip_or_one_without_kind_gets_none(): void
    {
        self::assertSame([], RoundContact::counterparts([2, 6], [2 => 'k', 6 => null, 7 => null], [2 => 40, 6 => 40, 7 => 40], [2 => self::ROUND, 7 => self::ROUND], self::ROUND));
    }
}
