<?php

namespace App\Models;

use App\Enums\MealType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $recipe_id
 * @property MealType $meal_type
 */
#[Fillable(['recipe_id', 'meal_type'])]
class RecipeMealType extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['meal_type' => MealType::class];
    }
}
