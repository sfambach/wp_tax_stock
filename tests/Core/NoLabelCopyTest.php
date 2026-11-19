<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** D-1296, last step: the labels belong to the labels module; the stock asks it through `taxmod_label_catalog` and keeps no copy. */
final class NoLabelCopyTest extends TestCase
{
    #[Test]
    public function the_stock_keeps_no_label_catalog_of_its_own(): void
    {
        $src   = dirname(__DIR__, 2) . '/src';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // The label's own logic: reading the catalog, its content line, its change list.
            if (preg_match('/function\s+(withContents|forButton|rollsAllowing|maxOf)\s*\(|class\s+Label(Templates|Contents|Changes|Catalog)\b/', $code, $m) === 1) {
                $found[] = substr($file->getPathname(), strlen($src) + 1) . ': ' . $m[0];
            }
        }

        self::assertSame([], $found, 'label logic belongs to wp_tax_label (D-1296)');
    }

    #[Test]
    public function the_stock_asks_the_labels_module(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/StockService.php');

        self::assertStringContainsString("apply_filters('taxmod_label_catalog'", $service);
    }
}
