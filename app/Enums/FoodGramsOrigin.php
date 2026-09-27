<?php

namespace App\Enums;

/**
 * Where the gram amount of a mapped ingredient came from. Shown next to every value so an estimate is never
 * mistaken for a weighed input.
 */
enum FoodGramsOrigin: string
{
    /** Recipe amount × a confirmed conversion of that food (or a mass unit written in the recipe). */
    case UnitConversion = 'unit_conversion';

    /** A person typed the grams. */
    case UserEntered = 'user_entered';

    /** Marked as an estimate by a person; the calculation reports it as an assumption. */
    case Estimated = 'estimated';

    public function label(): string
    {
        return match ($this) {
            self::UnitConversion => 'z receptu',
            self::UserEntered => 'zadané',
            self::Estimated => 'odhad',
        };
    }
}
