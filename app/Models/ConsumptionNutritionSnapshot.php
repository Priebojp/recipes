<?php

namespace App\Models;

use App\Enums\ManualNutritionOrigin;
use App\Enums\NutritionCompleteness;
use App\Enums\PortionMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The numbers of one diary entry as they were computed when it was saved (or corrected): the frozen basis they
 * came from, every component with its grams and source, what is missing and which assumptions were made. A row is
 * never updated – a correction is the next revision – so a later change of the recipe, the food database or the
 * calculation cannot rewrite what a person recorded.
 *
 * @property int $id
 * @property int $meal_consumption_id
 * @property int $revision
 * @property int $calculation_version
 * @property PortionMode $portion_mode
 * @property string|null $portion_fraction
 * @property string|null $grams
 * @property array<int|string, float>|null $component_shares
 * @property array<string, mixed> $basis
 * @property array<string, float|null>|null $totals
 * @property NutritionCompleteness|null $completeness
 * @property list<array<string, mixed>> $components
 * @property list<array{name: string, reason: string}> $missing
 * @property list<string> $assumptions
 * @property ManualNutritionOrigin|null $manual_origin
 * @property Carbon $created_at
 */
#[Fillable([
    'meal_consumption_id', 'revision', 'calculation_version', 'portion_mode', 'portion_fraction', 'grams', 'component_shares',
    'basis', 'totals', 'completeness', 'components', 'missing', 'assumptions', 'manual_origin', 'created_at',
])]
class ConsumptionNutritionSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'calculation_version' => 'integer',
            'portion_mode' => PortionMode::class,
            'portion_fraction' => 'decimal:4',
            'grams' => 'decimal:2',
            'component_shares' => 'array',
            'basis' => 'array',
            'totals' => 'array',
            'completeness' => NutritionCompleteness::class,
            'components' => 'array',
            'missing' => 'array',
            'assumptions' => 'array',
            'manual_origin' => ManualNutritionOrigin::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MealConsumption, $this> */
    public function consumption(): BelongsTo
    {
        return $this->belongsTo(MealConsumption::class, 'meal_consumption_id');
    }

    public function hasNutrition(): bool
    {
        return $this->totals !== null;
    }

    public function isPartial(): bool
    {
        return $this->completeness === NutritionCompleteness::Partial;
    }
}
