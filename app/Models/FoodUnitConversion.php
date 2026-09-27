<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Grams of one unit of one specific food: a piece, a tablespoon, a cup, or one millilitre (density).
 * There is no global assumption; without a row here a recipe amount in that unit stays unresolved.
 *
 * @property int $id
 * @property int $food_source_record_id
 * @property string $unit
 * @property string $grams
 * @property string $source
 * @property string|null $note
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 */
#[Fillable(['food_source_record_id', 'unit', 'grams', 'source', 'note', 'confirmed_by', 'confirmed_at'])]
class FoodUnitConversion extends Model
{
    public const SOURCE_USDA_PORTION = 'usda_portion';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LABEL = 'label';

    public const SOURCES = [self::SOURCE_USDA_PORTION, self::SOURCE_MANUAL, self::SOURCE_LABEL];

    protected function casts(): array
    {
        return ['grams' => 'decimal:2', 'confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<FoodSourceRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(FoodSourceRecord::class, 'food_source_record_id');
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_USDA_PORTION => __('porcia USDA'),
            self::SOURCE_LABEL => __('etiketa'),
            default => __('ručne'),
        };
    }
}
