<?php

namespace App\Enums;

/**
 * Whether every ingredient line of a recipe took part in a nutrition calculation with a known value. A partial
 * result is shown as "Čiastočný súčet" – never as complete energy.
 */
enum NutritionCompleteness: string
{
    case Complete = 'complete';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Complete => __('Kompletný výpočet'),
            self::Partial => __('Čiastočný súčet'),
        };
    }
}
