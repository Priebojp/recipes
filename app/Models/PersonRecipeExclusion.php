<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $person_id
 * @property int $recipe_id
 * @property string|null $reason
 * @property int|null $created_by
 */
#[Fillable(['person_id', 'recipe_id', 'reason', 'created_by'])]
class PersonRecipeExclusion extends Model
{
    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
