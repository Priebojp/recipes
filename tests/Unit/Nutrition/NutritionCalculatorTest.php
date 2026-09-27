<?php

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodPreparationState;
use App\Enums\NutritionCompleteness;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Nutrition\NutritionComponent;
use App\Services\Nutrition\NutritionFormatter;

/**
 * @param  array<string, float|null>|null  $nutrients  per 100 g; null = no food mapped
 */
function nutritionComponent(string $name, ?float $grams, ?array $nutrients, float $share = 1.0, ?FoodGramsOrigin $origin = FoodGramsOrigin::UnitConversion, ?string $reason = null, FoodPreparationState $state = FoodPreparationState::Raw): NutritionComponent
{
    $all = $nutrients === null ? null : array_merge(['energy_kcal' => null, 'energy_kj' => null, 'protein_g' => null, 'carbohydrate_g' => null, 'fat_g' => null, 'fiber_g' => null], $nutrients);

    return new NutritionComponent(
        name: $name,
        amount: $grams === null ? 'podľa chuti' : $grams.' g',
        grams: $grams,
        gramsOrigin: $grams === null ? null : $origin,
        nutrients: $all,
        source: $nutrients === null ? null : ['record_id' => 1, 'provider' => 'usda_fdc', 'external_id' => '1', 'name' => $name, 'name_sk' => null, 'license' => 'CC0-1.0', 'basis' => '100g', 'preparation_state' => $state->value],
        preparationState: $nutrients === null ? null : $state,
        share: $share,
        unresolvedReason: $reason,
    );
}

