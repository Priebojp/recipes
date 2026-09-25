<?php

namespace App\Models;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $provider_job_id
 * @property string $prompt_version
 * @property array<string, mixed>|null $input
 * @property string|null $prompt
 * @property array<string, mixed>|null $output
 * @property string|null $error
 * @property int|null $result_media_id
 * @property Carbon|null $applied_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'household_id', 'recipe_id', 'kind', 'input_revision_id', 'status', 'request_key', 'provider', 'model',
    'provider_job_id', 'prompt_version', 'input', 'prompt', 'output', 'error', 'result_media_id', 'applied_at',
    'started_at', 'finished_at', 'created_by',
])]
class AiJob extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => AiJobKind::class,
            'status' => AiJobStatus::class,
            'input' => 'array',
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
}
