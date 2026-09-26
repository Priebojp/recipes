<?php

namespace App\Models;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int $recipe_id
 * @property AiJobKind $kind
 * @property int|null $input_revision_id
 * @property AiJobStatus $status
 * @property string $request_key
 * @property string|null $provider
 * @property string|null $model
 * @property array<string, mixed>|null $profile
 * @property string|null $provider_job_id
 * @property string $prompt_version
 * @property array<string, mixed>|null $input
 * @property string|null $prompt
 * @property array<string, mixed>|null $output
 * @property string|null $error
 * @property int|null $input_tokens
 * @property int|null $cached_input_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $image_output_tokens
 * @property int|null $estimated_cost_micro_usd
 * @property int|null $cost_rate_id
 * @property int|null $duration_ms
 * @property int|null $result_media_id
 * @property Carbon|null $applied_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'household_id', 'recipe_id', 'kind', 'input_revision_id', 'status', 'request_key', 'provider', 'model', 'profile',
    'provider_job_id', 'prompt_version', 'input', 'prompt', 'output', 'error', 'input_tokens', 'cached_input_tokens',
    'output_tokens', 'reasoning_tokens', 'image_output_tokens', 'estimated_cost_micro_usd', 'cost_rate_id', 'duration_ms',
    'result_media_id', 'applied_at', 'started_at', 'finished_at', 'created_by',
])]
class AiJob extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => AiJobKind::class,
            'status' => AiJobStatus::class,
            'input' => 'array',
            'profile' => 'array',
            'output' => 'array',
            'applied_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<AiCostRate, $this> */
    public function costRate(): BelongsTo
    {
        return $this->belongsTo(AiCostRate::class, 'cost_rate_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The use held for this job (absent when the ledger was not enforced at creation). */
    /** @return HasOne<UsageReservation, $this> */
    public function usageReservation(): HasOne
    {
        return $this->hasOne(UsageReservation::class);
    }

    /**
     * Short human description of the profile the job ran with (e.g. "medium · 1:1" or "effort: low").
     */
    public function profileLabel(): string
    {
        $profile = $this->profile ?? [];
        $parts = [];
        if (isset($profile['reasoning_effort']) && $profile['reasoning_effort'] !== 'default') {
            $parts[] = 'effort: '.$profile['reasoning_effort'];
        }
        if (isset($profile['quality'])) {
            $parts[] = $profile['quality'];
        }
        if (isset($profile['size'])) {
            $parts[] = $profile['size'];
        }

        return $parts === [] ? '–' : implode(' · ', $parts);
    }
}
