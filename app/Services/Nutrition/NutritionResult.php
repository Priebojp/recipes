<?php

namespace App\Services\Nutrition;

use App\Enums\NutritionCompleteness;

/**
 * What one calculation produced: totals for the whole recipe, per serving and (only with a final weight) per 100 g,
 * plus the record of every component, what is missing and which assumptions were made. Values are unrounded;
 * NutritionFormatter rounds for display.
 *
 * @phpstan-type Values array{energy_kcal: float|null, energy_kj: float|null, protein_g: float|null, carbohydrate_g: float|null, fat_g: float|null, fiber_g: float|null}
 */
final readonly class NutritionResult
{
    /**
     * @param  Values  $totals
     * @param  Values|null  $perServing
     * @param  Values|null  $per100g
     * @param  list<array<string, mixed>>  $components
     * @param  list<array{name: string, reason: string}>  $missing
     * @param  list<string>  $assumptions
     */
    public function __construct(
        public array $totals,
        public ?array $perServing,
        public ?array $per100g,
        public NutritionCompleteness $completeness,
        public array $components,
        public array $missing,
        public array $assumptions,
        public ?int $servings,
        public ?float $finalWeightG,
        public float $includedGrams,
    ) {}

    public function isPartial(): bool
    {
        return $this->completeness === NutritionCompleteness::Partial;
    }
}
