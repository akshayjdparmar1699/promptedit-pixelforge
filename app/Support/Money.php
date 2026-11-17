<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money is stored as integer nanodollars (1 USD = 1,000,000,000).
 * Pixelforge reports exact amounts with nine decimal places, so nanodollars
 * represent every provider amount without rounding and without floats.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    public static function decimalToNanos(string $decimal): int
    {
        $decimal = trim($decimal);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not an exact decimal with at most 9 places: [{$decimal}]");
        }

        $nanos = (int) $m[2] * self::NANOS_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nanos : $nanos;
    }

    public static function nanosToDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        return $sign.intdiv($abs, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($abs % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    public static function dollarsToNanos(int $dollars): int
    {
        return $dollars * self::NANOS_PER_DOLLAR;
    }
}
