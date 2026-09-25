<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Household> */
class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' – domácnosť',
            'timezone' => 'Europe/Bratislava',
            'owner_user_id' => User::factory(),
        ];
    }
}
