<?php

namespace App\Enums;

enum StripeEventState: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => __('prijatá'),
            self::Processed => __('spracovaná'),
            self::Ignored => __('bez účinku'),
            self::Failed => __('zlyhala'),
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Processed => 'green',
            self::Received => 'amber',
            self::Ignored => 'zinc',
            self::Failed => 'red',
        };
    }
}
