<?php

namespace App\Services\Diary;

use App\Enums\NutritionCompleteness;
use App\Enums\PortionMode;
use App\Models\FoodSourceRecord;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Nutrition\NutritionFormatter;
use InvalidArgumentException;

/**
 * Pure arithmetic of "how much of it did I eat" (chapter 7 of the v2.1 addendum). One unit of the basis
 * (a serving, a plate) is divided into its stored components; every component is scaled by the same fraction,
 * by weight, or by its own share when the rice stayed on the plate and the meat did not. Values come from the
 * frozen basis – never from AI, never from the live database – so the same call gives the same answer after Plus
 * ends, after the recipe is edited and after a food sync. A missing value stays missing.
 */
class ConsumptionCalculator
{
    /** Bump whenever the rules below change so stored snapshots can be told apart. */
    public const VERSION = 1;

    public function calculate(ConsumptionBasis $basis, ConsumptionPortion $portion): ConsumptionResult
    {
        if ($basis->hasIncludedComponents()) {
            return $this->fromComponents($basis, $portion);
        }
        if ($basis->totals !== null) {
            return $this->fromTotals($basis, $portion);
        }

        throw new InvalidArgumentException(__('Zdroj nemá žiadne hodnoty, z ktorých by sa dal podiel vypočítať.'));
    }

    /**
     * Grams of one unit: what a person weighed when known, otherwise the sum of the included ingredient grams
     * (raw weights, which the assumptions say so).
     *
     * @return array{grams: float|null, origin: string}
     */
    public function unitGrams(ConsumptionBasis $basis): array
    {
        if ($basis->unitGrams !== null && $basis->unitGrams > 0) {
            return ['grams' => $basis->unitGrams, 'origin' => 'measured'];
        }

        $sum = 0.0;
        foreach ($basis->components as $component) {
            if (($component['included'] ?? false) && ($component['included_grams'] ?? null) !== null) {
                $sum += (float) $component['included_grams'];
            }
        }
        if ($sum > 0) {
            return ['grams' => round($sum / $basis->divisor, 2), 'origin' => 'ingredients'];
        }

        return ['grams' => null, 'origin' => 'none'];
    }

    private function fromComponents(ConsumptionBasis $basis, ConsumptionPortion $portion): ConsumptionResult
    {
        $unit = $this->unitGrams($basis);
        $factor = $this->uniformFactor($portion, $unit['grams']);

        $sums = array_fill_keys(FoodSourceRecord::NUTRIENTS, null);
        $gaps = array_fill_keys(FoodSourceRecord::NUTRIENTS, []);
        $components = [];
        $assumptions = $basis->assumptions;
        $eatenGrams = 0.0;

        foreach ($basis->components as $index => $component) {
            $included = ($component['included'] ?? false) && ($component['included_grams'] ?? null) !== null;
            $unitGrams = $included ? (float) $component['included_grams'] / $basis->divisor : null;
            $share = $portion->mode === PortionMode::PerComponent ? ($portion->componentShares[$index] ?? 1.0) : $factor;
            $eaten = $included ? $unitGrams * $share : null;

            $components[] = [
                'index' => $index,
                'line_id' => $component['line_id'] ?? null,
                'name' => (string) ($component['name'] ?? ''),
                'unit_grams' => $unitGrams === null ? null : round($unitGrams, 2),
                'share_eaten' => $included ? round($share, 4) : null,
                'eaten_grams' => $eaten === null ? null : round($eaten, 2),
                'grams_origin' => $component['grams_origin'] ?? null,
                'preparation_state' => $component['preparation_state'] ?? null,
                'source' => $component['source'] ?? null,
                'nutrients_per_100g' => $component['nutrients_per_100g'] ?? null,
                'included' => $included && $share > 0.0,
                'unresolved_reason' => $component['unresolved_reason'] ?? null,
            ];

            if (! $included) {
                continue;
            }
            if ($share <= 0.0) {
                $assumptions[] = __('„:name“ nebolo zjedené (0 %).', ['name' => $component['name'] ?? '']);

                continue;
            }
            if ($portion->mode === PortionMode::PerComponent && $share < 1.0) {
                $assumptions[] = __('„:name“: zjedených :percent % (:eaten g z :grams g).', [
                    'name' => $component['name'] ?? '',
                    'percent' => (int) round($share * 100),
                    'eaten' => $this->grams($eaten),
                    'grams' => $this->grams($unitGrams),
                ]);
            }

            $eatenGrams += $eaten;
            foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
                $per100 = $component['nutrients_per_100g'][$nutrient] ?? null;
                if ($per100 === null) {
                    $gaps[$nutrient][] = (string) ($component['name'] ?? '');

                    continue;
                }
                $sums[$nutrient] = ($sums[$nutrient] ?? 0.0) + $eaten / 100 * (float) $per100;
            }
        }

