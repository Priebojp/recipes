<?php

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Enums\MembershipRole;
use App\Enums\NutritionCompleteness;
use App\Livewire\NutritionPanel;
use App\Models\AiJob;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\HouseholdMembership;
use App\Models\IngredientLine;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Models\UsageLedgerEntry;
use App\Models\UsageReservation;
use App\Models\User;
use App\Services\ExportService;
use App\Services\Nutrition\RecipeNutrition;
use App\Services\RecipeService;
use App\Support\CurrentHousehold;
use Livewire\Livewire;

/**
 * A food in the dictionary under one Slovak alias, with the values per 100 g the test needs.
 *
 * @param  array<string, float|null>  $nutrients
 */
function food(string $alias, array $nutrients, FoodPreparationState $state = FoodPreparationState::Raw, ?string $nameSk = null): FoodSourceRecord
{
    $record = FoodSourceRecord::factory()->create(array_merge(
        ['energy_kcal' => 0, 'energy_kj' => 0, 'protein_g' => 0, 'carbohydrate_g' => 0, 'fat_g' => 0, 'fiber_g' => 0],
        $nutrients,
        ['preparation_state' => $state, 'name_sk' => $nameSk ?? ucfirst($alias), 'name' => ucfirst($alias).', '.$state->value],
    ));
    FoodAlias::create(['food_source_record_id' => $record->id, 'alias' => $alias, 'normalized' => FoodAlias::normalize($alias), 'locale' => 'sk', 'preparation_state' => $state]);

    return $record;
}

function line(Recipe $recipe, string $name, ?string $amount, ?string $unit = null, ?string $text = null): IngredientLine
{
    return $recipe->ingredients()->create([
        'position' => (int) $recipe->ingredients()->max('position') + 1,
        'name' => $name, 'numeric_amount' => $amount, 'text_amount' => $text, 'unit' => $unit,
    ]);
}

/**
 * Rice with chicken: two mass lines, oil in spoons without a conversion, salt to taste.
 *
 * @return array{recipe: Recipe, rice: IngredientLine, chicken: IngredientLine, oil: IngredientLine, salt: IngredientLine}
 */
function riceRecipe(int $householdId): array
{
    food('ryža', ['energy_kcal' => 365, 'energy_kj' => 1527, 'protein_g' => 7.1, 'carbohydrate_g' => 80, 'fat_g' => 0.7, 'fiber_g' => 1.3]);
    food('kuracie prsia', ['energy_kcal' => 120, 'energy_kj' => 502, 'protein_g' => 22.5, 'carbohydrate_g' => 0, 'fat_g' => 2.6]);
    food('olej', ['energy_kcal' => 884, 'energy_kj' => 3699, 'fat_g' => 100]);
    food('soľ', ['energy_kcal' => 0, 'energy_kj' => 0]);

    $recipe = Recipe::factory()->create(['household_id' => $householdId, 'title' => 'Ryža s kuracím', 'base_servings' => 4]);

    return [
        'recipe' => $recipe,
        'rice' => line($recipe, 'Ryža', '200', 'g'),
        'chicken' => line($recipe, 'kuracie prsia', '300', 'g'),
        'oil' => line($recipe, 'olej', '1', 'PL'),
        'salt' => line($recipe, 'soľ', null, null, 'podľa chuti'),
    ];
}

