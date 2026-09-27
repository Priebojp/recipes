<?php

use App\Ai\Agents\MealAnalysisAgent;
use App\Enums\ConsumptionSource;
use App\Enums\FoodPreparationState;
use App\Enums\ManualNutritionOrigin;
use App\Enums\MembershipRole;
use App\Enums\NutritionCompleteness;
use App\Livewire\CookedPanel;
use App\Livewire\MealDiary;
use App\Livewire\MealPhotoAnalyzer;
use App\Models\AiJob;
use App\Models\ConsumptionNutritionSnapshot;
use App\Models\CookingEvent;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\MealAnalysis;
use App\Models\MealConsumption;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Models\UsageLedgerEntry;
use App\Models\UsageReservation;
use App\Models\User;
use App\Services\Ai\MealAnalysisService;
use App\Services\Billing\PlanStatus;
use App\Services\Diary\ConsumptionPortion;
use App\Services\Diary\MealDiaryService;
use App\Services\ExportService;
use App\Services\Nutrition\RecipeNutrition;
use App\Services\RecipeService;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * A food in the dictionary under one Slovak alias.
 *
 * @param  array<string, float|null>  $nutrients  per 100 g
 */
function diaryFood(string $alias, array $nutrients, FoodPreparationState $state = FoodPreparationState::Raw): FoodSourceRecord
{
    $record = FoodSourceRecord::factory()->create(array_merge(
        ['energy_kcal' => 0, 'energy_kj' => 0, 'protein_g' => 0, 'carbohydrate_g' => 0, 'fat_g' => 0, 'fiber_g' => 0],
        $nutrients,
        ['preparation_state' => $state, 'name_sk' => ucfirst($alias), 'name' => ucfirst($alias).', '.$state->value],
    ));
    FoodAlias::create(['food_source_record_id' => $record->id, 'alias' => $alias, 'normalized' => FoodAlias::normalize($alias), 'locale' => 'sk', 'preparation_state' => $state]);

    return $record;
}

/**
 * Sugar + butter syrup: 1 000 kcal on 4 servings, calculated through the real stage-10 flow (scenario 9's test input).
 *
 * @return array{recipe: Recipe, calculation: NutritionCalculation, sugar: FoodSourceRecord}
 */
function syrupRecipe(int $householdId, User $by): array
{
    $sugar = diaryFood('cukor', ['energy_kcal' => 400, 'energy_kj' => 1674, 'carbohydrate_g' => 100]);
    diaryFood('maslo', ['energy_kcal' => 800, 'energy_kj' => 3347, 'protein_g' => 1, 'fat_g' => 80]);
    $recipe = Recipe::factory()->create(['household_id' => $householdId, 'title' => 'Sirup', 'base_servings' => 4]);
    $recipe->ingredients()->create(['position' => 1, 'name' => 'cukor', 'numeric_amount' => '150', 'unit' => 'g']);
    $recipe->ingredients()->create(['position' => 2, 'name' => 'maslo', 'numeric_amount' => '50', 'unit' => 'g']);
    $calculation = app(RecipeNutrition::class)->calculate($recipe->fresh(), $by);

    return ['recipe' => $recipe->fresh(), 'calculation' => $calculation, 'sugar' => $sugar];
}

