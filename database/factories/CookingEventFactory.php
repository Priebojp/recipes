<?php

namespace Database\Factories;

use App\Models\CookingEvent;
use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CookingEvent> */
class CookingEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'recipe_id' => Recipe::factory(),
            'cooked_on' => now()->toDateString(),
            'recipe_title_snapshot' => 'Jedlo',
        ];
    }
}