it('proposes foods, asks for the grams of oil without a conversion, then stores a calculation with every source and no AI use (scenarios 15, 16)', function () {
    ['household' => $household, 'user' => $user] = household();
    $r = riceRecipe($household->id);

    $this->get(route('recipes.show', $r['recipe']))->assertOk()->assertSee('Vypočítať výživové hodnoty');

    $panel = Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])
        ->call('start')
        ->assertSet('reviewing', true)
        ->assertSee('Ryža')
        ->call('compute')
        ->assertSet('reviewing', true);

    expect($panel->get('error'))->toContain('olej')
        ->and(NutritionCalculation::query()->count())->toBe(0)
        ->and($r['salt']->fresh()->foodMapping->status)->toBe(FoodMappingStatus::Unresolved);

    $panel->set('gramsInput.'.$r['oil']->id, '13,5')
        ->set('originInput.'.$r['oil']->id, 'estimated')
        ->call('saveGrams', $r['oil']->id)
        ->assertHasNoErrors()
        ->call('compute')
        ->assertHasNoErrors()
        ->assertSet('reviewing', false)
        ->assertSet('error', '')
        ->assertSee('Kompletný výpočet');

    $calculation = NutritionCalculation::query()->sole();
    $recipe = $r['recipe']->fresh();
    expect($calculation->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($calculation->totals['energy_kcal'])->toEqual(1209.34)
        ->and($calculation->totals['protein_g'])->toEqual(81.7)
        ->and($calculation->per_serving['energy_kcal'])->toEqual(302.335)
        ->and($calculation->per_100g)->toBeNull()
        ->and($calculation->servings)->toBe(4)
        ->and($calculation->created_by)->toBe($user->id)
        ->and($calculation->recipe_revision_id)->toBe($recipe->active_revision_id)
        ->and($recipe->active_revision_id)->not->toBeNull()
        ->and($calculation->missing)->toBe([])
        ->and($calculation->assumptions)->toHaveCount(2)
        ->and($calculation->assumptions[0])->toContain('olej')->toContain('odhad')
        ->and($calculation->assumptions[1])->toContain('soľ')->toContain('zanedbateľné');

    $components = collect($calculation->components)->keyBy('name');
    expect($components['Ryža']['grams'])->toEqual(200.0)
        ->and($components['Ryža']['grams_origin'])->toBe('unit_conversion')
        ->and($components['Ryža']['source']['provider'])->toBe('usda_fdc')
        ->and($components['Ryža']['source']['external_id'])->not->toBeEmpty()
        ->and($components['Ryža']['source']['license'])->toBe('CC0-1.0')
        ->and($components['Ryža']['nutrients_per_100g']['energy_kcal'])->toEqual(365.0)
        ->and($components['olej']['grams'])->toEqual(13.5)
        ->and($components['olej']['grams_origin'])->toBe('estimated')
        ->and($components['soľ']['included'])->toBeFalse();

    expect($r['rice']->fresh()->foodMapping->status)->toBe(FoodMappingStatus::Confirmed)
        ->and($r['rice']->fresh()->foodMapping->confirmed_by)->toBe($user->id)
        ->and(AiJob::query()->count())->toBe(0)
        ->and(UsageReservation::query()->count())->toBe(0)
        ->and(UsageLedgerEntry::query()->count())->toBe(0);
});

it('gives 250 kcal per serving from 1 000 kcal on 4 servings and per 100 g only with a final weight that changes concentration, not the recipe (scenarios 9, 10)', function () {
    ['household' => $household, 'user' => $user] = household();
    food('cukor', ['energy_kcal' => 400, 'carbohydrate_g' => 100]);
    $recipe = Recipe::factory()->create(['household_id' => $household->id, 'base_servings' => 4]);
    line($recipe, 'cukor', '250', 'g');

    $panel = Livewire::test(NutritionPanel::class, ['recipeId' => $recipe->id])->call('start')->call('compute')->assertHasNoErrors();
    $first = NutritionCalculation::query()->latest('id')->sole();
    expect($first->totals['energy_kcal'])->toEqual(1000.0)
        ->and($first->per_serving['energy_kcal'])->toEqual(250.0)
        ->and($first->per_100g)->toBeNull();
    $panel->assertSee('len so zadanou konečnou hmotnosťou');

    $panel->set('finalWeight', '500')->call('compute')->assertHasNoErrors();
    $second = NutritionCalculation::query()->latest('id')->first();
    expect($second->id)->not->toBe($first->id)
        ->and($second->totals['energy_kcal'])->toEqual(1000.0)
        ->and($second->per_serving['energy_kcal'])->toEqual(250.0)
        ->and($second->per_100g['energy_kcal'])->toEqual(200.0)
        ->and((float) $second->final_weight_g)->toEqual(500.0);

    $panel->set('finalWeight', '1000')->call('compute')->assertHasNoErrors();
    $third = NutritionCalculation::query()->latest('id')->first();
    expect($third->per_100g['energy_kcal'])->toEqual(100.0)
        ->and($third->totals['energy_kcal'])->toEqual(1000.0)
        ->and(NutritionCalculation::query()->count())->toBe(3);

    $panel->set('finalWeight', '-5')->call('compute')->assertHasErrors(['finalWeight']);
});

