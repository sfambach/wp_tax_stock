<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Contacts;

/** D-1184: Logistik › Kontakte — the contacts of a node of the contact branch and every node below it. */
final class ContactsTest extends TestCase
{
    /** @return array<int, array{name: string, parent: int, hidden: bool}> */
    private static function tree(): array
    {
        return [
            3083 => ['name' => 'Kontakt', 'parent' => 1, 'hidden' => false],
            13   => ['name' => 'Organisation', 'parent' => 3083, 'hidden' => false],
            100  => ['name' => 'Lieferant', 'parent' => 13, 'hidden' => false],
            101  => ['name' => 'Alt', 'parent' => 13, 'hidden' => true],
            102  => ['name' => 'Unter Alt', 'parent' => 101, 'hidden' => false],
            14   => ['name' => 'Person', 'parent' => 3083, 'hidden' => false],
        ];
    }

    #[Test]
    public function a_node_holds_its_own_contacts_and_those_below_it_but_not_beside_or_hidden(): void
    {
        $records = [13 => [1 => 'Reichelt'], 100 => [2 => 'Mouser'], 102 => [3 => 'Versteckt'], 14 => [4 => 'Stefan Fambach']];
        $rows    = Contacts::rows(self::tree(), 3083, 13, $records, [2 => 3]);

        self::assertSame([2, 1], array_column($rows, 'id'), 'sorted by name, Person and the hidden branch left out');
        self::assertSame('Organisation ▸ Lieferant', $rows[0]['group']);
        self::assertSame(3, $rows[0]['sources']);
        self::assertSame(0, $rows[1]['sources']);
        self::assertSame([2, 1, 4], array_column(Contacts::rows(self::tree(), 3083, 3083, $records, []), 'id'), 'the root holds every visible contact');
    }

    #[Test]
    public function the_search_looks_at_name_group_and_number(): void
    {
        $records = [13 => [1 => 'Reichelt'], 100 => [2 => 'Mouser']];

        self::assertSame([2], array_column(Contacts::rows(self::tree(), 3083, 3083, $records, [], 'liefer'), 'id'));
        self::assertSame([1], array_column(Contacts::rows(self::tree(), 3083, 3083, $records, [], 'reich'), 'id'));
        self::assertSame([2], array_column(Contacts::rows(self::tree(), 3083, 3083, $records, [], '2'), 'id'));
    }

    #[Test]
    public function a_hidden_or_unknown_node_has_no_branch(): void
    {
        self::assertSame([], Contacts::branch(self::tree(), 101));
        self::assertSame([], Contacts::branch(self::tree(), 999));
    }

    #[Test]
    public function a_person_is_named_first_name_then_name_and_a_nameless_record_by_its_number(): void
    {
        self::assertSame('Stefan Fambach', Contacts::displayName('Fambach', 'Stefan', 4));
        self::assertSame('Reichelt', Contacts::displayName('Reichelt', '', 1));
        self::assertSame('#7', Contacts::displayName(' ', '', 7));
    }
}
