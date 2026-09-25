<?php

use App\Enums\Preference;
use App\Models\CookingEvent;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\PersonRecipePreference;
use App\Models\Recipe;
use App\Models\SelectionSession;
use App\Services\CookingHistoryService;
use App\Services\PreferenceService;
use App\Services\RecipeSelectionService;
use App\Services\Selection\SelectionConfig;
use App\Services\Selection\WeightedPicker;
use Livewire\Livewire;

function deterministicService(float $random = 0.0): RecipeSelectionService
{
    return new RecipeSelectionService(SelectionConfig::fromConfig(), new WeightedPicker(fn () => $random));
}

it('excludes disliked meals in the default mode and allows them with low weight when relaxed', function () {
    ['household' => $household, 'person' => $a] = household();
    $b = Person::factory()->create(['household_id' => $household->id, 'name' => 'B']);
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $prefs = app(PreferenceService::class);
    $prefs->set($a, $recipe, Preference::Favorite);
    $prefs->set($b, $recipe, Preference::Dislikes);

    $session = deterministicService()->start($household, null, ['person_ids' => [$a->id, $b->id]]);
    expect($session->state['current'])->toBeNull()
        ->and($session->candidates['soft_counts'])->toBe(['dislikes' => 1]);

    $relaxed = deterministicService()->restartWith($session, ['allow_disliked' => true]);
    expect($relaxed->state['current'])->toBe($recipe->id)
        ->and($relaxed->candidates['scored'][0]['weight'])->toBeLessThan(1.0);
});

it('never offers a recipe with a hard exclusion, even when filters are relaxed', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    app(PreferenceService::class)->exclude($a, $recipe, 'alergia', $user);

    $session = deterministicService()->start($household, null, ['person_ids' => [$a->id], 'filters' => ['allow_disliked' => true, 'include_untyped' => true]]);

    expect($session->state['current'])->toBeNull()
        ->and($session->candidates['excluded'][0]['hard'])->toBeTrue()
        ->and($session->candidates['soft_counts'])->toBe([]);
});

it('does not let an unrated guest block all recipes', function () {
    ['household' => $household, 'person' => $a] = household();
    $guest = Person::factory()->guest()->create(['household_id' => $household->id, 'name' => 'Babka']);
    Recipe::factory()->count(3)->create(['household_id' => $household->id]);

    $session = deterministicService()->start($household, null, ['person_ids' => [$a->id, $guest->id]]);

    expect($session->state['current'])->not->toBeNull()
        ->and(count($session->candidates['scored']))->toBe(3)
        ->and(implode(' ', $session->candidates['scored'][0]['reasons']))->toContain('nepoznáme hodnotenie');
});

it('skips without changing preferences, never repeats a card, and undo restores the last skipped card', function () {
    ['household' => $household, 'person' => $a] = household();
    $recipes = Recipe::factory()->count(3)->create(['household_id' => $household->id]);
    $service = deterministicService();

    $session = $service->start($household, null, ['person_ids' => [$a->id]]);
    $shown = [$session->state['current']];

    $session = $service->skip($session);
    $shown[] = $session->state['current'];
    $session = $service->skip($session);
    $shown[] = $session->state['current'];

    expect(array_unique($shown))->toHaveCount(3)
        ->and($session->state['available'])->toBe([])
        ->and(PersonRecipePreference::count())->toBe(0)
        ->and(MealPlan::count())->toBe(0)
        ->and(CookingEvent::count())->toBe(0);

    $session = $service->skip($session);
    expect($session->state['current'])->toBeNull();

    $session = $service->undo($session);
    expect($session->state['current'])->toBe($shown[2]);

    $session = $service->undo($session);
    expect($session->state['current'])->toBe($shown[1])
        ->and($session->state['available'])->toBe([$shown[2]]);
});