it('logs a serving of a recipe from the diary page, halves it to 125 kcal and sums the day (scenario 9)', function () {
    ['household' => $household, 'user' => $user] = household();
    $r = syrupRecipe($household->id, $user);
    expect($r['calculation']->per_serving['energy_kcal'])->toEqual(250.0);

    $this->get(route('diary.index'))->assertOk()->assertSee('Denník');

    $diary = Livewire::withQueryParams(['recept' => $r['recipe']->id])->test(MealDiary::class)
        ->assertSet('formOpen', true)
        ->assertSet('source', 'recipe')
        ->assertSet('recipeId', $r['recipe']->id)
        ->assertSeeHtml('data-test="diary-preview-kcal"')
        ->set('eatenDate', CarbonImmutable::now('Europe/Bratislava')->toDateString())
        ->set('eatenTime', '12:30')
        ->set('fraction', '100')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('formOpen', false);

    $entry = MealConsumption::query()->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->source)->toBe(ConsumptionSource::Recipe)
        ->and($entry->title_snapshot)->toBe('Sirup')
        ->and($entry->nutrition_calculation_id)->toBe($r['calculation']->id)
        ->and($entry->timezone)->toBe('Europe/Bratislava')
        ->and($entry->eatenAtLocal()->format('H:i'))->toBe('12:30')
        ->and($entry->snapshot->totals['energy_kcal'])->toEqual(250.0)
        ->and($entry->snapshot->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($entry->snapshot->revision)->toBe(1);

    // Half a serving: a correction is a new revision computed from the frozen basis; the first snapshot stays.
    $diary->call('startAdjust', $entry->id)
        ->assertSet('adjustingId', $entry->id)
        ->set('fraction', '50')
        ->call('saveAdjust')
        ->assertHasNoErrors()
        ->assertSet('adjustingId', null);

    $entry->refresh();
    expect($entry->snapshot->revision)->toBe(2)
        ->and($entry->snapshot->totals['energy_kcal'])->toEqual(125.0)
        ->and((float) $entry->portion_fraction)->toBe(0.5)
        ->and($entry->snapshots)->toHaveCount(2)
        ->and($entry->snapshots[0]->totals['energy_kcal'])->toEqual(250.0);

    // A second entry per component: the sugar eaten, the butter not (a syrup, but the arithmetic is the point).
    $diary->call('openForm', 'recipe')
        ->set('recipeId', $r['recipe']->id)
        ->set('eatenTime', '19:00')
        ->set('portionMode', 'per_component')
        ->set('componentShares', [0 => 100, 1 => 0])
        ->call('save')
        ->assertHasNoErrors();

    $second = MealConsumption::query()->latest('id')->first();
    expect($second->snapshot->totals['energy_kcal'])->toEqual(150.0)
        ->and($second->snapshot->component_shares)->toBe([0 => 1, 1 => 0])
        ->and($second->snapshot->assumptions)->toContain('„maslo“ nebolo zjedené (0 %).');

    $diary->assertSee('275 kcal')->assertSeeHtml('data-test="diary-sum-completeness"');
    expect(app(MealDiaryService::class)->dayTotals(MealConsumption::query()->with('snapshot')->get())['totals']['energy_kcal'])->toEqual(275.0);
});

it('keeps the snapshot when the recipe is edited and the food database changes; a correction still uses the frozen basis (scenario 11)', function () {
    ['household' => $household, 'user' => $user] = household();
    $r = syrupRecipe($household->id, $user);
    $service = app(MealDiaryService::class);

    $entry = $service->logRecipe($user, $household, $r['recipe'], ConsumptionPortion::fraction(1.0), now(), 'Europe/Bratislava');
    expect($entry->snapshot->totals['energy_kcal'])->toEqual(250.0);

    // The recipe changes (300 g sugar → the calculation is stale) and the food sync rewrites the sugar record.
    $sugarLine = $r['recipe']->ingredients()->where('name', 'cukor')->sole();
    app(RecipeService::class)->update($r['recipe'], $user, ['ingredients' => [['id' => $sugarLine->id, 'name' => 'cukor', 'amount' => '300', 'unit' => 'g']]], null);
    $r['sugar']->update(['energy_kcal' => 999]);
    expect($r['calculation']->fresh()->stale_at)->not->toBeNull();

    $entry->refresh();
    expect($entry->snapshot->totals['energy_kcal'])->toEqual(250.0)
        ->and($entry->snapshot->components[0]['nutrients_per_100g']['energy_kcal'])->toEqual(400.0);

    $snapshot = $service->adjust($entry, ConsumptionPortion::fraction(0.5));
    expect($snapshot->revision)->toBe(2)
        ->and($snapshot->totals['energy_kcal'])->toEqual(125.0)
        ->and(ConsumptionNutritionSnapshot::query()->where('meal_consumption_id', $entry->id)->count())->toBe(2);

    // A new entry from the stale calculation says so and points the person to a recalculation.
    Livewire::withQueryParams(['recept' => $r['recipe']->id])->test(MealDiary::class)
        ->assertSeeHtml('data-test="diary-stale"')
        ->assertSee('staršiu verziu');
    $fresh = $service->logRecipe($user, $household, $r['recipe']->fresh(), ConsumptionPortion::fraction(1.0), now(), 'Europe/Bratislava');
    expect($fresh->snapshot->totals['energy_kcal'])->toEqual(250.0)
        ->and(implode(' ', $fresh->snapshot->assumptions))->toContain('staršiu verziu receptu');
});

