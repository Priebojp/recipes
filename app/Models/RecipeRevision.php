<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int|null $author_id
 * @property array<string, mixed> $snapshot
 * @property string $source
 * @property int|null $previous_revision_id
 * @property Carbon $created_at
 */
#[Fillable(['recipe_id', 'author_id', 'snapshot', 'source', 'previous_revision_id', 'created_at'])]
class RecipeRevision extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
