<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of administrator actions. Sensitive values are redacted before they are stored.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $reason
 * @property array<string, mixed>|null $changes
 * @property Carbon $created_at
 */
#[Fillable(['actor_id', 'action', 'target_type', 'target_id', 'reason', 'changes', 'created_at'])]
class AdminAudit extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
