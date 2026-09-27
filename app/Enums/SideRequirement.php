<?php

namespace App\Enums;

enum SideRequirement: string
{
    case Unknown = 'unknown';
    case Complete = 'complete';
    case NeedsSide = 'needs_side';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('Neznáme'),
            self::Complete => __('Kompletné jedlo'),
            self::NeedsSide => __('Vyžaduje samostatnú prílohu'),
        };
    }
}
