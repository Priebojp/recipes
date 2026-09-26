<?php

namespace App\Models;

use App\Enums\PrivacyRequestKind;
use App\Enums\PrivacyRequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A data-subject request with its statutory deadline and the evidence of completion.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $household_id
 * @property string|null $subject_email
 * @property PrivacyRequestKind $kind
 * @property PrivacyRequestStatus $status
 * @property string|null $message
 * @property CarbonInterface $received_at
 * @property CarbonInterface $deadline_at
 * @property CarbonInterface|null $completed_at
 * @property string|null $completion_evidence
 * @property int|null $handled_by
 */
#[Fillable(['user_id', 'household_id', 'subject_email', 'kind', 'status', 'message', 'received_at', 'deadline_at', 'completed_at', 'completion_evidence', 'handled_by'])]
class PrivacyRequest extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => PrivacyRequestKind::class,
            'status' => PrivacyRequestStatus::class,
            'received_at' => 'datetime',
            'deadline_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->deadline_at->isPast();
    }
}
