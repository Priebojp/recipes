<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Processed = 'processed';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';
    case Reviewed = 'reviewed';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'požiadaná',
            self::Processed => 'spracovaná',
            self::Failed => 'zlyhala',
            self::NeedsReview => 'čaká na posúdenie',
            self::Reviewed => 'posúdená',
            self::Disputed => 'spor',
        };
    }

    /** Money left the account (or is held by the card network): counts as refunded for order status and reports. */
    public function countsAsRefunded(): bool
    {
        return in_array($this, [self::Processed, self::NeedsReview, self::Reviewed], true);
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Processed, self::Reviewed => 'green',
            self::Requested => 'amber',
            self::Failed, self::Disputed => 'red',
            self::NeedsReview => 'orange',
        };
    }
}
