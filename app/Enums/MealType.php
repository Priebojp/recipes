<?php

namespace App\Enums;

enum MealType: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Raňajky',
            self::Lunch => 'Obed',
            self::Dinner => 'Večera',
        };
    }

    /**
     * Suggest a meal type from the local hour of day.
     */
    public static function suggestForHour(int $hour): self
    {
        return match (true) {
            $hour < 10 => self::Breakfast,
            $hour < 15 => self::Lunch,
            default => self::Dinner,
        };
    }
}
