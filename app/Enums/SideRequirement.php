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
            self::Unknown => 'Neznáme',
            self::Complete => 'Kompletné jedlo',
            self::NeedsSide => 'Vyžaduje samostatnú prílohu',
        };
    }
}
