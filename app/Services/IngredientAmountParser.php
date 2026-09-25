<?php

namespace App\Services;

/**
 * Splits a user supplied amount into a numeric decimal or free text.
 * "1/2", "1,5", "2" become numbers; "podľa chuti", "trochu" stay text.
 */
class IngredientAmountParser
{
    /**
     * @return array{numeric: string|null, text: string|null}
     */
    public function parse(?string $amount): array
    {
        $amount = trim((string) $amount);

        if ($amount === '') {
            return ['numeric' => null, 'text' => null];
        }

        $numeric = $this->toNumber($amount);

        if ($numeric !== null) {
            return ['numeric' => $this->format($numeric), 'text' => null];
        }

        return ['numeric' => null, 'text' => $amount];
    }

    private function toNumber(string $value): ?float
    {
        // Mixed fraction "1 1/2" -> "1-1/2" before spaces are removed.
        $value = preg_replace('/^(\d+)\s+(\d+\/\d+)$/', '$1-$2', $value) ?? $value;
        $value = str_replace([',', ' '], ['.', ''], $value);

        if (preg_match('/^(\d+)\/(\d+)$/', $value, $m)) {
            return $m[2] === '0' ? null : (int) $m[1] / (int) $m[2];
        }

        if (preg_match('/^(\d+)-(\d+)\/(\d+)$/', $value, $m)) {
            return $m[3] === '0' ? null : (int) $m[1] + (int) $m[2] / (int) $m[3];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    public function format(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
