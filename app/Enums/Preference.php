<?php

namespace App\Enums;

enum Preference: string
{
    case Favorite = 'favorite';
    case Eats = 'eats';
    case Dislikes = 'dislikes';

    public function label(): string
    {
        return match ($this) {
            self::Favorite => __('Obľúbené'),
            self::Eats => __('Zje'),
            self::Dislikes => __('Nemá rád'),
        };
    }
}
