<?php

namespace App\Models;

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Link from one ingredient line of a recipe to a food record with the grams the line stands for and where those
 * grams came from. One mapping per line; the matcher's proposal and a person's confirmation share the row.
 *
 * @property int $id
 * @property int $ingredient_line_id
 * @property int|null $food_source_record_id
 * @property FoodPreparationState|null $preparation_state
 * @property string|null $grams
 * @property FoodGramsOrigin|null $grams_origin
 * @property int|null $conversion_id
 * @property FoodMappingStatus $status
 * @property string|null $unresolved_reason
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 */
#[Fillable([
    'ingredient_line_id', 'food_source_record_id', 'preparation_state', 'grams', 'grams_origin', 'conversion_id',
    'status', 'unresolved_reason', 'confirmed_by', 'confirmed_at',
])]
class IngredientFoodMapping extends Model
{
    protected function casts(): array
    {
        return [
            'preparation_state' => FoodPreparationState::class,
            'grams' => 'decimal:2',
            'grams_origin' => FoodGramsOrigin::class,
            'status' => FoodMappingStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IngredientLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(IngredientLine::class, 'ingredient_line_id');
    }

    /** @return BelongsTo<FoodSourceRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(FoodSourceRecord::class, 'food_source_record_id');
    }

    /** @return BelongsTo<FoodUnitConversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(FoodUnitConversion::class, 'conversion_id');
    }

    /**
     * Usable by a nutrition calculation: a confirmed food with grams.
     */
    public function isComplete(): bool
    {
        return $this->status === FoodMappingStatus::Confirmed && $this->food_source_record_id !== null && $this->grams !== null;
    }
}
