<?php

namespace App\Support;

/**
 * Money is stored as an integer number of nanodollars (1 USD = 1_000_000_000).
 * Pixelforge reports costs with nine decimals, so this is lossless and avoids floats entirely.
 */
final class Money
{
    public const NANO = 1_000_000_000;

    /** Parse a decimal USD string ("0.373875", "5.00") into nanodollars, rounding half up. */
    public static function fromUsd(string $usd): int
    {
        return (int) bcadd(bcmul($usd, (string) self::NANO, 1), '0.5', 0);
    }

    /** Format nanodollars as an exact USD string with nine decimals. */
    public static function toUsd(int $nano): string
    {
        return bcdiv((string) $nano, (string) self::NANO, 9);
    }

    /** Human display: at least 2 decimals, trimming trailing zeros up to 9 ("$0.37", "$0.000575"). */
    public static function display(int $nano): string
    {
        $usd = self::toUsd($nano);
        $trimmed = rtrim($usd, '0');
        [$whole, $frac] = explode('.', $trimmed.(str_contains($trimmed, '.') ? '' : '.'));

        return '$'.$whole.'.'.str_pad($frac, 2, '0');
    }
}
