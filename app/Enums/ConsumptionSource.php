<?php

namespace App\Enums;

/**
 * Where a diary entry came from: a recipe with a stored calculation (stage 10), a confirmed photo analysis
 * (stage 11) or a meal typed by hand (a restaurant, a label). None of them creates a cooking event.
 */
enum ConsumptionSource: string
{
    case Recipe = 'recipe';
    case Analysis = 'analysis';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => __('recept'),
            self::Analysis => __('fotka jedla'),
            self::Manual => __('ručný záznam'),
        };
    }
}
