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
            self::Favorite => 'Obľúbené',
            self::Eats => 'Zje',
            self::Dislikes => 'Nemá rád',
        };
    }
}
