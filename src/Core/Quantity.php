<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * A quantity as typed in a form — «3», «2,5», «0.25» — turned into the decimal text the model stores.
 */
final class Quantity
{
    /** A positive decimal; the sign of a movement is the booking's business, not the user's. */
    public static function positive(string $given): string
    {
        $text = str_replace(',', '.', trim($given));

        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1 || (float) $text <= 0.0) {
            throw StockError::notAQuantity($given);
        }

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : ltrim($text, '0');
    }

    /** A money amount; zero is a valid price (a gift, a part from a donor). */
    public static function amount(string $given): string
    {
        $text = str_replace(',', '.', trim($given));

        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            throw StockError::notAQuantity($given);
        }

        return $text;
    }
}
