<?php

namespace App\Services\Food;

use App\Enums\FoodPreparationState;

/**
 * A provider's food as the application stores it: values per basis (null = the provider has no value), the
 * carbohydrate methodology, portions the provider publishes and the original payload as a snapshot.
 *
 * @phpstan-type Nutrients array{energy_kcal: float|null, energy_kj: float|null, protein_g: float|null, carbohydrate_g: float|null, fat_g: float|null, fiber_g: float|null}
 * @phpstan-type Portion array{unit: string, grams: float, description: string}
 */
final readonly class FoodRecordData
{
    /**
     * @param  Nutrients  $nutrients
     * @param  list<Portion>  $portions  units already canonical (FoodUnit), grams of exactly one unit
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public string $license,
        public string $basis,
        public FoodPreparationState $preparationState,
        public array $nutrients,
        public ?string $carbohydrateMethod,
        public array $portions,
        public array $snapshot,
    ) {}
}
