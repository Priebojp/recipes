<?php

namespace Database\Factories;

use App\Enums\FoodPreparationState;
use App\Models\FoodSourceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FoodSourceRecord> */
class FoodSourceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => FoodSourceRecord::PROVIDER_USDA,
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'license' => 'CC0-1.0',
            'name' => ucfirst(fake()->word()).', raw',
            'preparation_state' => FoodPreparationState::Raw,
            'basis' => FoodSourceRecord::BASIS_100G,
            'energy_kcal' => 100,
            'energy_kj' => 418,
            'protein_g' => 5,
            'carbohydrate_g' => 10,
            'carbohydrate_method' => 'by_difference',
            'fat_g' => 4,
            'fiber_g' => 1,
            'source_snapshot' => ['fake' => true],
            'fetched_at' => now(),
            'is_curated' => true,
        ];
    }

    public function cooked(): static
    {
        return $this->state(fn () => ['preparation_state' => FoodPreparationState::Cooked]);
    }

    /** No provider values yet – the seeder's state before the first sync. */
    public function unsynced(): static
    {
        return $this->state(fn () => [
            'energy_kcal' => null, 'energy_kj' => null, 'protein_g' => null, 'carbohydrate_g' => null, 'carbohydrate_method' => null, 'fat_g' => null, 'fiber_g' => null,
            'source_snapshot' => null, 'fetched_at' => null,
        ]);
    }
}
