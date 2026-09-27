<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Received = 'received';
    case Refunded = 'refunded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => __('prijaté'),
            self::Refunded => __('refundované'),
            self::Rejected => __('zamietnuté'),
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Received => 'amber',
            self::Refunded => 'green',
            self::Rejected => 'zinc',
        };
    }
}
