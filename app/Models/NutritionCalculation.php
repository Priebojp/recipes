<?php

namespace App\Models;

use App\Enums\NutritionCompleteness;
use Database\Factories\NutritionCalculationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of the nutrition calculation for one recipe revision. Everything the numbers came from is stored with
 * them (components with source and grams, assumptions, what is missing), so the result stays explainable after
 * the recipe, the mapping or the food database changes. Old runs are kept; a newer revision that changes the
 * ingredients or servings marks the run stale instead of rewriting it.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $recipe_revision_id
 * @property int $calculation_version
 * @property int|null $servings
 * @property string|null $final_weight_g
 * @property array<string, float|null> $totals
 * @property array<string, float|null>|null $per_serving
 * @property array<string, float|null>|null $per_100g
 * @property NutritionCompleteness $completeness
 * @property list<array<string, mixed>> $components
 * @property list<array{name: string, reason: string}> $missing
 * @property list<string> $assumptions
 * @property Carbon|null $stale_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'recipe_id', 'recipe_revision_id', 'calculation_version', 'servings', 'final_weight_g', 'totals', 'per_serving', 'per_100g',
    'completeness', 'components', 'missing', 'assumptions', 'stale_at', 'created_by',
])]
class NutritionCalculation extends Model
{
    /** @use HasFactory<NutritionCalculationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'calculation_version' => 'integer',
            'servings' => 'integer',
            'final_weight_g' => 'decimal:2',
            'totals' => 'array',
            'per_serving' => 'array',
            'per_100g' => 'array',
            'completeness' => NutritionCompleteness::class,
            'components' => 'array',
            'missing' => 'array',
            'assumptions' => 'array',
            'stale_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<RecipeRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(RecipeRevision::class, 'recipe_revision_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isStale(): bool
    {
        return $this->stale_at !== null;
    }

    public function isPartial(): bool
    {
        return $this->completeness === NutritionCompleteness::Partial;
    }

    public function hasPer100g(): bool
    {
        return $this->per_100g !== null;
    }
}
