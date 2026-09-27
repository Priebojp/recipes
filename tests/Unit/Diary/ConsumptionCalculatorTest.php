<?php

use App\Enums\NutritionCompleteness;
use App\Models\ConsumptionNutritionSnapshot;
use App\Services\Diary\ConsumptionBasis;
use App\Services\Diary\ConsumptionCalculator;
use App\Services\Diary\ConsumptionPortion;

/**
 * A stored component as the recipe or photo calculator writes it (grams of the whole source).
 *
 * @param  array<string, float|null>|null  $nutrients  per 100 g; null = no food
 */
function storedComponent(string $name, ?float $grams, ?array $nutrients, float $share = 1.0, bool $included = true): array
{
    $all = $nutrients === null ? null : array_merge(['energy_kcal' => null, 'energy_kj' => null, 'protein_g' => null, 'carbohydrate_g' => null, 'fat_g' => null, 'fiber_g' => null], $nutrients);

    return [
        'line_id' => null,
        'name' => $name,
        'amount' => $grams === null ? '' : $grams.' g',
        'grams' => $grams,
        'grams_origin' => 'user_entered',
        'preparation_state' => 'cooked',
        'share' => $share,
        'source' => $nutrients === null ? null : ['record_id' => 1, 'provider' => 'usda_fdc', 'external_id' => '1', 'name' => $name, 'name_sk' => null, 'license' => 'CC0-1.0', 'basis' => '100g', 'preparation_state' => 'cooked'],
        'nutrients_per_100g' => $all,
        'included' => $included && $grams !== null && $nutrients !== null,
        'included_grams' => $included && $grams !== null && $nutrients !== null ? round($grams * $share, 2) : null,
        'unresolved_reason' => $nutrients === null ? 'Bez priradenej potraviny.' : null,
    ];
}

/** Sugar and butter: 1 000 kcal over 4 servings (the test input of scenario 9, not a database claim). */
function syrupBasis(?float $finalWeightG = null, int $servings = 4): ConsumptionBasis
{
    return new ConsumptionBasis(
        meta: ['kind' => 'recipe', 'nutrition_calculation_id' => 1],
        components: [
            storedComponent('cukor', 150, ['energy_kcal' => 400, 'energy_kj' => 1674, 'protein_g' => 0, 'carbohydrate_g' => 100, 'fat_g' => 0, 'fiber_g' => 0]),
            storedComponent('maslo', 50, ['energy_kcal' => 800, 'energy_kj' => 3347, 'protein_g' => 1, 'carbohydrate_g' => 0, 'fat_g' => 80, 'fiber_g' => 0]),
        ],
        totals: ['energy_kcal' => 1000, 'energy_kj' => 4184.5, 'protein_g' => 0.5, 'carbohydrate_g' => 150, 'fat_g' => 40, 'fiber_g' => 0],
        completeness: NutritionCompleteness::Complete,
        missing: [],
        assumptions: [],
        divisor: $servings,
        unitGrams: $finalWeightG === null ? null : $finalWeightG / $servings,
    );
}

