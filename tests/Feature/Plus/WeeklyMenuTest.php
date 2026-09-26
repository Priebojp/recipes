<?php

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\RecipeMealType;
use App\Services\MealPlanningService;
use App\Services\PlanningCalendar;
use App\Services\Plus\WeeklyMenuPlanner;
use App\Services\PreferenceService;
use App\Services\RecipeSelectionService;
use App\Services\Selection\SelectionConfig;
use App\Services\Selection\WeightedPicker;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\PlusScenario;

function weeklyPlanner(float $random = 0.0): WeeklyMenuPlanner
{
    return new WeeklyMenuPlanner(app(RecipeSelectionService::class), app(MealPlanningService::class), SelectionConfig::fromConfig(), new WeightedPicker(fn () => $random));
}

function nextWeek(): string
{
    return (new PlanningCalendar('Europe/Bratislava'))->nextWeekStart()->toDateString();
}

it('proposes a week without repeating a recipe, leaves planned slots alone and confirms into meal plans', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $recipes = Recipe::factory()->count(4)->create(['household_id' => $household->id]);
    $week = nextWeek();

    // Tuesday dinner is already planned by hand: the proposal must keep it and never reuse that recipe.
    $existing = app(MealPlanningService::class)->create($household, $recipes[0], ['mode' => 'date', 'scheduled_date' => CarbonImmutable::parse($week)->addDay()->toDateString(), 'meal_type' => 'dinner', 'person_ids' => [$a->id]], $user);

    $slots = weeklyPlanner()->propose($household, ['week_start' => $week, 'days' => [0, 1, 2, 3, 4, 5, 6], 'meal_types' => ['dinner'], 'person_ids' => [$a->id]]);

    expect($slots)->toHaveCount(7)
        ->and($slots[1]['occupied'])->toBeTrue()
        ->and($slots[1]['existing_plan_id'])->toBe($existing->id)
        ->and($slots[1]['recipe_id'])->toBe($recipes[0]->id);

    $picked = collect($slots)->reject(fn ($s) => $s['occupied'])->pluck('recipe_id')->filter();
    // Three free recipes remain for six free slots: each used once, the rest stays empty with a reason.
    expect($picked->count())->toBe(3)
        ->and($picked->unique()->count())->toBe(3)
        ->and($picked)->not->toContain($recipes[0]->id)
        ->and(collect($slots)->filter(fn ($s) => ! $s['occupied'] && $s['recipe_id'] === null)->first()['note'])->toContain('už sú v tomto týždni použité');

    $result = weeklyPlanner()->confirm($household, $slots, [$a->id], 2, $user);

    expect($result['created'])->toHaveCount(3)
        ->and($result['skipped'])->toBe([])
        ->and(MealPlan::query()->where('household_id', $household->id)->where('status', PlanStatus::Planned)->count())->toBe(4)
        ->and($result['created'][0]->meal_type)->toBe(MealType::Dinner)
        ->and($result['created'][0]->servings)->toBe(2)
        ->and($result['created'][0]->people->pluck('id')->all())->toBe([$a->id]);
});

it('respects meal types, hard exclusions and filters, and rerolls only one slot', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $breakfast = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Ovsená kaša', 'prep_minutes' => 5, 'cook_minutes' => 10]);
    RecipeMealType::create(['recipe_id' => $breakfast->id, 'meal_type' => MealType::Breakfast]);
    $dinner = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Guláš', 'prep_minutes' => 15, 'cook_minutes' => 45]);
    RecipeMealType::create(['recipe_id' => $dinner->id, 'meal_type' => MealType::Dinner]);
    $excluded = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Orechový koláč', 'prep_minutes' => 10, 'cook_minutes' => 10]);
    app(PreferenceService::class)->exclude($a, $excluded, 'alergia', $user);
    $slow = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Pečená hus', 'prep_minutes' => 60, 'cook_minutes' => 180]);

    $request = ['week_start' => nextWeek(), 'days' => [0, 1], 'meal_types' => ['breakfast', 'dinner'], 'person_ids' => [$a->id], 'filters' => ['include_untyped' => false, 'max_minutes' => 60]];
    $slots = weeklyPlanner()->propose($household, $request);

    expect($slots)->toHaveCount(4)
        ->and($slots[0]['meal_type'])->toBe('breakfast')
        ->and($slots[0]['recipe_id'])->toBe($breakfast->id)
        ->and($slots[1]['meal_type'])->toBe('dinner')
        ->and($slots[1]['recipe_id'])->toBe($dinner->id)
        ->and($slots[2]['recipe_id'])->toBeNull()
        ->and($slots[3]['recipe_id'])->toBeNull()
        ->and(collect($slots)->pluck('recipe_id'))->not->toContain($excluded->id, $slow->id);

    // Rerolling the only breakfast has no alternative: the slot becomes empty, the dinner stays.
    $rerolled = weeklyPlanner()->reroll($household, $request, $slots, 0);
    expect($rerolled[0]['recipe_id'])->toBeNull()
        ->and($rerolled[1]['recipe_id'])->toBe($dinner->id);
});

it('skips recipes archived or excluded after the proposal instead of planning them silently', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $recipes = Recipe::factory()->count(2)->create(['household_id' => $household->id]);

    $slots = weeklyPlanner()->propose($household, ['week_start' => nextWeek(), 'days' => [0, 1], 'meal_types' => ['any'], 'person_ids' => [$a->id]]);
    expect(collect($slots)->pluck('recipe_id')->filter())->toHaveCount(2);

    $recipes[0]->update(['archived_at' => now()]);
    $result = weeklyPlanner()->confirm($household, $slots, [$a->id], null, $user);

    expect($result['created'])->toHaveCount(1)
        ->and($result['skipped'])->toBe([$recipes[0]->title]);
});

it('shows the Plus gate to a Free household and refuses the actions, while Plus members can plan from the page', function () {
    ['household' => $household, 'person' => $a] = household();
    Recipe::factory()->count(3)->create(['household_id' => $household->id]);

    $this->get(route('plan.propose'))->assertOk()->assertSee('je súčasťou programu Plus')->assertDontSee('Navrhnúť týždeň');
    $this->get(route('plan.index'))->assertOk()->assertSee('Navrhnúť týždeň');

    Livewire::test('pages::plan.propose')
        ->set('personIds', [$a->id])
        ->call('propose')
        ->assertForbidden();

    PlusScenario::activate($household);
    Person::factory()->create(['household_id' => $household->id, 'name' => 'Eva']);

    $component = Livewire::test('pages::plan.propose')
        ->set('personIds', [$a->id])
        ->set('mealTypes', ['dinner'])
        ->set('days', ['0', '1', '2'])
        ->call('propose')
        ->assertHasNoErrors()
        ->assertSee('Návrh');

    expect(count($component->get('proposal')))->toBe(3)
        ->and(collect($component->get('proposal'))->pluck('recipe_id')->filter()->count())->toBe(3)
        ->and(MealPlan::count())->toBe(0);

    $component->call('clearSlot', 2)->call('confirm')->assertSee('2 jedlá sú v pláne');

    expect(MealPlan::query()->where('status', PlanStatus::Planned)->count())->toBe(2)
        ->and(MealPlan::query()->first()->scheduled_date->toDateString())->toBe(nextWeek());
});
