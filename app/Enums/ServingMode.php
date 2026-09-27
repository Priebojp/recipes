<?php

namespace App\Enums;

enum ServingMode: string
{
    case Auto = 'auto';
    case Plate = 'plate';
    case Bowl = 'bowl';
    case Pot = 'pot';
    case Casserole = 'casserole';
    case BakingDish = 'baking_dish';

    public function label(): string
    {
        return match ($this) {
            self::Auto => __('Automaticky'),
            self::Plate => __('Tanier'),
            self::Bowl => __('Miska'),
            self::Pot => __('Hrniec'),
            self::Casserole => __('Kastról'),
            self::BakingDish => __('Pekáč'),
        };
    }

    /**
     * Human readable serving description used in prompts and confirmation text.
     */
    public function promptText(): string
    {
        return match ($this) {
            self::Auto => 'bežná domáca porcia',
            self::Plate => 'na tanieri',
            self::Bowl => 'v miske',
            self::Pot => 'v hrnci',
            self::Casserole => 'v kastróle',
            self::BakingDish => 'v pekáči',
        };
    }
}
