<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Money;

use InvalidArgumentException;

/**
 * Decimal strings to integer cents and back — pure string arithmetic, no
 * float in the path. Doctrine hands DECIMAL columns over as strings; the
 * form value objects validate and normalise before anything reaches here,
 * so a malformed string is wiring and throws.
 */
final class Cents
{
    private function __construct() {}

    /**
     * '1234.56', '1234,56', '1234' → 123456. Two fraction digits at most —
     * the columns are DECIMAL(…,2) and the forms enforce it first.
     */
    public static function fromDecimal(string $decimal): int
    {
        $normalized = str_replace(',', '.', trim($decimal));

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('Not a non-negative 2-decimal string: "%s".', $decimal));
        }

        $fraction = str_pad($matches[2] ?? '0', 2, '0');

        return ((int) $matches[1]) * 100 + (int) $fraction;
    }

    public static function toDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents % 100));
    }
}
