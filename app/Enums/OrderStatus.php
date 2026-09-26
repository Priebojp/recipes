<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'čaká na úhradu',
            self::Paid => 'zaplatená',
            self::Failed => 'platba zlyhala',
            self::Canceled => 'zrušená',
            self::Expired => 'nedokončená',
            self::Refunded => 'refundovaná',
            self::PartiallyRefunded => 'čiastočne refundovaná',
        };
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Refunded, self::PartiallyRefunded], true);
    }
}
