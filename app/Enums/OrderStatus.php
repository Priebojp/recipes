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
            self::Pending => __('čaká na úhradu'),
            self::Paid => __('zaplatená'),
            self::Failed => __('platba zlyhala'),
            self::Canceled => __('zrušená'),
            self::Expired => __('nedokončená'),
            self::Refunded => __('refundovaná'),
            self::PartiallyRefunded => __('čiastočne refundovaná'),
        };
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Refunded, self::PartiallyRefunded], true);
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Pending => 'amber',
            self::Refunded, self::PartiallyRefunded => 'blue',
            self::Failed => 'red',
            self::Canceled, self::Expired => 'zinc',
        };
    }
}
