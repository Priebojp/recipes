<?php

namespace Database\Factories;

use App\Enums\PlanMode;
use App\Enums\PlanStatus;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlan> */
class MealPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'recipe_id' => Recipe::factory(),
            'mode' => PlanMode::Date,
            'scheduled_date' => now()->toDateString(),
            'status' => PlanStatus::Planned,
        ];
    }
}
