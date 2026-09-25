<?php

namespace App\Models;

use App\Enums\Preference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $person_id
 * @property int $recipe_id
 * @property Preference $preference
 */
#[Fillable(['person_id', 'recipe_id', 'preference'])]
class PersonRecipePreference extends Model
{
    protected function casts(): array
    {
        return ['preference' => Preference::class];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