it('sums grams / 100 × value per component, divides by servings and gives per 100 g only with a final weight (scenarios 9, 10)', function () {
    $calculator = new NutritionCalculator;
    $components = [
        nutritionComponent('cukor', 150, ['energy_kcal' => 400, 'energy_kj' => 1674, 'protein_g' => 0, 'carbohydrate_g' => 100, 'fat_g' => 0, 'fiber_g' => 0]),
        nutritionComponent('maslo', 50, ['energy_kcal' => 800, 'energy_kj' => 3347, 'protein_g' => 1, 'carbohydrate_g' => 0, 'fat_g' => 80, 'fiber_g' => 0]),
    ];

    $noWeight = $calculator->calculate($components, 4);
    expect($noWeight->totals['energy_kcal'])->toBe(1000.0)
        ->and($noWeight->totals['fat_g'])->toBe(40.0)
        ->and($noWeight->totals['energy_kj'])->toBe(4184.5)
        ->and($noWeight->perServing['energy_kcal'])->toBe(250.0)
        ->and($noWeight->per100g)->toBeNull()
        ->and($noWeight->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($noWeight->includedGrams)->toBe(200.0);

    $light = $calculator->calculate($components, 4, 500);
    $heavy = $calculator->calculate($components, 4, 1000);
    expect($light->totals['energy_kcal'])->toBe(1000.0)
        ->and($light->per100g['energy_kcal'])->toBe(200.0)
        ->and($heavy->totals['energy_kcal'])->toBe(1000.0)
        ->and($heavy->per100g['energy_kcal'])->toBe(100.0)
        ->and($heavy->perServing['energy_kcal'])->toBe(250.0);

    expect($calculator->calculate($components, null)->perServing)->toBeNull();
});

it('keeps a missing value missing – a partial sum, never zero – and blocks nothing for salt without an amount (scenario 8)', function () {
    $calculator = new NutritionCalculator;

    $result = $calculator->calculate([
        nutritionComponent('múka', 100, ['energy_kcal' => 364, 'protein_g' => 10, 'carbohydrate_g' => 76, 'fat_g' => 1, 'fiber_g' => 2.7]),
        nutritionComponent('domáci sirup', 100, ['energy_kcal' => 300, 'protein_g' => 0, 'carbohydrate_g' => 75, 'fat_g' => null, 'fiber_g' => null]),
        nutritionComponent('olej', null, ['energy_kcal' => 884, 'protein_g' => 0, 'carbohydrate_g' => 0, 'fat_g' => 100, 'fiber_g' => 0], reason: 'Pre potravinu „olej“ nie je potvrdený prevod jednotky „PL“ na gramy.'),
        nutritionComponent('soľ', null, ['energy_kcal' => 0, 'protein_g' => 0, 'carbohydrate_g' => 0, 'fat_g' => 0, 'fiber_g' => 0]),
        nutritionComponent('xylitolový sirup', 20, null, reason: 'V slovníku nie je zhoda pre „xylitolový sirup“.'),
    ], 2);

    expect($result->completeness)->toBe(NutritionCompleteness::Partial)
        ->and($result->totals['energy_kcal'])->toBe(664.0)
        ->and($result->totals['fat_g'])->toBe(1.0)
        ->and($result->totals['fiber_g'])->toBeNull()
        ->and($result->totals['energy_kj'])->toBeNull()
        ->and(collect($result->missing)->pluck('name')->all())->toBe(['olej', 'xylitolový sirup', 'domáci sirup'])
        ->and($result->missing[0]['reason'])->toContain('prevod jednotky')
        ->and($result->missing[2]['reason'])->toContain('Tuky')
        ->and($result->assumptions)->toHaveCount(1)
        ->and($result->assumptions[0])->toContain('soľ')->toContain('zanedbateľné')
        ->and(collect($result->components)->firstWhere('name', 'soľ')['negligible'])->toBeTrue()
        ->and(collect($result->components)->firstWhere('name', 'olej')['included'])->toBeFalse();

    $unknownEnergy = $calculator->calculate([nutritionComponent('bylinky', null, ['energy_kcal' => null])], 1);
    expect($unknownEnergy->missing)->toHaveCount(1)->and($unknownEnergy->assumptions)->toBe([]);
});

it('uses the record of the state the person confirmed – 100 g raw rice is not 100 g cooked rice (scenario 7)', function () {
    $calculator = new NutritionCalculator;
    $raw = $calculator->calculate([nutritionComponent('ryža', 100, ['energy_kcal' => 365, 'protein_g' => 7.1, 'carbohydrate_g' => 80, 'fat_g' => 0.7], state: FoodPreparationState::Raw)], 1);
    $cooked = $calculator->calculate([nutritionComponent('ryža', 100, ['energy_kcal' => 130, 'protein_g' => 2.7, 'carbohydrate_g' => 28, 'fat_g' => 0.3], state: FoodPreparationState::Cooked)], 1);

    expect($raw->totals['energy_kcal'])->toBe(365.0)
        ->and($cooked->totals['energy_kcal'])->toBe(130.0)
        ->and($raw->components[0]['source']['preparation_state'])->toBe('raw')
        ->and($cooked->components[0]['preparation_state'])->toBe('cooked');
});

it('counts a share explicitly and records estimates and exclusions as assumptions instead of silent numbers', function () {
    $calculator = new NutritionCalculator;
    $oil = ['energy_kcal' => 884, 'protein_g' => 0, 'carbohydrate_g' => 0, 'fat_g' => 100, 'fiber_g' => 0];

    $half = $calculator->calculate([nutritionComponent('olej na vyprážanie', 40, $oil, share: 0.5)], 1);
    $none = $calculator->calculate([nutritionComponent('olej na vyprážanie', 40, $oil, share: 0.0)], 1);
    $estimate = $calculator->calculate([nutritionComponent('olej', 27, $oil, origin: FoodGramsOrigin::Estimated)], 1);

    expect($half->totals['energy_kcal'])->toBe(176.8)
        ->and($half->components[0]['included_grams'])->toBe(20.0)
        ->and($half->assumptions[0])->toContain('50 %')->toContain('20 g z 40 g')
        ->and($half->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($none->totals['energy_kcal'])->toBeNull()
        ->and($none->assumptions[0])->toContain('nie je započítané')
        ->and($none->missing)->toBe([])
        ->and($estimate->totals['energy_kcal'])->toBe(238.68)
        ->and($estimate->assumptions[0])->toContain('odhad')
        ->and($estimate->components[0]['grams_origin'])->toBe('estimated');
});

it('formats energy and grams the way a person would say them and never shows an unknown value as zero', function () {
    $formatter = new NutritionFormatter;

    expect($formatter->energy(647.238))->toBe('645')
        ->and($formatter->energy(1234.0))->toBe('1 230')
        ->and($formatter->energy(47.6))->toBe('48')
        ->and($formatter->value('energy_kcal', 250.0))->toBe('250 kcal')
        ->and($formatter->value('protein_g', 3.14159))->toBe('3,1 g')
        ->and($formatter->value('carbohydrate_g', 123.4))->toBe('123 g')
        ->and($formatter->value('fat_g', null))->toBe(NutritionFormatter::DASH)
        ->and($formatter->weight(1200.0))->toBe('1 200')
        ->and($formatter->weight(13.5))->toBe('13,5');
});