it('reports unknown ingredients and missing source values as a partial sum, never as zero (scenario 8)', function () {
    ['household' => $household] = household();
    food('múka', ['energy_kcal' => 364, 'protein_g' => 10, 'carbohydrate_g' => 76, 'fat_g' => 1]);
    food('domáci sirup', ['energy_kcal' => 300, 'carbohydrate_g' => 75, 'fat_g' => null, 'protein_g' => null]);
    $recipe = Recipe::factory()->create(['household_id' => $household->id, 'base_servings' => 2]);
    line($recipe, 'múka', '100', 'g');
    line($recipe, 'domáci sirup', '100', 'g');
    line($recipe, 'xylitolový sirup', '20', 'g');

    Livewire::test(NutritionPanel::class, ['recipeId' => $recipe->id])
        ->call('start')
        ->assertSee('V slovníku nie je zhoda')
        ->call('compute')
        ->assertHasNoErrors()
        ->assertSee('Čiastočný súčet')
        ->assertSee('xylitolový sirup');

    $calculation = NutritionCalculation::query()->sole();
    expect($calculation->completeness)->toBe(NutritionCompleteness::Partial)
        ->and($calculation->totals['energy_kcal'])->toEqual(664.0)
        ->and($calculation->totals['fat_g'])->toEqual(1.0)
        ->and($calculation->totals['protein_g'])->toEqual(10.0)
        ->and(collect($calculation->missing)->pluck('name')->all())->toBe(['xylitolový sirup', 'domáci sirup', 'domáci sirup']);
});

it('lets a person pick cooked rice instead of raw and uses that record’s values (scenario 7)', function () {
    ['household' => $household] = household();
    $raw = food('ryža', ['energy_kcal' => 365, 'protein_g' => 7.1, 'carbohydrate_g' => 80, 'fat_g' => 0.7]);
    $cooked = food('ryža', ['energy_kcal' => 130, 'protein_g' => 2.7, 'carbohydrate_g' => 28, 'fat_g' => 0.3], FoodPreparationState::Cooked, 'Ryža uvarená');
    $recipe = Recipe::factory()->create(['household_id' => $household->id, 'base_servings' => 1]);
    $rice = line($recipe, 'ryža', '100', 'g');

    $panel = Livewire::test(NutritionPanel::class, ['recipeId' => $recipe->id])->call('start')->assertSee('Ryža uvarená');
    expect($rice->fresh()->foodMapping->food_source_record_id)->toBe($raw->id);

    $panel->set('choices.'.$rice->id, (string) $cooked->id)->call('compute')->assertHasNoErrors();

    $mapping = $rice->fresh()->foodMapping;
    expect($mapping->food_source_record_id)->toBe($cooked->id)
        ->and($mapping->preparation_state)->toBe(FoodPreparationState::Cooked)
        ->and($mapping->status)->toBe(FoodMappingStatus::Confirmed)
        ->and($mapping->grams)->toBe('100.00')
        ->and(NutritionCalculation::query()->sole()->totals['energy_kcal'])->toEqual(130.0);

    $panel->call('start')->set('choices.'.$rice->id, '')->call('compute');
    expect($rice->fresh()->foodMapping->status)->toBe(FoodMappingStatus::Rejected)
        ->and(NutritionCalculation::query()->latest('id')->first()->completeness)->toBe(NutritionCompleteness::Partial);
});

it('marks the calculation stale when a new revision changes ingredients or servings, keeps it for a title edit and keeps old runs (scenario 11)', function () {
    ['household' => $household, 'user' => $user] = household();
    food('cukor', ['energy_kcal' => 400, 'carbohydrate_g' => 100]);
    $recipe = Recipe::factory()->create(['household_id' => $household->id, 'base_servings' => 4]);
    $sugar = line($recipe, 'cukor', '250', 'g');
    $recipes = app(RecipeService::class);
    $recipe = $recipes->update($recipe, $user, ['title' => 'Sirup'], null);

    Livewire::test(NutritionPanel::class, ['recipeId' => $recipe->id])->call('start')->call('compute')->assertHasNoErrors();
    $first = NutritionCalculation::query()->sole();

    $recipe = $recipes->update($recipe, $user, ['title' => 'Sirup babkin', 'notes' => 'variť pomaly'], null);
    expect($first->fresh()->stale_at)->toBeNull();

    $recipe = $recipes->update($recipe, $user, ['ingredients' => [['id' => $sugar->id, 'name' => 'cukor', 'amount' => '300', 'unit' => 'g']]], null);
    expect($first->fresh()->stale_at)->not->toBeNull();

    $panel = Livewire::test(NutritionPanel::class, ['recipeId' => $recipe->id])
        ->assertSee('staršiu verziu')
        ->call('start');
    expect($sugar->fresh()->foodMapping->grams)->toBe('300.00')
        ->and($sugar->fresh()->foodMapping->status)->toBe(FoodMappingStatus::Confirmed);

    $panel->call('compute')->assertHasNoErrors()->assertDontSee('staršiu verziu');
    $latest = NutritionCalculation::query()->latest('id')->first();
    expect(NutritionCalculation::query()->count())->toBe(2)
        ->and($latest->stale_at)->toBeNull()
        ->and($latest->totals['energy_kcal'])->toEqual(1200.0)
        ->and($latest->recipe_revision_id)->toBe($recipe->fresh()->active_revision_id)
        ->and($first->fresh()->totals['energy_kcal'])->toEqual(1000.0);

    $recipes->update($recipe, $user, ['base_servings' => 6], null);
    expect($latest->fresh()->stale_at)->not->toBeNull();
});