it('does not turn cooking into eating, nor a restaurant meal into cooking (scenario 12)', function () {
    ['household' => $household, 'user' => $user, 'person' => $person] = household();
    $r = syrupRecipe($household->id, $user);

    Livewire::test(CookedPanel::class)
        ->call('openFor', $r['recipe']->id)
        ->set('personIds', [$person->id])
        ->call('save')
        ->assertSet('saved', true)
        ->assertSeeHtml('data-test="cooked-log-meal"')
        ->assertSee('Zapísať, čo som zjedol');

    expect(CookingEvent::query()->count())->toBe(1)
        ->and(MealConsumption::query()->count())->toBe(0);

    Livewire::test(MealDiary::class)
        ->call('openForm', 'manual')
        ->set('title', 'Pizza v reštaurácii')
        ->set('eatenTime', '20:00')
        ->set('manual.energy_kcal', '850')
        ->set('manual.protein_g', '30')
        ->set('manual.carbohydrate_g', '95')
        ->set('manual.fat_g', '35')
        ->set('manualOrigin', 'label')
        ->call('save')
        ->assertHasNoErrors();

    $entry = MealConsumption::query()->sole();
    expect($entry->source)->toBe(ConsumptionSource::Manual)
        ->and($entry->recipe_id)->toBeNull()
        ->and($entry->snapshot->totals['energy_kcal'])->toEqual(850.0)
        ->and($entry->snapshot->totals['fiber_g'])->toBeNull()
        ->and($entry->snapshot->completeness)->toBe(NutritionCompleteness::Complete)
        ->and($entry->snapshot->manual_origin)->toBe(ManualNutritionOrigin::Label)
        ->and(CookingEvent::query()->count())->toBe(1);

    // Values without a stated origin are refused; without any values the meal is saved without calories.
    Livewire::test(MealDiary::class)
        ->call('openForm', 'manual')
        ->set('title', 'Koláč u babky')
        ->set('manual.energy_kcal', '400')
        ->call('save')
        ->assertSet('error', 'Uveď, odkiaľ hodnoty pochádzajú (etiketa alebo odhad).');
    Livewire::test(MealDiary::class)
        ->call('openForm', 'manual')
        ->set('title', 'Koláč u babky')
        ->set('withNutrition', false)
        ->call('save')
        ->assertHasNoErrors();
    $cake = MealConsumption::query()->latest('id')->first();
    expect($cake->snapshot->hasNutrition())->toBeFalse()
        ->and(app(MealDiaryService::class)->dayTotals(MealConsumption::query()->with('snapshot')->get()))->toMatchArray(['partial' => true, 'without_values' => 1, 'entries' => 2]);
});

