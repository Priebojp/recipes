<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $household_id
 * @property int|null $creator_id
 * @property array<string, mixed> $inputs
 * @property int $config_version
 * @property array<string, mixed> $candidates
 * @property array<string, mixed> $state
 * @property Carbon $expires_at
 */
#[Fillable(['household_id', 'creator_id', 'inputs', 'config_version', 'candidates', 'state', 'expires_at'])]
class SelectionSession extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'candidates' => 'array',
            'state' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return HasMany<SelectionAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(SelectionAction::class, 'session_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
