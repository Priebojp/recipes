<?php

namespace App\Enums;

enum LaunchCheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Warn => 'Upozornenie',
            self::Fail => 'Blokuje',
            self::Skip => 'Neoverené',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'green',
            self::Warn => 'amber',
            self::Fail => 'red',
            self::Skip => 'zinc',
        };
    }

    /** Sort weight: blockers first. */
    public function weight(): int
    {
        return match ($this) {
            self::Fail => 0,
            self::Warn => 1,
            self::Skip => 2,
            self::Ok => 3,
        };
    }
}