it('counts a chosen share of a line and lets "nezapočítať" stand in for missing grams', function () {
    ['household' => $household] = household();
    $r = riceRecipe($household->id);

    $panel = Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])
        ->call('start')
        ->set('shares.'.$r['oil']->id, 0)
        ->set('shares.'.$r['chicken']->id, 50)
        ->call('compute')
        ->assertHasNoErrors()
        ->assertSet('error', '');

    $calculation = NutritionCalculation::query()->sole();
    expect($calculation->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($calculation->totals['energy_kcal'])->toEqual(910.0)
        ->and(collect($calculation->components)->firstWhere('name', 'kuracie prsia')['included_grams'])->toEqual(150.0)
        ->and(collect($calculation->components)->firstWhere('name', 'olej')['share'])->toEqual(0.0)
        ->and(implode(' ', $calculation->assumptions))->toContain('kuracie prsia')->toContain('50 %')->toContain('olej');

    $panel->assertSet('shares.'.$r['oil']->id, 0);
    Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])->assertSet('shares.'.$r['oil']->id, 0)->assertSet('shares.'.$r['chicken']->id, 50);
});

it('shows the result to a reading member, refuses changes from them and hides other households’ recipes', function () {
    ['household' => $household, 'user' => $owner] = household();
    $r = riceRecipe($household->id);
    app(RecipeNutrition::class)->calculate($r['recipe'], $owner, null, [$r['oil']->id => 0.0]);

    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $member->id, 'role' => MembershipRole::Member]);
    $this->actingAs($member);
    app(CurrentHousehold::class)->set($household);

    $this->get(route('recipes.show', $r['recipe']))->assertOk()->assertSee('Výživové hodnoty')->assertSee('kcal');
    Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])
        ->assertSee('Kompletný výpočet')
        ->assertDontSee('Upraviť priradenia')
        ->call('start')
        ->assertForbidden();
    Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])->call('compute')->assertForbidden();
    Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])->set('choices.'.$r['rice']->id, '')->assertForbidden();

    $stranger = User::factory()->create();
    $other = CurrentHousehold::createFor($stranger);
    $this->actingAs($stranger);
    app(CurrentHousehold::class)->set($other);
    Livewire::test(NutritionPanel::class, ['recipeId' => $r['recipe']->id])->assertNotFound();
});

it('exports mappings and calculations with provider references (schema 3)', function () {
    ['household' => $household, 'user' => $user] = household();
    $r = riceRecipe($household->id);
    $calculation = app(RecipeNutrition::class)->calculate($r['recipe'], $user, 800, [$r['oil']->id => 0.0]);

    $data = app(ExportService::class)->data($household);
    $recipe = $data['recipes'][0];
    $rice = collect($recipe['ingredients'])->firstWhere('name', 'Ryža');

    expect($data['schema_version'])->toBe(3)
        ->and($rice['food_mapping']['provider'])->toBe('usda_fdc')
        ->and($rice['food_mapping']['external_id'])->toBe($r['rice']->fresh()->foodMapping->record->external_id)
        ->and($rice['food_mapping']['grams'])->toBe('200.00')
        ->and($rice['food_mapping']['grams_origin'])->toBe(FoodGramsOrigin::UnitConversion->value)
        ->and($recipe['nutrition_calculations'])->toHaveCount(1)
        ->and($recipe['nutrition_calculations'][0]['id'])->toBe($calculation->id)
        ->and($recipe['nutrition_calculations'][0]['per_100g']['energy_kcal'])->toEqual(136.25)
        ->and($recipe['nutrition_calculations'][0]['components'])->toHaveCount(4);

    app(RecipeService::class)->destroy($r['recipe']);
    expect(NutritionCalculation::query()->count())->toBe(0);
});
