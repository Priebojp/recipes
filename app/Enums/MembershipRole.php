<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Vlastník',
            self::Editor => 'Editor',
            self::Member => 'Člen',
        };
    }

    public function canEditContent(): bool
    {
        return $this !== self::Member;
    }
}
