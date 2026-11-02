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
            ]],
            ['key' => 'settings', 'label' => 'Einstellungen', 'items' => [
                ['key' => 'basics', 'label' => 'Grundeinstellungen', 'page' => 'basics', 'tab' => ''],
                ['key' => 'module-ebay', 'label' => 'eBay', 'page' => 'module', 'tab' => '', 'module' => 'ebay'],
                ['key' => 'module-dhl', 'label' => 'DHL', 'page' => 'module', 'tab' => '', 'module' => 'dhl'],
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
    }

    #[Test]
    public function empty_areas_are_left_out_and_every_item_is_a_page_of_the_stock_interface(): void
    {
        $nav = $this->navigation();

        self::assertSame(['cockpit', 'inbound', 'stock', 'settings'], array_column($nav->areas(), 'key'));
        self::assertSame(['c', 'in', 'stock', 'basics', 'module'], $nav->pages());
    }

    #[Test]
    public function a_module_page_opens_its_own_item_inside_the_frame(): void
    {
        $nav = $this->navigation();

        // D-1085: the modules share one page of the stock interface and are told apart by the module.
        self::assertSame(['area' => 'settings', 'item' => 'module-dhl'], $nav->active('module', '', 'dhl'));
        self::assertSame(['area' => 'settings', 'item' => 'module-dhl'], $nav->active('module', 'some-tab-of-dhl', 'dhl'));
        self::assertSame(['area' => 'settings', 'item' => 'module-ebay'], $nav->active('module', '', 'gone'));
        self::assertNull($nav->active('other', ''));
    }
}
