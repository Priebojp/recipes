<?php

namespace App\Models;

use App\Enums\ConsumptionSource;
use App\Enums\PortionMode;
use Carbon\CarbonImmutable;
use Database\Factories\MealConsumptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One event of eating in the private diary (v2.1 stage 12). It belongs to the signed-in person who wrote it –
 * not to the household, not to a diner profile – and records what was actually eaten, not what was served.
 * The numbers live in immutable snapshots: the latest revision is what the diary shows, older ones are history.
 *
 * @property int $id
 * @property int $user_id
 * @property int $household_id
 * @property Carbon $eaten_at
 * @property string $timezone
 * @property CarbonImmutable $eaten_on
 * @property ConsumptionSource $source
 * @property int|null $recipe_id
 * @property int|null $recipe_revision_id
 * @property int|null $nutrition_calculation_id
 * @property int|null $meal_analysis_id
 * @property string $title_snapshot
 * @property PortionMode $portion_mode
 * @property string|null $portion_fraction
 * @property string|null $grams
 * @property string|null $note
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id', 'household_id', 'eaten_at', 'timezone', 'eaten_on', 'source', 'recipe_id', 'recipe_revision_id',
    'nutrition_calculation_id', 'meal_analysis_id', 'title_snapshot', 'portion_mode', 'portion_fraction', 'grams', 'note',
])]
class MealConsumption extends Model
{
    /** @use HasFactory<MealConsumptionFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'eaten_at' => 'datetime',
            'eaten_on' => 'immutable_date',
            'source' => ConsumptionSource::class,
            'portion_mode' => PortionMode::class,
            'portion_fraction' => 'decimal:4',
            'grams' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<NutritionCalculation, $this> */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(NutritionCalculation::class, 'nutrition_calculation_id');
    }

    /** @return BelongsTo<MealAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(MealAnalysis::class, 'meal_analysis_id');
    }

    /** Every revision, oldest first. */
    /** @return HasMany<ConsumptionNutritionSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ConsumptionNutritionSnapshot::class)->orderBy('revision');
    }

    /** The revision the diary shows. */
    /** @return HasOne<ConsumptionNutritionSnapshot, $this> */
    public function snapshot(): HasOne
    {
        return $this->hasOne(ConsumptionNutritionSnapshot::class)->ofMany('revision', 'max');
    }

    /** The instant in the zone it was written in. */
    public function eatenAtLocal(): CarbonImmutable
    {
        return $this->eaten_at->toImmutable()->timezone($this->timezone);
    }
}