it('logs a confirmed photo with "Zjedol som" and hides the diary from other household members and the admin (scenario 13)', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
    ['household' => $household, 'user' => $user] = household();
    $user->forceFill(['meal_photo_notice_accepted_at' => now()])->save();
    diaryFood('ryža varená', ['energy_kcal' => 130, 'protein_g' => 2.7, 'carbohydrate_g' => 28, 'fat_g' => 0.3], FoodPreparationState::Cooked);
    diaryFood('kuracie prsia', ['energy_kcal' => 165, 'protein_g' => 31, 'carbohydrate_g' => 0, 'fat_g' => 3.6], FoodPreparationState::Cooked);
    MealAnalysisAgent::fake([[
        'status' => 'recognized', 'dish_name' => 'Kuracie s ryžou', 'questions' => [], 'limitations' => [],
        'components' => [
            ['label' => 'ryža varená', 'is_unknown' => false, 'alternatives' => [], 'preparation_state' => 'cooked', 'estimated_grams' => 150, 'portion_basis' => null, 'visible_evidence' => null, 'assumptions' => []],
            ['label' => 'kuracie prsia', 'is_unknown' => false, 'alternatives' => [], 'preparation_state' => 'cooked', 'estimated_grams' => 120, 'portion_basis' => null, 'visible_evidence' => null, 'assumptions' => []],
        ],
    ]]);
    $photo = tempnam(sys_get_temp_dir(), 'meal').'.jpg';
    $image = imagecreatetruecolor(64, 64);
    imagejpeg($image, $photo);
    $analyses = app(MealAnalysisService::class);
    $analysis = $analyses->request($user, $household, new UploadedFile($photo, 'obed.jpg', 'image/jpeg', null, true));
    $analysis = $analyses->confirm($analysis);
    expect($analysis->nutrition['totals']['energy_kcal'])->toEqual(393.0);

    Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealPhotoAnalyzer::class)->assertSeeHtml('data-test="meal-log"');

    Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealDiary::class)
        ->assertSet('source', 'analysis')
        ->set('portionMode', 'per_component')
        ->set('componentShares', [0 => 0, 1 => 100])
        ->call('save')
        ->assertHasNoErrors();

    $entry = MealConsumption::query()->sole();
    expect($entry->source)->toBe(ConsumptionSource::Analysis)
        ->and($entry->meal_analysis_id)->toBe($analysis->id)
        ->and($entry->title_snapshot)->toBe('Kuracie s ryžou')
        ->and($entry->snapshot->totals['energy_kcal'])->toEqual(198.0)
        ->and($entry->snapshot->assumptions)->toContain('„ryža varená“ nebolo zjedené (0 %).');

    // A member of the same household: no entries on the page, 404 on the entry's actions, 403 on the foreign photo.
    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $member->id, 'role' => MembershipRole::Owner]);
    $this->actingAs($member);
    app(CurrentHousehold::class)->set($household);
    $this->get(route('diary.index'))->assertOk()->assertDontSee('Kuracie s ryžou');
    Livewire::withQueryParams([])->test(MealDiary::class)->assertDontSee('Kuracie s ryžou');
    Livewire::withQueryParams([])->test(MealDiary::class)->call('startAdjust', $entry->id)->assertNotFound();
    Livewire::withQueryParams([])->test(MealDiary::class)->call('remove', $entry->id)->assertNotFound();
    Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealDiary::class)->assertForbidden();
    expect(MealConsumption::query()->count())->toBe(1);

    $this->actingAs($user);
    expect($user->can('view', $entry))->toBeTrue()->and($member->can('view', $entry))->toBeFalse();

    actingAsPlatformAdmin();
    $this->get(route('admin.index'))->assertOk()->assertDontSee('Kuracie s ryžou');
    $this->get(route('admin.ai', ['kind' => 'meal_analysis']))->assertOk()->assertDontSee('Kuracie s ryžou');
});

