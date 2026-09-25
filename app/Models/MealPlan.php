<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\MealType;
use App\Enums\PlanMode;
use App\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int $recipe_id
 * @property PlanMode $mode
 * @property CarbonImmutable|null $scheduled_date
 * @property CarbonImmutable|null $week_start_date
 * @property MealType|null $meal_type
 * @property int|null $servings
 * @property PlanStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['household_id', 'recipe_id', 'mode', 'scheduled_date', 'week_start_date', 'meal_type', 'servings', 'status', 'created_by'])]
class MealPlan extends Model
{
    /** @use HasFactory<MealPlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mode' => PlanMode::class,
            'status' => PlanStatus::class,
            'meal_type' => MealType::class,
            'scheduled_date' => DateOnly::class,
            'week_start_date' => DateOnly::class,
            'servings' => 'integer',
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

    /** @return BelongsToMany<Person, $this> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'meal_plan_people');
    }

    /** @return HasOne<CookingEvent, $this> */
    public function activeCookingEvent(): HasOne
    {
        return $this->hasOne(CookingEvent::class)->whereNull('voided_at');
    }

    /** @param  Builder<MealPlan>  $query */
    public function scopePlanned(Builder $query): void
    {
        $query->where('status', PlanStatus::Planned);
    }

    public function isPlanned(): bool
    {
        return $this->status === PlanStatus::Planned;
    }

    /**
     * Human readable label of the plan's term.
     */
    public function termLabel(): string
    {
        return match ($this->mode) {
            PlanMode::Date => $this->scheduled_date?->translatedFormat('D j. n.') ?? '',
            PlanMode::Week => 'Týždeň od '.$this->week_start_date?->format('j. n.'),
            PlanMode::Someday => 'Niekedy',
        };
    }
}
