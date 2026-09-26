<?php

namespace App\Models;

use App\Services\IngredientAmountParser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $position
 * @property string $name
 * @property string|null $numeric_amount
 * @property string|null $text_amount
 * @property string|null $unit
 * @property string|null $note
 * @property string|null $source_text
 */
#[Fillable(['recipe_id', 'position', 'name', 'numeric_amount', 'text_amount', 'unit', 'note', 'source_text'])]
class IngredientLine extends Model
{
    protected function casts(): array
    {
        return ['numeric_amount' => 'decimal:3'];
    }

    public function hasNumericAmount(): bool
    {
        return $this->numeric_amount !== null;
    }

    /**
     * Amount as written for the base servings: a formatted number or the free text ("podľa chuti").
     */
    public function displayAmount(): string
    {
        if ($this->numeric_amount !== null) {
            return (new IngredientAmountParser)->format((float) $this->numeric_amount);
        }

        return (string) $this->text_amount;
    }
}
