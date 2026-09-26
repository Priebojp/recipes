<?php

namespace App\Enums;

enum PrivacyRequestKind: string
{
    case Export = 'export';
    case Erasure = 'erasure';
    case Rectification = 'rectification';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'export údajov',
            self::Erasure => 'výmaz účtu',
            self::Rectification => 'oprava údajov',
            self::Other => 'iná žiadosť',
        };
    }
}
