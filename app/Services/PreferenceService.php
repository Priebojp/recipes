<?php

namespace App\Services;

use App\Enums\Preference;
use App\Models\Person;
use App\Models\PersonRecipeExclusion;
use App\Models\PersonRecipePreference;
use App\Models\Recipe;
use App\Models\User;
use InvalidArgumentException;

class PreferenceService
{
    /**
     * Set or clear (null) a person's preference for a recipe.
     */
    public function set(Person $person, Recipe $recipe, ?Preference $preference): void
    {
        $this->assertSameHousehold($person, $recipe);

        if ($preference === null) {
            PersonRecipePreference::query()->where('person_id', $person->id)->where('recipe_id', $recipe->id)->delete();

            return;
        }

        PersonRecipePreference::query()->updateOrCreate(
            ['person_id' => $person->id, 'recipe_id' => $recipe->id],
            ['preference' => $preference],
        );
    }

    public function get(Person $person, Recipe $recipe): ?Preference
    {
        return PersonRecipePreference::query()
            ->where('person_id', $person->id)
            ->where('recipe_id', $recipe->id)
            ->first()?->preference;
    }

    /**
     * Toggle "favorite" (the heart). Favorite -> unrated, anything else -> favorite.
     */
    public function toggleFavorite(Person $person, Recipe $recipe): ?Preference
    {
        $new = $this->get($person, $recipe) === Preference::Favorite ? null : Preference::Favorite;
        $this->set($person, $recipe, $new);

        return $new;
    }

    public function exclude(Person $person, Recipe $recipe, ?string $reason, ?User $by): void
    {
        $this->assertSameHousehold($person, $recipe);

        PersonRecipeExclusion::query()->updateOrCreate(
            ['person_id' => $person->id, 'recipe_id' => $recipe->id],
            ['reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : null, 'created_by' => $by?->id],
        );
    }

    public function removeExclusion(Person $person, Recipe $recipe): void
    {
        PersonRecipeExclusion::query()->where('person_id', $person->id)->where('recipe_id', $recipe->id)->delete();
    }

    private function assertSameHousehold(Person $person, Recipe $recipe): void
    {
        if ($person->household_id !== $recipe->household_id) {
            throw new InvalidArgumentException('Person and recipe belong to different households.');
        }
    }
}
