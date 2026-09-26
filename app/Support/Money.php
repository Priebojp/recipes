<?php

namespace App\Support;

/**
 * Formatting helpers for integer money. Micro-USD (1 USD = 1 000 000) is used for AI provider costs.
 */
class Money
{
    public const MICRO = 1_000_000;

    public static function microUsd(?int $micro, int $decimals = 4): string
    {
        if ($micro === null) {
            return '–';
        }

        return number_format($micro / self::MICRO, $decimals, ',', ' ').' USD';
    }

    /**
     * Parse a decimal USD amount typed by a person ("0,053" or "0.053") into micro-USD. Null when empty/invalid.
     */
    public static function parseUsdToMicro(?string $input): ?int
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $input);
        if (! preg_match('/^\d+(\.\d{1,6})?$/', $normalized)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 6), 6, '0');

        return (int) $whole * self::MICRO + (int) $fraction;
    }

    /** Parse "3,99" / "3.99" / "24" typed by a person into EUR cents. Null when empty or invalid. */
    public static function parseEurToCents(?string $input): ?int
    {
        $normalized = str_replace([' ', ',', '€'], ['', '.', ''], trim((string) $input));
        if ($normalized === '' || ! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function microToUsdString(?int $micro): string
    {
        if ($micro === null) {
            return '';
        }

        return rtrim(rtrim(sprintf('%d.%06d', intdiv($micro, self::MICRO), $micro % self::MICRO), '0'), '.');
    }
}
