<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Processed = 'processed';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';
    case Disputed = 'disputed';
}
