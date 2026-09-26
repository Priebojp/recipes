<?php

namespace App\Enums;

enum CatalogState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'návrh',
            self::Active => 'aktívna',
            self::Retired => 'stiahnutá',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Active => 'green',
            self::Retired => 'zinc',
        };
    }
}
