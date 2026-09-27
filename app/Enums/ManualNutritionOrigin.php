<?php

namespace App\Enums;

/**
 * Where hand-typed values of a manual entry came from. Shown with the numbers so a guess never looks like a
 * measurement; a manual entry without values has no origin.
 */
enum ManualNutritionOrigin: string
{
    case Label = 'label';
    case Estimate = 'estimate';

    public function label(): string
    {
        return match ($this) {
            self::Label => __('etiketa / jedálny lístok'),
            self::Estimate => __('vlastný odhad'),
        };
    }
}
