<?php

namespace Database\Factories;

use App\Enums\MealAnalysisAiStatus;
use App\Enums\MealAnalysisStatus;
use App\Models\Household;
use App\Models\MealAnalysis;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealAnalysis> */
class MealAnalysisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'status' => MealAnalysisStatus::Uploaded,
            'note' => null,
            'clarification_count' => 0,
            'expires_at' => now()->addDays((int) config('recipes.meal_analysis.draft_ttl_days', 7)),
        ];
    }

    /** A delivered proposal waiting for the person's check. */
    public function needsReview(): static
    {
        return $this->state(fn () => [
            'status' => MealAnalysisStatus::NeedsReview,
            'ai_status' => MealAnalysisAiStatus::Recognized,
            'dish_name' => 'Bryndzové halušky',
            'questions' => [],
            'limitations' => ['Gramáž je odhad z jednej fotografie.'],
            'ai_result' => ['status' => 'recognized', 'dish_name' => 'Bryndzové halušky', 'components' => [], 'questions' => [], 'limitations' => []],
        ]);
    }

    public function confirmed(): static
    {
        return $this->needsReview()->state(fn () => [
            'status' => MealAnalysisStatus::Confirmed,
            'confirmed_at' => now(),
            'expires_at' => null,
        ]);
    }

    public function unusable(): static
    {
        return $this->state(fn () => [
            'status' => MealAnalysisStatus::Unusable,
            'ai_status' => MealAnalysisAiStatus::NotFood,
            'limitations' => ['Na fotografii nie je jedlo.'],
        ]);
    }
}
