<?php

namespace Database\Factories;

use App\Enums\PersonKind;
use App\Models\Household;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Person> */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->firstName(),
            'kind' => PersonKind::Member,
        ];
    }

    public function guest(): static
    {
        return $this->state(fn () => ['kind' => PersonKind::Guest]);
    }
}
