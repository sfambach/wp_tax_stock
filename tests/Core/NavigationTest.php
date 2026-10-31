<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\Navigation;

final class NavigationTest extends TestCase
{
    private function navigation(): Navigation
    {
        return new Navigation([
            ['key' => 'cockpit', 'label' => 'Cockpit', 'items' => [['key' => 'cockpit', 'label' => 'Cockpit', 'page' => 'c', 'tab' => '']]],
            ['key' => 'inbound', 'label' => 'Wareneingang', 'items' => [
                ['key' => 'arrived', 'label' => 'Angekommen', 'page' => 'in', 'tab' => 'arrived'],
                ['key' => 'book', 'label' => 'Einbuchen', 'page' => 'stock', 'tab' => 'wareneingang'],
            ]],
            ['key' => 'stock', 'label' => 'Bestand', 'items' => [
                ['key' => 'material', 'label' => 'Material', 'page' => 'stock', 'tab' => 'material'],
                ['key' => 'ship', 'label' => 'Versand', 'page' => 'other', 'tab' => '', 'url' => 'https://x/other'],
            ]],
            ['key' => 'later', 'label' => 'Auswertungen', 'items' => []],
        ]);
    }

    #[Test]
    public function the_exact_tab_wins_and_a_page_without_one_opens_its_first_item(): void
    {
        $nav = $this->navigation();

        self::assertSame(['area' => 'stock', 'item' => 'material'], $nav->active('stock', 'material'));
        self::assertSame(['area' => 'inbound', 'item' => 'book'], $nav->active('stock', ''));
        self::assertSame(['area' => 'inbound', 'item' => 'arrived'], $nav->active('in', 'shipped'));
        self::assertNull($nav->active('other', ''));
    }

    #[Test]
    public function empty_areas_and_pages_of_other_modules_are_left_out(): void
    {
        $nav = $this->navigation();

        self::assertSame(['cockpit', 'inbound', 'stock'], array_column($nav->areas(), 'key'));
        self::assertSame(['c', 'in', 'stock'], $nav->pages());
    }
}
