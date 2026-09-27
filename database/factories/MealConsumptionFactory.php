<?php

namespace Database\Factories;

use App\Enums\ConsumptionSource;
use App\Enums\PortionMode;
use App\Models\Household;
use App\Models\MealConsumption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealConsumption> */
class MealConsumptionFactory extends Factory
{
    public function definition(): array
    {
        $eatenAt = now()->subHours(2);

        return [
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'eaten_at' => $eatenAt,
            'timezone' => 'Europe/Bratislava',
            'eaten_on' => $eatenAt->copy()->timezone('Europe/Bratislava')->toDateString(),
            'source' => ConsumptionSource::Manual,
            'title_snapshot' => 'Obed v reštaurácii',
            'portion_mode' => PortionMode::Fraction,
            'portion_fraction' => 1,
            'grams' => null,
            'note' => null,
        ];
    }
}
