<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Shopping list of one household week, generated from the planned meals (Plus feature).
 *
 * @property int $id
 * @property int $household_id
 * @property CarbonImmutable $week_start_date
 * @property Carbon|null $generated_at
 * @property int $plan_count
 * @property int|null $generated_by
 */
#[Fillable(['household_id', 'week_start_date', 'generated_at', 'plan_count', 'generated_by'])]
class ShoppingList extends Model
{
    protected function casts(): array
    {
        return [
            'week_start_date' => DateOnly::class,
            'generated_at' => 'datetime',
            'plan_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<ShoppingListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class)->orderBy('position');
    }
}