it('gives 250 kcal for one serving of a 1 000 kcal recipe on 4 servings and 125 for half of it (scenario 9)', function () {
    $calculator = new ConsumptionCalculator;

    $whole = $calculator->calculate(syrupBasis(), ConsumptionPortion::fraction(1.0));
    $half = $calculator->calculate(syrupBasis(), ConsumptionPortion::fraction(0.5));

    expect($whole->totals['energy_kcal'])->toBe(250.0)
        ->and($whole->totals['fat_g'])->toBe(10.0)
        ->and($whole->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($whole->eatenGrams)->toBe(50.0)
        ->and($whole->assumptions)->toBe([])
        ->and($half->totals['energy_kcal'])->toBe(125.0)
        ->and($half->totals['carbohydrate_g'])->toBe(18.75)
        ->and($half->eatenGrams)->toBe(25.0)
        ->and($half->assumptions)->toContain('Zjedený podiel: 50 % jednej porcie.')
        ->and($half->components[0]['eaten_grams'])->toBe(18.75)
        ->and($half->components[0]['share_eaten'])->toBe(0.5);
});

it('scales by weight against the measured serving, or against the ingredient sum with a visible note', function () {
    $calculator = new ConsumptionCalculator;

    // 800 g finished dish ÷ 4 = 200 g per serving; 100 g eaten = half a serving.
    $measured = $calculator->calculate(syrupBasis(800), ConsumptionPortion::grams(100));
    expect($measured->totals['energy_kcal'])->toBe(125.0)
        ->and($measured->assumptions)->toContain('Zjedených 100 g z porcie 200 g – prepočet podľa hmotnosti.')
        ->and(implode(' ', $measured->assumptions))->not->toContain('súčet započítaných surovín');

    // No final weight: a serving is the 50 g of ingredients and the note says so.
    $derived = $calculator->calculate(syrupBasis(), ConsumptionPortion::grams(25));
    expect($derived->totals['energy_kcal'])->toBe(125.0)
        ->and($derived->assumptions)->toContain('Hmotnosť porcie je súčet započítaných surovín (50 g), nie odvážené hotové jedlo.');

    // Nothing to weigh against: a clear refusal, never a silent guess.
    $manual = new ConsumptionBasis(meta: ['kind' => 'manual'], components: [], totals: ['energy_kcal' => 300, 'energy_kj' => null, 'protein_g' => 10, 'carbohydrate_g' => 30, 'fat_g' => 12, 'fiber_g' => null], completeness: NutritionCompleteness::Complete, missing: [], assumptions: []);
    expect(fn () => $calculator->calculate($manual, ConsumptionPortion::grams(100)))->toThrow(InvalidArgumentException::class);
});

it('corrects per component – the meat eaten, the rice left – and keeps a missing value missing', function () {
    $calculator = new ConsumptionCalculator;
    $basis = new ConsumptionBasis(
        meta: ['kind' => 'analysis'],
        components: [
            storedComponent('ryža varená', 150, ['energy_kcal' => 130, 'protein_g' => 2.7, 'carbohydrate_g' => 28, 'fat_g' => 0.3]),
            storedComponent('kuracie prsia', 120, ['energy_kcal' => 165, 'protein_g' => 31, 'carbohydrate_g' => 0, 'fat_g' => 3.6, 'fiber_g' => 0]),
            storedComponent('omáčka', null, null),
        ],
        totals: ['energy_kcal' => 393, 'energy_kj' => null, 'protein_g' => 41.25, 'carbohydrate_g' => 42, 'fat_g' => 4.77, 'fiber_g' => null],
        completeness: NutritionCompleteness::Partial,
        missing: [['name' => 'omáčka', 'reason' => 'Bez priradenej potraviny.']],
        assumptions: ['„ryža varená“: gramáž 150 g je odhad.'],
    );

    $result = $calculator->calculate($basis, ConsumptionPortion::perComponent([0 => 0.0, 1 => 1.0]));

    expect($result->totals['energy_kcal'])->toBe(198.0)
        ->and($result->totals['protein_g'])->toBe(37.2)
        ->and($result->totals['fiber_g'])->toBe(0.0) // the uneaten rice's unknown fibre does not touch the eaten sum
        ->and($result->totals['energy_kj'])->toBeNull()
        ->and($result->completeness)->toBe(NutritionCompleteness::Partial)
        ->and($result->missing)->toBe([['name' => 'omáčka', 'reason' => 'Bez priradenej potraviny.']])
        ->and($result->assumptions)->toContain('„ryža varená“ nebolo zjedené (0 %).')
        ->and($result->assumptions)->toContain('„ryža varená“: gramáž 150 g je odhad.')
        ->and($result->components[0]['included'])->toBeFalse()
        ->and($result->components[0]['eaten_grams'])->toBe(0.0)
        ->and($result->components[1]['eaten_grams'])->toBe(120.0)
        ->and($result->components[2]['eaten_grams'])->toBeNull()
        ->and($result->eatenGrams)->toBe(120.0);

    $partly = $calculator->calculate($basis, ConsumptionPortion::perComponent([0 => 0.5]));
    expect($partly->totals['energy_kcal'])->toBe(295.5)
        ->and($partly->assumptions)->toContain('„ryža varená“: zjedených 50 % (75 g z 150 g).');
});

it('rebuilds the basis from a stored snapshot shape so a correction never needs the live source', function () {
    $calculator = new ConsumptionCalculator;
    $first = $calculator->calculate(syrupBasis(800), ConsumptionPortion::fraction(1.0));

    $stored = $first->basis->toArray();
    $snapshot = new ConsumptionNutritionSnapshot(['basis' => $stored]);
    $again = $calculator->calculate(ConsumptionBasis::fromSnapshot($snapshot), ConsumptionPortion::fraction(0.25));

    expect($again->totals['energy_kcal'])->toBe(62.5)
        ->and($again->basis->unitGrams)->toBe(200.0)
        ->and($again->basis->divisor)->toBe(4);
});
