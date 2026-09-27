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
            self::Owner => __('Vlastník'),
            self::Editor => __('Editor'),
            self::Member => __('Člen'),
        };
    }

    public function canEditContent(): bool
    {
        return $this !== self::Member;
    }
}