it('keeps the session position across detail and refresh', function () {
    ['household' => $household, 'person' => $a] = household();
    Recipe::factory()->count(2)->create(['household_id' => $household->id]);
    $service = deterministicService();
    $session = $service->skip($service->start($household, null, ['person_ids' => [$a->id]]));
    $current = $session->state['current'];

    $this->get(route('recipes.show', ['recipe' => $current, 'session' => $session->id]))->assertOk();
    $this->get(route('cook.session', $session))->assertOk();
    $this->get(route('cook.session', $session))->assertOk();

    expect(SelectionSession::find($session->id)->state['current'])->toBe($current);
});

it('re-validates archive and exclusions before showing a card from an older session', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    $recipes = Recipe::factory()->count(2)->create(['household_id' => $household->id]);
    $service = deterministicService();
    $session = $service->start($household, null, ['person_ids' => [$a->id]]);

    $next = collect($session->state['available'])->first();
    app(PreferenceService::class)->exclude($a, Recipe::find($next), null, $user);

    $session = $service->skip($session);
    expect($session->state['current'])->toBeNull();

    $another = Recipe::factory()->create(['household_id' => $household->id]);
    $session2 = $service->start($household, null, ['person_ids' => [$a->id]]);
    Recipe::whereKey($session2->state['available'][0] ?? $another->id)->update(['archived_at' => now()]);
    expect($service->stillValid($session2, $another->id))->toBe(! $another->fresh()->isArchived());
});

it('gives a recently cooked meal a lower but positive weight in a real session', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    $fresh = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Staré']);
    $recent = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Včerajšie']);
    app(CookingHistoryService::class)->record($household, $recent, ['cooked_on' => now()->subDay()->toDateString(), 'person_ids' => [$a->id]], $user);
    app(CookingHistoryService::class)->record($household, $fresh, ['cooked_on' => now()->subDays(30)->toDateString(), 'person_ids' => [$a->id]], $user);

    $session = deterministicService()->start($household, null, ['person_ids' => [$a->id], 'term' => 'today']);
    $weights = collect($session->candidates['scored'])->pluck('weight', 'id');

    expect($weights[$recent->id])->toBeGreaterThan(0)->toBeLessThan($weights[$fresh->id]);
});

it('penalises a meal cooked only for guests less when choosing for the family', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    $guest = Person::factory()->guest()->create(['household_id' => $household->id]);
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    app(CookingHistoryService::class)->record($household, $recipe, ['cooked_on' => now()->toDateString(), 'person_ids' => [$guest->id]], $user);

    $forFamily = deterministicService()->start($household, null, ['person_ids' => [$a->id], 'term' => 'today']);
    $forGuest = deterministicService()->start($household, null, ['person_ids' => [$guest->id], 'term' => 'today']);

    expect($forFamily->candidates['scored'][0]['weight'])->toBeGreaterThan($forGuest->candidates['scored'][0]['weight']);
});

it('runs the card page: skip, undo and want-to-cook open the shared plan panel', function () {
    ['household' => $household, 'person' => $a] = household();
    Recipe::factory()->count(2)->create(['household_id' => $household->id]);
    $session = deterministicService()->start($household, null, ['person_ids' => [$a->id], 'term' => 'tomorrow']);

    Livewire::test('pages::cook.session', ['session' => $session->id])
        ->assertSee('Teraz nie')
        ->call('skip')
        ->call('undo')
        ->call('wantToCook')
        ->assertDispatched('open-plan-panel');
});

it('creates a session from the wizard and adds a one-off guest', function () {
    ['household' => $household, 'person' => $a] = household();
    Recipe::factory()->create(['household_id' => $household->id]);

    Livewire::test('pages::cook.select')
        ->set('personIds', [$a->id])
        ->set('showGuestForm', true)
        ->set('guestName', 'Jednorazový')
        ->set('guestSave', false)
        ->call('addGuest')
        ->set('term', 'tomorrow')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirect();

    $session = SelectionSession::firstOrFail();
    $guest = Person::where('name', 'Jednorazový')->firstOrFail();
    expect($session->inputs['person_ids'])->toContain($guest->id)
        ->and($session->inputs['one_off_guest_ids'])->toBe([$guest->id])
        ->and($session->inputs['term']['mode'])->toBe('date');
});
