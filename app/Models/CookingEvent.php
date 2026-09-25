<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Database\Factories\CookingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int|null $recipe_id
 * @property int|null $meal_plan_id
 * @property int|null $active_plan_key
 * @property string|null $idempotency_key
 * @property CarbonImmutable $cooked_on
 * @property int|null $servings
 * @property string|null $note
 * @property string $recipe_title_snapshot
 * @property int|null $recipe_revision_id
 * @property Carbon|null $voided_at
 * @property int|null $created_by
 */
#[Fillable([
    'household_id', 'recipe_id', 'meal_plan_id', 'active_plan_key', 'idempotency_key', 'cooked_on', 'servings',
    'note', 'recipe_title_snapshot', 'recipe_revision_id', 'voided_at', 'created_by',
])]
class CookingEvent extends Model
{
    /** @use HasFactory<CookingEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cooked_on' => DateOnly::class,
            'voided_at' => 'datetime',
            'servings' => 'integer',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsToMany<Person, $this> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'cooking_event_people');
    }

    /** @param  Builder<CookingEvent>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
