<?php

namespace App\Models;

use App\Enums\MealType;
use App\Services\Selection\SelectionFilters;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved group of diners, meal type and filters for the generator ("Rodina", "Návšteva") – Plus feature.
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property list<int> $person_ids
 * @property MealType|null $meal_type
 * @property array<string, mixed> $filters
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'name', 'person_ids', 'meal_type', 'filters', 'created_by'])]
class SelectionPreset extends Model
{
    protected function casts(): array
    {
        return [
            'person_ids' => 'array',
            'meal_type' => MealType::class,
            'filters' => 'array',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function selectionFilters(): SelectionFilters
    {
        return SelectionFilters::fromArray($this->filters ?? []);
    }

    /**
     * Diners of the preset that still exist and are active (archived profiles are dropped silently).
     *
     * @return list<int>
     */
    public function activePersonIds(): array
    {
        $active = Person::query()->where('household_id', $this->household_id)->active()->whereIn('id', $this->person_ids)->pluck('id')->all();

        return array_values(array_map('intval', array_intersect($this->person_ids, $active)));
    }
}
