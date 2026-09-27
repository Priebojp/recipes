<?php

namespace App\Models;

use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Enums\MealGramsOrigin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One visible component of a photographed meal. The AI proposes label, state and an estimated amount; the server
 * looks the label up in the food dictionary; the person confirms, corrects, removes or marks it unknown.
 * The linked food record is always one the server verified – an ID from the model is never stored.
 *
 * @property int $id
 * @property int $meal_analysis_id
 * @property int $position
 * @property string $label
 * @property list<string>|null $alternatives
 * @property FoodPreparationState|null $preparation_state
 * @property string|null $estimated_grams
 * @property string|null $grams
 * @property MealGramsOrigin|null $grams_origin
 * @property string|null $portion_basis
 * @property string|null $visible_evidence
 * @property list<string>|null $assumptions
 * @property int|null $food_source_record_id
 * @property FoodMappingStatus $mapping_status
 * @property bool $is_unknown
 * @property bool $included
 */
#[Fillable([
    'meal_analysis_id', 'position', 'label', 'alternatives', 'preparation_state', 'estimated_grams', 'grams', 'grams_origin',
    'portion_basis', 'visible_evidence', 'assumptions', 'food_source_record_id', 'mapping_status', 'is_unknown', 'included',
])]
class MealAnalysisItem extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'alternatives' => 'array',
            'preparation_state' => FoodPreparationState::class,
            'estimated_grams' => 'decimal:2',
            'grams' => 'decimal:2',
            'grams_origin' => MealGramsOrigin::class,
            'assumptions' => 'array',
            'mapping_status' => FoodMappingStatus::class,
            'is_unknown' => 'boolean',
            'included' => 'boolean',
        ];
    }

    /** @return BelongsTo<MealAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(MealAnalysis::class, 'meal_analysis_id');
    }

    /** @return BelongsTo<FoodSourceRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(FoodSourceRecord::class, 'food_source_record_id');
    }

    /** The food that counts: none when rejected or marked unknown. */
    public function effectiveRecord(): ?FoodSourceRecord
    {
        if ($this->is_unknown || $this->mapping_status === FoodMappingStatus::Rejected) {
            return null;
        }

        return $this->record;
    }

    public function isEstimate(): bool
    {
        return $this->grams_origin === MealGramsOrigin::Estimated;
    }
}