it('exports the diary and kept photos with the account and erases them on withdrawal – also for a mere member (scenario 14)', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
    Mail::fake();
    ['household' => $household, 'user' => $owner] = household();

    $member = User::factory()->create(['meal_photo_notice_accepted_at' => now()]);
    HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $member->id, 'role' => MembershipRole::Editor]);
    $this->actingAs($member);
    app(CurrentHousehold::class)->set($household);

    MealAnalysisAgent::fake([['status' => 'recognized', 'dish_name' => 'Halušky', 'questions' => [], 'limitations' => [], 'components' => []]]);
    $photo = tempnam(sys_get_temp_dir(), 'meal').'.jpg';
    $image = imagecreatetruecolor(64, 64);
    imagejpeg($image, $photo);
    $analyses = app(MealAnalysisService::class);
    $analysis = $analyses->request($member, $household, new UploadedFile($photo, 'obed.jpg', 'image/jpeg', null, true));
    $analysis = $analyses->confirm($analysis, withNutrition: false, keepPhoto: true);
    $photoPath = $analysis->photo()->getPath();

    $diary = app(MealDiaryService::class);
    $entry = $diary->logAnalysis($member, $analysis, ConsumptionPortion::fraction(1.0), now(), 'Europe/Bratislava', 'večera');
    $deleted = $diary->logManual($member, $household, 'Jablko', ['energy_kcal' => 52], ManualNutritionOrigin::Estimate, now(), 'Europe/Bratislava');
    $diary->delete($deleted);

    $export = $this->get(route('privacy.export'))->assertOk()->assertDownload();
    $zip = new ZipArchive;
    $zip->open($export->getFile()->getPathname());
    $data = json_decode((string) $zip->getFromName('ucet.json'), true);
    expect($data['schema_version'])->toBe(2)
        ->and($data['meal_consumptions'])->toHaveCount(2)
        ->and($data['meal_consumptions'][0]['title'])->toBe('Halušky')
        ->and($data['meal_consumptions'][0]['note'])->toBe('večera')
        ->and($data['meal_consumptions'][0]['snapshots'][0]['totals'])->toBeNull()
        ->and($data['meal_consumptions'][1]['deleted_at'])->not->toBeNull()
        ->and($data['meal_consumptions'][1]['snapshots'][0]['totals']['energy_kcal'])->toEqual(52.0)
        ->and($data['meal_consumptions'][1]['snapshots'][0]['manual_origin'])->toBe('estimate')
        ->and($data['meal_analyses'])->toHaveCount(1)
        ->and($data['meal_analyses'][0]['photo'])->toStartWith('fotky/analyza-'.$analysis->id)
        ->and($zip->locateName($data['meal_analyses'][0]['photo']))->not->toBeFalse();
    $zip->close();

    // The household export stays the household's: no diary in it.
    $householdExport = app(ExportService::class)->data($household);
    expect($householdExport)->not->toHaveKey('meal_consumptions');

    // Leaving as a member: the diary and the photo go, the household and its recipes stay.
    Livewire::test('pages::settings.privacy')->set('password', 'password')->call('erase')->assertHasNoErrors();
    expect(MealConsumption::query()->withTrashed()->count())->toBe(0)
        ->and(ConsumptionNutritionSnapshot::query()->count())->toBe(0)
        ->and(MealAnalysis::query()->find($analysis->id))->toBeNull()
        ->and(is_file($photoPath))->toBeFalse()
        ->and(Household::query()->find($household->id))->not->toBeNull();

    // The owner's own diary goes with the household on erasure and stays gone after a re-application.
    $this->actingAs($owner);
    app(CurrentHousehold::class)->set($household);
    $diary->logManual($owner, $household, 'Raňajky', null, null, now(), 'Europe/Bratislava');
    expect(MealConsumption::query()->withTrashed()->count())->toBe(1);
    Livewire::test('pages::settings.privacy')->set('password', 'password')->call('erase')->assertHasNoErrors();
    expect(MealConsumption::query()->withTrashed()->count())->toBe(0)
        ->and(Household::query()->find($household->id))->toBeNull();
});

it('corrects a portion without AI, without a usage and without Plus (scenario 15)', function () {
    ['household' => $household, 'user' => $user] = household();
    $r = syrupRecipe($household->id, $user);
    $diary = app(MealDiaryService::class);
    $entry = $diary->logRecipe($user, $household, $r['recipe'], ConsumptionPortion::fraction(1.0), now(), 'Europe/Bratislava');

    expect(app(PlanStatus::class)->isPlus($household))->toBeFalse();
    $before = [AiJob::query()->count(), UsageReservation::query()->count(), UsageLedgerEntry::query()->count()];

    $snapshot = $diary->adjust($entry, ConsumptionPortion::grams(25));
    expect($snapshot->totals['energy_kcal'])->toEqual(125.0)
        ->and($snapshot->assumptions)->toContain('Hmotnosť porcie je súčet započítaných surovín (50 g), nie odvážené hotové jedlo.')
        ->and([AiJob::query()->count(), UsageReservation::query()->count(), UsageLedgerEntry::query()->count()])->toBe($before);

    Livewire::test(MealDiary::class)
        ->call('startAdjust', $entry->id)
        ->assertSet('portionMode', 'grams')
        ->assertSet('gramsEaten', '25')
        ->set('portionMode', 'fraction')
        ->set('fraction', '150')
        ->call('saveAdjust')
        ->assertHasNoErrors()
        ->assertSee('375 kcal');
    expect($entry->fresh()->snapshot->revision)->toBe(3)
        ->and(AiJob::query()->count())->toBe($before[0]);
});
