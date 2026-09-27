<?php

namespace App\Enums;

/**
 * Where the grams of one recognised component came from. An AI guess stays an estimate until a person confirms
 * it; "measured" means the person says they weighed it – never that the application measured anything.
 */
enum MealGramsOrigin: string
{
    case Estimated = 'estimated';
    case Confirmed = 'confirmed';
    case Measured = 'measured';

    public function label(): string
    {
        return match ($this) {
            self::Estimated => __('odhad'),
            self::Confirmed => __('potvrdené'),
            self::Measured => __('odvážené'),
        };
    }

    /** The calculator's origin: a confirmed or weighed amount counts as entered, an estimate stays an estimate. */
    public function foodGramsOrigin(): FoodGramsOrigin
    {
        return $this === self::Estimated ? FoodGramsOrigin::Estimated : FoodGramsOrigin::UserEntered;
    }
}
