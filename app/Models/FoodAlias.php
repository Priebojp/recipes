<?php

namespace App\Models;

use App\Enums\FoodPreparationState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Slovak or Czech name of a food ("ryža", "hladká múka") pointing at one source record in one preparation state.
 * The same alias may point at several records (raw and cooked rice) – the matcher offers them as distinct choices.
 *
 * @property int $id
 * @property int $food_source_record_id
 * @property string $alias
 * @property string $normalized
 * @property string $locale
 * @property FoodPreparationState $preparation_state
 * @property int|null $curated_by
 * @property Carbon|null $curated_at
 */
#[Fillable(['food_source_record_id', 'alias', 'normalized', 'locale', 'preparation_state', 'curated_by', 'curated_at'])]
class FoodAlias extends Model
{
    public const LOCALES = ['sk', 'cs'];

    protected function casts(): array
    {
        return ['preparation_state' => FoodPreparationState::class, 'curated_at' => 'datetime'];
    }

    /** @return BelongsTo<FoodSourceRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(FoodSourceRecord::class, 'food_source_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curated_by');
    }

    /**
     * Lower-case, single spaces, no punctuation – the same rule the shopping list uses to merge "Cibuľa" and "cibuľa".
     */
    public static function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = (string) preg_replace('/[[:punct:]]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
