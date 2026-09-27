<?php

namespace App\Services\Nutrition;

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodPreparationState;

/**
 * One ingredient line as the calculator sees it: the grams it stands for, where those grams came from, the
 * food's values per 100 g and the share a person decided to count (oil left in the pan, drained liquid).
 * A component without a food or without grams is carried through so the result can say what is missing.
 *
 * @phpstan-type Nutrients array{energy_kcal: float|null, energy_kj: float|null, protein_g: float|null, carbohydrate_g: float|null, fat_g: float|null, fiber_g: float|null}
 * @phpstan-type Source array{record_id: int, provider: string, external_id: string, name: string, name_sk: string|null, license: string, basis: string, preparation_state: string}
 */
final readonly class NutritionComponent
{
    /**
     * @param  Nutrients|null  $nutrients  per 100 g of the food; null when no food is mapped
     * @param  Source|null  $source
     * @param  float  $share  0..1 – the part of the line that is eaten (1 = all of it)
     */
    public function __construct(
        public string $name,
        public string $amount,
        public ?float $grams,
        public ?FoodGramsOrigin $gramsOrigin,
        public ?array $nutrients,
        public ?array $source,
        public ?FoodPreparationState $preparationState = null,
        public float $share = 1.0,
        public ?string $unresolvedReason = null,
        public ?int $lineId = null,
    ) {}

    public function hasFood(): bool
    {
        return $this->nutrients !== null;
    }

    public function hasGrams(): bool
    {
        return $this->grams !== null && $this->grams > 0;
    }
}
