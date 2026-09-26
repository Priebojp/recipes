<?php

namespace App\Models;

use App\Enums\UsageLedgerReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only movement of a grant's available quantity. Never updated or deleted; corrections are new rows.
 *
 * @property int $id
 * @property int $household_id
 * @property int $usage_grant_id
 * @property int|null $usage_reservation_id
 * @property int $movement
 * @property UsageLedgerReason $reason
 * @property string $source_key
 * @property int|null $actor_id
 * @property string|null $note
 * @property CarbonInterface $created_at
 */
#[Fillable(['household_id', 'usage_grant_id', 'usage_reservation_id', 'movement', 'reason', 'source_key', 'actor_id', 'note', 'created_at'])]
class UsageLedgerEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'movement' => 'integer',
            'reason' => UsageLedgerReason::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<UsageGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(UsageGrant::class, 'usage_grant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
