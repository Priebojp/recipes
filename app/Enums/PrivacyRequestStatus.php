<?php

namespace App\Enums;

enum PrivacyRequestStatus: string
{
    case Received = 'received';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'prijatá',
            self::InProgress => 'v riešení',
            self::Completed => 'vybavená',
            self::Rejected => 'zamietnutá',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Received => 'amber',
            self::InProgress => 'blue',
            self::Completed => 'green',
            self::Rejected => 'zinc',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Received, self::InProgress], true);
    }
}
