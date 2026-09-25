<?php

use App\Enums\PlanStatus;
use App\Enums\Preference;
use App\Livewire\CookedPanel;
use App\Livewire\PlanPanel;
use App\Models\CookingEvent;
use App\Models\MealPlan;
use App\Models\PersonRecipePreference;
use App\Models\Recipe;
use App\Services\CookingHistoryService;
use App\Services\MealPlanningService;
use App\Services\RecipeSelectionService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('plans next week correctly on Sunday and on Monday and can move a week plan to a day', function (string $now, string $expectedWeek) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($now, 'Europe/Bratislava'));
    ['household' => $household, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);

    $term = app(RecipeSelectionService::class)->resolveTerm($household, 'next_week');
    $plan = app(MealPlanningService::class)->create($household, $recipe, ['mode' => $term['mode'], 'week_start_date' => $term['week_start_date']], $user);

    expect($plan->week_start_date->toDateString())->toBe($expectedWeek)->and($plan->scheduled_date)->toBeNull();

    $moved = app(MealPlanningService::class)->update($plan, ['mode' => 'date', 'scheduled_date' => $expectedWeek]);
    expect($moved->scheduled_date->toDateString())->toBe($expectedWeek)->and($moved->week_start_date)->toBeNull();

    CarbonImmutable::setTestNow();
})->with([
    ['2026-09-27 21:00', '2026-09-28'],
    ['2026-09-28 08:00', '2026-10-05'],
]);

it('never marks an overdue plan as cooked by itself and cancelling creates no history', function () {
    ['household' => $household, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $plan = app(MealPlanningService::class)->create($household, $recipe, ['mode' => 'date', 'scheduled_date' => now()->subDays(3)->toDateString()], $user);

    $this->get(route('plan.index'))->assertOk()->assertSee('Nepotvrdené z minulosti');
    expect($plan->fresh()->status)->toBe(PlanStatus::Planned);

    app(MealPlanningService::class)->cancel($plan);
    expect($plan->fresh()->status)->toBe(PlanStatus::Cancelled)->and(CookingEvent::count())->toBe(0);

    app(MealPlanningService::class)->restore($plan->fresh());
    expect($plan->fresh()->status)->toBe(PlanStatus::Planned);
});

it('confirms cooking idempotently and lets a mistake be undone', function () {
    ['household' => $household, 'user' => $user, 'person' => $a] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $plan = app(MealPlanningService::class)->create($household, $recipe, ['mode' => 'date', 'scheduled_date' => now()->toDateString(), 'person_ids' => [$a->id], 'servings' => 2], $user);
    $service = app(MealPlanningService::class);

    $service->markCooked($plan, [], $user, 'key-1');
    $service->markCooked($plan->fresh(), [], $user, 'key-1');
    $service->markCooked($plan->fresh(), [], $user, 'key-2');

    expect(CookingEvent::active()->count())->toBe(1)
        ->and($plan->fresh()->status)->toBe(PlanStatus::Cooked)
        ->and(CookingEvent::first()->people->pluck('id')->all())->toBe([$a->id])
        ->and(CookingEvent::first()->servings)->toBe(2);

    $service->undoCooked($plan->fresh());
    expect($plan->fresh()->status)->toBe(PlanStatus::Planned)
        ->and(CookingEvent::active()->count())->toBe(0)
        ->and(CookingEvent::count())->toBe(1);

    // A fresh confirmation after undo is allowed without two active events.
    $service->markCooked($plan->fresh(), [], $user, 'key-3');
    expect(CookingEvent::active()->count())->toBe(1);
});

it('records cooking from the detail without a plan and refuses future dates', function () {
    ['household' => $household, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $history = app(CookingHistoryService::class);

    $event = $history->record($household, $recipe, ['cooked_on' => now()->toDateString()], $user, 'abc');
    $again = $history->record($household, $recipe, ['cooked_on' => now()->toDateString()], $user, 'abc');

    expect($again->id)->toBe($event->id)->and(CookingEvent::count())->toBe(1);

    expect(fn () => $history->record($household, $recipe, ['cooked_on' => now()->addDay()->toDateString()], $user))
        ->toThrow(InvalidArgumentException::class);
});

it('saves a plan through the shared panel from a session and marks the session accepted', function () {
    ['household' => $household, 'person' => $a] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $session = app(RecipeSelectionService::class)->start($household, null, ['person_ids' => [$a->id], 'term' => 'tomorrow']);

    Livewire::test(PlanPanel::class)
        ->call('openFor', $recipe->id, ['person_ids' => [$a->id], 'term' => 'tomorrow'], $session->id)
        ->assertSet('term', 'tomorrow')
        ->assertSet('servings', 1)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertDispatched('plan-saved');

    $plan = MealPlan::firstOrFail();
    expect($plan->scheduled_date->toDateString())->toBe(now('Europe/Bratislava')->addDay()->toDateString())
        ->and($plan->people->pluck('id')->all())->toBe([$a->id])
        ->and($session->fresh()->state['accepted'])->toBe([$recipe->id]);
});

it('warns about a collision on the same day and allows a conscious duplicate', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    app(MealPlanningService::class)->create($household, $recipe, ['mode' => 'date', 'scheduled_date' => now('Europe/Bratislava')->toDateString(), 'person_ids' => [$a->id]], $user);

    $component = Livewire::test(PlanPanel::class)
        ->call('openFor', $recipe->id, ['person_ids' => [$a->id], 'term' => 'today'])
        ->call('save')
        ->assertSet('saved', false)
        ->assertSee('už je naplánované');

    $component->set('forceDuplicate', true)->call('save')->assertSet('saved', true);
    expect(MealPlan::count())->toBe(2);
});

it('confirms cooking through the cooked panel with double submit protection and the taste question', function () {
    ['household' => $household, 'person' => $a] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);

    $panel = Livewire::test(CookedPanel::class)
        ->call('openFor', $recipe->id, null, ['person_ids' => [$a->id]])
        ->call('save')
        ->call('save')
        ->assertSet('saved', true)
        ->assertSee('Chutilo?');

    expect(CookingEvent::count())->toBe(1)
        ->and(PersonRecipePreference::count())->toBe(0);

    $panel->call('setTaste', $a->id, 'favorite');
    expect(PersonRecipePreference::first()->preference)->toBe(Preference::Favorite);
});
