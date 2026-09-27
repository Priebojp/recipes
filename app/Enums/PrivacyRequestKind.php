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
            self::Export => __('export údajov'),
            self::Erasure => __('výmaz účtu'),
            self::Rectification => __('oprava údajov'),
            self::Other => __('iná žiadosť'),
        };
    }
}