        $missing = $basis->missing;
        $completeness = $basis->completeness;
        foreach (NutritionCalculator::CORE_NUTRIENTS as $nutrient) {
            foreach ($gaps[$nutrient] as $name) {
                $missing[] = ['name' => $name, 'reason' => __('Zdroj nemá hodnotu „:nutrient“.', ['nutrient' => NutritionFormatter::label($nutrient)])];
                $completeness = NutritionCompleteness::Partial;
            }
        }
        foreach (NutritionCalculator::OPTIONAL_NUTRIENTS as $nutrient) {
            if ($gaps[$nutrient] !== []) {
                $sums[$nutrient] = null;
            }
        }
        if ($eatenGrams <= 0.0) {
            // Nothing eaten: every value is a known zero, not an unknown.
            $sums = array_map(fn (?float $value) => $value ?? 0.0, $sums);
        }

        array_push($assumptions, ...$this->portionAssumptions($portion, $unit));

        return new ConsumptionResult(
            totals: array_map(fn (?float $value) => $value === null ? null : round($value, 3), $sums),
            completeness: $completeness,
            components: $components,
            missing: $missing,
            assumptions: array_values(array_unique($assumptions)),
            basis: $basis,
            portion: $portion,
            eatenGrams: round($eatenGrams, 2),
        );
    }

    /**
     * A basis without components (a manual entry, a run stored without them) can only be scaled as a whole.
     */
    private function fromTotals(ConsumptionBasis $basis, ConsumptionPortion $portion): ConsumptionResult
    {
        if ($portion->mode === PortionMode::PerComponent) {
            throw new InvalidArgumentException(__('Tento záznam nemá zložky, opraviť sa dá iba podiel alebo gramy.'));
        }

        $unit = $this->unitGrams($basis);
        $factor = $this->uniformFactor($portion, $unit['grams']);

        $totals = [];
        foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
            $value = $basis->totals[$nutrient] ?? null;
            $totals[$nutrient] = $value === null ? null : round((float) $value / $basis->divisor * $factor, 3);
        }

        $assumptions = $basis->assumptions;
        array_push($assumptions, ...$this->portionAssumptions($portion, $unit));

        return new ConsumptionResult(
            totals: $totals,
            completeness: $basis->completeness,
            components: [],
            missing: $basis->missing,
            assumptions: array_values(array_unique($assumptions)),
            basis: $basis,
            portion: $portion,
            eatenGrams: $unit['grams'] === null ? 0.0 : round($unit['grams'] * $factor, 2),
        );
    }

    /**
     * The factor every component is scaled by in fraction and grams mode (per-component mode uses its own shares).
     *
     * @throws InvalidArgumentException when grams are asked for and the unit has no weight
     */
    private function uniformFactor(ConsumptionPortion $portion, ?float $unitGrams): float
    {
        return match ($portion->mode) {
            PortionMode::Fraction => max(0.0, (float) $portion->fraction),
            PortionMode::Grams => $unitGrams === null || $unitGrams <= 0
                ? throw new InvalidArgumentException(__('Zdroj nemá hmotnosť, gramy sa nedajú prepočítať – zadaj podiel porcie.'))
                : max(0.0, (float) $portion->grams) / $unitGrams,
            PortionMode::PerComponent => 1.0,
        };
    }

    /**
     * @param  array{grams: float|null, origin: string}  $unit
     * @return list<string>
     */
    private function portionAssumptions(ConsumptionPortion $portion, array $unit): array
    {
        $lines = [];
        if ($portion->mode === PortionMode::Fraction && ! $portion->isWhole()) {
            $lines[] = __('Zjedený podiel: :percent % jednej porcie.', ['percent' => (int) round((float) $portion->fraction * 100)]);
        }
        if ($portion->mode === PortionMode::Grams) {
            $lines[] = __('Zjedených :eaten g z porcie :unit g – prepočet podľa hmotnosti.', ['eaten' => $this->grams((float) $portion->grams), 'unit' => $this->grams((float) $unit['grams'])]);
            if ($unit['origin'] === 'ingredients') {
                $lines[] = __('Hmotnosť porcie je súčet započítaných surovín (:unit g), nie odvážené hotové jedlo.', ['unit' => $this->grams((float) $unit['grams'])]);
            }
        }

        return $lines;
    }

    private function grams(?float $grams): string
    {
        return rtrim(rtrim(number_format((float) $grams, 1, ',', ''), '0'), ',');
    }
}
