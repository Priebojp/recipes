<?php

namespace App\Models;

use App\Enums\UsageReservationState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A use held for one AI job. Created together with the job, settled (consumed or released) exactly once.
 *
 * @property int $id
 * @property int $household_id
 * @property int $usage_grant_id
 * @property int|null $ai_job_id
 * @property int $quantity
 * @property UsageReservationState $state
 * @property CarbonInterface $reserved_at
 * @property CarbonInterface|null $settled_at
 */
#[Fillable(['household_id', 'usage_grant_id', 'ai_job_id', 'quantity', 'state', 'reserved_at', 'settled_at'])]
class UsageReservation extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'state' => UsageReservationState::class,
            'reserved_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<UsageGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(UsageGrant::class, 'usage_grant_id');
    }

    /** @return BelongsTo<AiJob, $this> */
    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class);
    }
}
