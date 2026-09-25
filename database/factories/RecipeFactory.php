<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recipe> */
class RecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => ucfirst(fake()->word().' '.fake()->word()),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
