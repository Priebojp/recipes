<?php

namespace App\Models;

use App\Services\IngredientAmountParser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One merged line of a shopping list: the sum of numeric amounts with the same name and unit, plus the free-text
 * amounts that cannot be added ("podľa chuti"). Manual items are the shopper's own and survive regeneration.
 *
 * @property int $id
 * @property int $shopping_list_id
 * @property int $position
 * @property string $merge_key
 * @property string $name
 * @property string|null $unit
 * @property string|null $numeric_amount
 * @property list<string>|null $text_amounts
 * @property list<array{recipe_id: int, title: string, plan_id: int, scaled: bool}>|null $sources
 * @property bool $manual
 * @property Carbon|null $checked_at
 */
#[Fillable(['shopping_list_id', 'position', 'merge_key', 'name', 'unit', 'numeric_amount', 'text_amounts', 'sources', 'manual', 'checked_at'])]
class ShoppingListItem extends Model
{
    protected function casts(): array
    {
        return [
            'numeric_amount' => 'decimal:3',
            'text_amounts' => 'array',
            'sources' => 'array',
            'manual' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function isChecked(): bool
    {
        return $this->checked_at !== null;
    }

    /** "500 g", "2 ks" or "" when only free-text amounts exist. */
    public function amountLabel(): string
    {
        if ($this->numeric_amount === null) {
            return '';
        }

        return trim((new IngredientAmountParser)->format((float) $this->numeric_amount).' '.($this->unit ?? ''));
    }

    /**
     * Normalised name + unit used to merge lines and to keep the checked state across regenerations.
     */
    public static function mergeKey(string $name, ?string $unit): string
    {
        $normalise = fn (?string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));

        return $normalise($name).'|'.$normalise($unit);
    }
}
