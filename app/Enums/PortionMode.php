<?php

namespace App\Enums;

/**
 * How much of the unit (a serving of a recipe, the whole plate of a photo) was eaten. A fraction scales every
 * component alike; grams scale by weight; per component lets a person say "the meat yes, the rice no".
 */
enum PortionMode: string
{
    case Fraction = 'fraction';
    case Grams = 'grams';
    case PerComponent = 'per_component';

    public function label(): string
    {
        return match ($this) {
            self::Fraction => __('podiel porcie'),
            self::Grams => __('odvážené gramy'),
            self::PerComponent => __('po zložkách'),
        };
    }
}
