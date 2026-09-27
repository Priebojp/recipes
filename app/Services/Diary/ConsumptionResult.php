<?php

namespace App\Services\Diary;

use App\Enums\NutritionCompleteness;

/**
 * The eaten values of one diary entry with everything they came from. Values are unrounded (NutritionFormatter
 * rounds for display); a missing value stays null and makes the result a partial sum.
 *
 * @phpstan-type Values array{energy_kcal: float|null, energy_kj: float|null, protein_g: float|null, carbohydrate_g: float|null, fat_g: float|null, fiber_g: float|null}
 */
final readonly class ConsumptionResult
{
    /**
     * @param  Values  $totals
     * @param  list<array<string, mixed>>  $components
     * @param  list<array{name: string, reason: string}>  $missing
     * @param  list<string>  $assumptions
     */
    public function __construct(
        public array $totals,
        public NutritionCompleteness $completeness,
        public array $components,
        public array $missing,
        public array $assumptions,
        public ConsumptionBasis $basis,
        public ConsumptionPortion $portion,
        public float $eatenGrams,
    ) {}

    public function isPartial(): bool
    {
        return $this->completeness === NutritionCompleteness::Partial;
    }
}
