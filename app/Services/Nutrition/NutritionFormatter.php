<?php

namespace App\Services\Nutrition;

/**
 * Rounding and labels for display only. Stored values stay unrounded; "647,238 kcal" is not more trustworthy than
 * "približne 645 kcal", so energy is shown to the nearest 5 (10 above 1 000) and grams to one decimal below 10 g.
 * An unknown value is shown as a dash, never as zero.
 */
class NutritionFormatter
{
    public const DASH = '–';

    public static function label(string $nutrient): string
    {
        return match ($nutrient) {
            'energy_kcal' => __('Energia'),
            'energy_kj' => __('Energia (kJ)'),
            'protein_g' => __('Bielkoviny'),
            'carbohydrate_g' => __('Sacharidy'),
            'fat_g' => __('Tuky'),
            'fiber_g' => __('Vláknina'),
            default => $nutrient,
        };
    }

    public static function unit(string $nutrient): string
    {
        return match ($nutrient) {
            'energy_kcal' => 'kcal',
            'energy_kj' => 'kJ',
            default => 'g',
        };
    }

    /**
     * A value of the given nutrient with its unit, or a dash when unknown.
     */
    public function value(string $nutrient, ?float $value): string
    {
        if ($value === null) {
            return self::DASH;
        }

        $number = match ($nutrient) {
            'energy_kcal', 'energy_kj' => $this->energy($value),
            default => $this->grams($value),
        };

        return $number.' '.self::unit($nutrient);
    }

    /**
     * Energy rounded the way a person would say it: whole numbers below 100, nearest 5 below 1 000, nearest 10 above.
     */
    public function energy(float $value): string
    {
        $step = match (true) {
            $value >= 1000 => 10,
            $value >= 100 => 5,
            default => 1,
        };

        return $this->number(round($value / $step) * $step, 0);
    }

    /**
     * Grams with one decimal below 10 g, whole grams above.
     */
    public function grams(float $value): string
    {
        return $value < 10 ? $this->number($value, 1) : $this->number($value, 0);
    }

    /**
     * Weight as written in the calculation (an amount, a final weight): up to one decimal, trailing zero dropped.
     */
    public function weight(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ' '), '0'), ',');
    }

    private function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', ' ');
    }
}
