<?php

namespace Database\Factories;

use App\Enums\NutritionCompleteness;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Services\Nutrition\NutritionCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NutritionCalculation> */
class NutritionCalculationFactory extends Factory
{
    public function definition(): array
    {
        $totals = ['energy_kcal' => 1000.0, 'energy_kj' => 4184.0, 'protein_g' => 40.0, 'carbohydrate_g' => 120.0, 'fat_g' => 35.0, 'fiber_g' => 8.0];

        return [
            'recipe_id' => Recipe::factory(),
            'recipe_revision_id' => fn (array $attributes) => RecipeRevision::create([
                'recipe_id' => $attributes['recipe_id'],
                'snapshot' => ['ingredients' => []],
                'source' => 'manual',
                'created_at' => now(),
            ])->id,
            'calculation_version' => NutritionCalculator::VERSION,
            'servings' => 4,
            'final_weight_g' => null,
            'totals' => $totals,
            'per_serving' => array_map(fn (float $value) => $value / 4, $totals),
            'per_100g' => null,
            'completeness' => NutritionCompleteness::Complete,
            'components' => [],
            'missing' => [],
            'assumptions' => [],
        ];
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'completeness' => NutritionCompleteness::Partial,
            'missing' => [['name' => 'xylitolový sirup', 'reason' => 'V slovníku nie je zhoda.']],
        ]);
    }

    public function stale(): static
    {
        return $this->state(fn () => ['stale_at' => now()]);
    }
}
