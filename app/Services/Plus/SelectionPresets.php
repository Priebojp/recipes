<?php

namespace App\Services\Plus;

use App\Enums\MealType;
use App\Models\Household;
use App\Models\Person;
use App\Models\SelectionPreset;
use App\Models\User;
use App\Services\Selection\SelectionFilters;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Saved diner groups and filters of the generator (Plus). Saving under an existing name replaces that preset.
 */
class SelectionPresets
{
    /** Visible product limit; shown in the UI only once it is reached. */
    public const LIMIT = 30;

    /** @return Collection<int, SelectionPreset> */
    public function forHousehold(Household $household): Collection
    {
        return SelectionPreset::query()->where('household_id', $household->id)->orderBy('name')->get();
    }

    /**
     * @param  array{person_ids: list<int|string>, meal_type?: string|null, filters?: array<string, mixed>}  $inputs
     */
    public function save(Household $household, string $name, array $inputs, ?User $by): SelectionPreset
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Zadaj názov šablóny (najviac 60 znakov).');
        }

        $personIds = array_values(array_map('intval', Person::query()
            ->where('household_id', $household->id)
            ->whereIn('id', array_map('intval', $inputs['person_ids']))
            ->pluck('id')
            ->all()));
        if ($personIds === []) {
            throw new InvalidArgumentException('Šablóna potrebuje aspoň jedného stravníka.');
        }

        $mealType = isset($inputs['meal_type']) && $inputs['meal_type'] !== '' && $inputs['meal_type'] !== 'any'
            ? MealType::from($inputs['meal_type'])
            : null;

        $existing = SelectionPreset::query()->where('household_id', $household->id)->where('name', $name)->first();
        if ($existing === null && SelectionPreset::query()->where('household_id', $household->id)->count() >= self::LIMIT) {
            throw new InvalidArgumentException('Domácnosť môže mať najviac '.self::LIMIT.' šablón. Odstráň niektorú alebo ju prepíš rovnakým názvom.');
        }

        $attributes = [
            'person_ids' => $personIds,
            'meal_type' => $mealType,
            'filters' => SelectionFilters::fromArray($inputs['filters'] ?? [])->toArray(),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return SelectionPreset::create(array_merge($attributes, [
            'household_id' => $household->id,
            'name' => $name,
            'created_by' => $by?->id,
        ]));
    }

    public function delete(SelectionPreset $preset): void
    {
        $preset->delete();
    }
}
