<?php

namespace App\Enums;

enum PlanInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'mesačne',
            self::Year => 'ročne',
        };
    }
}
