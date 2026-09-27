<?php

namespace App\Services\Nutrition;

use App\Enums\FoodGramsOrigin;
use App\Enums\NutritionCompleteness;
use App\Models\FoodSourceRecord;

/**
 * Pure arithmetic over mapped ingredient lines (chapter 4 of the v2.1 addendum). For every included component and
 * nutrient: grams × share / 100 × value per 100 g; the recipe is the sum of the included components. Energy is
 * taken from the database (never 4/4/9), kJ is kept apart from kcal, a missing value stays missing – it is never
 * replaced by zero – and any gap in the core nutrients makes the result a partial sum. No database access here.
 */
class NutritionCalculator
{
    /** Bump whenever the formula or the rules below change so stored runs can be told apart. */
    public const VERSION = 1;

    /** Nutrients a person is promised; a gap in any of them makes the result partial. */
    public const CORE_NUTRIENTS = ['energy_kcal', 'protein_g', 'carbohydrate_g', 'fat_g'];

    /** Shown only when every included component has a value; otherwise reported as unknown. */
    public const OPTIONAL_NUTRIENTS = ['energy_kj', 'fiber_g'];

    /**
     * A food with at most this much energy per 100 g (salt, water, most spices) may be "podľa chuti" without an
     * amount: it is left out with a visible note instead of blocking the result. Oil or sugar without an amount
     * stays missing.
     */
    public const NEGLIGIBLE_KCAL_PER_100G = 5.0;

    /**
     * @param  list<NutritionComponent>  $components
     * @param  int|null  $servings  the recipe's base servings; per-serving values need it
     * @param  float|null  $finalWeightG  edible weight of the finished dish as a person measured it; per 100 g needs it
     */
    public function calculate(array $components, ?int $servings, ?float $finalWeightG = null): NutritionResult
    {
        $sums = array_fill_keys(FoodSourceRecord::NUTRIENTS, null);
        $gaps = array_fill_keys(FoodSourceRecord::NUTRIENTS, []);
        $snapshot = [];
        $missing = [];
        $assumptions = [];
        $includedGrams = 0.0;

        foreach ($components as $component) {
            $share = max(0.0, min(1.0, $component->share));
            $entry = $this->snapshotEntry($component, $share);

            if (! $component->hasFood()) {
                $missing[] = ['name' => $component->name, 'reason' => $component->unresolvedReason ?? __('Bez priradenej potraviny.')];
                $snapshot[] = $entry;

                continue;
            }

            if ($share <= 0.0) {
                $assumptions[] = __('„:name“ nie je započítané (vedomá voľba).', ['name' => $component->name]);
                $snapshot[] = $entry;

                continue;
            }

            if (! $component->hasGrams()) {
                if ($this->isNegligible($component)) {
                    $assumptions[] = __('„:name“ je bez množstva (:amount) – energeticky zanedbateľné, nezapočítané.', ['name' => $component->name, 'amount' => $component->amount !== '' ? $component->amount : __('podľa chuti')]);
                    $entry['negligible'] = true;
                } else {
                    $missing[] = ['name' => $component->name, 'reason' => $component->unresolvedReason ?? __('Chýba gramáž.')];
                }
                $snapshot[] = $entry;

                continue;
            }

            $grams = (float) $component->grams * $share;
            $includedGrams += $grams;
            $entry['included'] = true;
            $entry['included_grams'] = round($grams, 2);

            foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
                $per100 = $component->nutrients[$nutrient] ?? null;
                if ($per100 === null) {
                    $gaps[$nutrient][] = $component->name;

                    continue;
                }
                $sums[$nutrient] = ($sums[$nutrient] ?? 0.0) + $grams / 100 * $per100;
            }

            if ($share < 1.0) {
                $assumptions[] = __('„:name“ je započítané na :percent % (:included g z :grams g).', [
                    'name' => $component->name,
                    'percent' => (int) round($share * 100),
                    'included' => $this->formatGrams($grams),
                    'grams' => $this->formatGrams((float) $component->grams),
                ]);
            }
            if ($component->gramsOrigin === FoodGramsOrigin::Estimated) {
                $assumptions[] = __('„:name“: gramáž :grams g je odhad.', ['name' => $component->name, 'grams' => $this->formatGrams((float) $component->grams)]);
            }

            $snapshot[] = $entry;
        }

        $completeness = $missing === [] ? NutritionCompleteness::Complete : NutritionCompleteness::Partial;
        foreach (self::CORE_NUTRIENTS as $nutrient) {
            foreach ($gaps[$nutrient] as $name) {
                $missing[] = ['name' => $name, 'reason' => __('Zdroj nemá hodnotu „:nutrient“.', ['nutrient' => NutritionFormatter::label($nutrient)])];
                $completeness = NutritionCompleteness::Partial;
            }
        }
        foreach (self::OPTIONAL_NUTRIENTS as $nutrient) {
            if ($gaps[$nutrient] !== []) {
                $sums[$nutrient] = null;
            }
        }

        $totals = array_map(fn (?float $value) => $value === null ? null : round($value, 3), $sums);

        return new NutritionResult(
            totals: $totals,
            perServing: $servings !== null && $servings > 0 ? $this->divide($totals, $servings) : null,
            per100g: $finalWeightG !== null && $finalWeightG > 0 ? $this->divide($totals, $finalWeightG / 100) : null,
            completeness: $completeness,
            components: $snapshot,
            missing: $missing,
            assumptions: $assumptions,
            servings: $servings,
            finalWeightG: $finalWeightG,
            includedGrams: round($includedGrams, 2),
        );
    }

    /**
     * Salt-like foods: energy known and negligible per 100 g. A food with unknown energy is never negligible.
     */
    public function isNegligible(NutritionComponent $component): bool
    {
        $kcal = $component->nutrients['energy_kcal'] ?? null;

        return $kcal !== null && $kcal <= self::NEGLIGIBLE_KCAL_PER_100G;
    }

    /**
     * @param  array<string, float|null>  $values
     * @return array<string, float|null>
     */
    private function divide(array $values, float $divisor): array
    {
        return array_map(fn (?float $value) => $value === null ? null : round($value / $divisor, 3), $values);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotEntry(NutritionComponent $component, float $share): array
    {
        return [
            'line_id' => $component->lineId,
            'name' => $component->name,
            'amount' => $component->amount,
            'grams' => $component->grams === null ? null : round($component->grams, 2),
            'grams_origin' => $component->gramsOrigin?->value,
            'preparation_state' => $component->preparationState?->value,
            'share' => $share,
            'source' => $component->source,
            'nutrients_per_100g' => $component->nutrients,
            'included' => false,
            'included_grams' => null,
            'unresolved_reason' => $component->unresolvedReason,
        ];
    }

    private function formatGrams(float $grams): string
    {
        return rtrim(rtrim(number_format($grams, 1, ',', ''), '0'), ',');
    }
}
