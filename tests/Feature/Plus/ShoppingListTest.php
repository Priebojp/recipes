<?php

use App\Models\IngredientLine;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Services\IngredientAmountParser;
use App\Services\MealPlanningService;
use App\Services\PlanningCalendar;
use App\Services\Plus\ShoppingListBuilder;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\PlusScenario;

function thisWeek(): CarbonImmutable
{
    return (new PlanningCalendar('Europe/Bratislava'))->thisWeekStart();
}

/** @param  list<array{0: string, 1: string|null, 2: string|null}>  $lines  name, amount, unit */
function recipeWithIngredients(int $householdId, string $title, ?int $servings, array $lines): Recipe
{
    $recipe = Recipe::factory()->create(['household_id' => $householdId, 'title' => $title, 'base_servings' => $servings]);
    foreach ($lines as $i => [$name, $amount, $unit]) {
        $parsed = (new IngredientAmountParser)->parse($amount);
        IngredientLine::create(['recipe_id' => $recipe->id, 'position' => $i, 'name' => $name, 'numeric_amount' => $parsed['numeric'], 'text_amount' => $parsed['text'], 'unit' => $unit]);
    }

    return $recipe;
}

it('merges the ingredients of the planned week by name and unit, scales by servings and keeps text amounts apart', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $week = thisWeek();

    $soup = recipeWithIngredients($household->id, 'Polievka', 2, [['Cibuľa', '1', 'ks'], ['mrkva', '200', 'g'], ['soľ', 'podľa chuti', null]]);
    $stew = recipeWithIngredients($household->id, 'Guláš', 4, [['cibuľa', '2', 'ks'], ['Mrkva', '0,5', 'kg'], ['Soľ', '1', 'ČL']]);
    $noBase = recipeWithIngredients($household->id, 'Praženica', null, [['Cibuľa', '1', 'ks']]);
    recipeWithIngredients($household->id, 'Nenaplánované', 2, [['Cibuľa', '10', 'ks']]);

    $planning = app(MealPlanningService::class);
    $planning->create($household, $soup, ['mode' => 'date', 'scheduled_date' => $week->toDateString(), 'servings' => 4, 'person_ids' => [$a->id]], $user); // ×2
    $planning->create($household, $stew, ['mode' => 'week', 'week_start_date' => $week->toDateString(), 'servings' => 2, 'person_ids' => [$a->id]], $user); // ×0.5
    $planning->create($household, $noBase, ['mode' => 'date', 'scheduled_date' => $week->addDays(6)->toDateString(), 'servings' => 6, 'person_ids' => [$a->id]], $user); // not scalable
    $planning->create($household, $soup, ['mode' => 'date', 'scheduled_date' => $week->addDays(7)->toDateString(), 'servings' => 4, 'person_ids' => [$a->id]], $user); // next week
    $cancelled = $planning->create($household, $stew, ['mode' => 'date', 'scheduled_date' => $week->addDays(2)->toDateString(), 'person_ids' => [$a->id]], $user);
    $planning->cancel($cancelled);

    $list = app(ShoppingListBuilder::class)->generate($household, $week, $user);
    $byKey = $list->items->keyBy('merge_key');

    expect($list->plan_count)->toBe(3)
        ->and($byKey->get('cibuľa|ks')->numeric_amount)->toBe('4.000') // 1×2 + 2×0.5 + 1 (Praženica unscaled)
        ->and($byKey->get('cibuľa|ks')->name)->toBe('Cibuľa')
        ->and(collect($byKey->get('cibuľa|ks')->sources)->pluck('title')->all())->toBe(['Polievka', 'Praženica', 'Guláš'])
        ->and(collect($byKey->get('cibuľa|ks')->sources)->pluck('scaled')->all())->toBe([true, false, true])
        ->and($byKey->get('mrkva|g')->numeric_amount)->toBe('400.000')
        ->and($byKey->get('mrkva|kg')->numeric_amount)->toBe('0.250')
        ->and($byKey->get('soľ|')->numeric_amount)->toBeNull()
        ->and($byKey->get('soľ|')->text_amounts)->toBe(['podľa chuti (Polievka)'])
        ->and($byKey->get('soľ|čl')->numeric_amount)->toBe('0.500')
        ->and($list->items->pluck('name')->all())->toBe(['Cibuľa', 'mrkva', 'Mrkva', 'soľ', 'Soľ']);
});

it('keeps checked state and manual items across regeneration and drops lines no longer in the plan', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $week = thisWeek();
    $builder = app(ShoppingListBuilder::class);

    $soup = recipeWithIngredients($household->id, 'Polievka', 2, [['Cibuľa', '1', 'ks'], ['Mrkva', '200', 'g']]);
    $plan = app(MealPlanningService::class)->create($household, $soup, ['mode' => 'date', 'scheduled_date' => $week->toDateString(), 'servings' => 2, 'person_ids' => [$a->id]], $user);

    $list = $builder->generate($household, $week, $user);
    $onion = $list->items->firstWhere('merge_key', 'cibuľa|ks');
    $builder->toggle($onion);
    $bread = $builder->addManual($list, 'Chlieb', '1', 'ks');
    $builder->addManual($list, 'chlieb', '1', 'ks'); // same line: adds up instead of duplicating

    $soup->ingredients()->where('name', 'Mrkva')->delete();
    $plan->update(['servings' => 4]);

    $list = $builder->generate($household, $week, $user);

    expect($list->items->pluck('merge_key')->all())->toBe(['cibuľa|ks', 'chlieb|ks'])
        ->and($list->items->firstWhere('merge_key', 'cibuľa|ks')->id)->toBe($onion->id)
        ->and($list->items->firstWhere('merge_key', 'cibuľa|ks')->isChecked())->toBeTrue()
        ->and($list->items->firstWhere('merge_key', 'cibuľa|ks')->numeric_amount)->toBe('2.000')
        ->and($list->items->firstWhere('merge_key', 'chlieb|ks')->id)->toBe($bread->id)
        ->and($list->items->firstWhere('merge_key', 'chlieb|ks')->manual)->toBeTrue()
        ->and($list->items->firstWhere('merge_key', 'chlieb|ks')->numeric_amount)->toBe('2.000')
        ->and(ShoppingList::query()->where('household_id', $household->id)->count())->toBe(1);

    expect(fn () => $builder->remove($onion->fresh()))->toThrow(InvalidArgumentException::class);
    $builder->remove($bread->fresh());
    expect($list->items()->count())->toBe(1);
});

it('gates generation behind Plus but keeps an existing list usable, and works from the page', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    $week = thisWeek();
    $soup = recipeWithIngredients($household->id, 'Polievka', 2, [['Cibuľa', '1', 'ks']]);
    app(MealPlanningService::class)->create($household, $soup, ['mode' => 'date', 'scheduled_date' => $week->toDateString(), 'servings' => 2, 'person_ids' => [$a->id]], $user);

    $this->get(route('plan.shopping'))->assertOk()->assertSee('je súčasťou programu Plus');
    Livewire::test('pages::plan.shopping')->call('generate')->assertForbidden();

    PlusScenario::activate($household);
    $component = Livewire::test('pages::plan.shopping')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('Cibuľa')
        ->set('newName', 'Chlieb')
        ->set('newAmount', '2')
        ->set('newUnit', 'ks')
        ->call('addItem')
        ->assertSee('Chlieb');

    $list = ShoppingList::query()->where('household_id', $household->id)->firstOrFail();
    $item = $list->items->firstWhere('name', 'Cibuľa');
    $component->call('toggle', $item->id);
    expect($item->fresh()->isChecked())->toBeTrue()
        ->and($component->get('asText'))->toBe('- Chlieb – 2 ks');

    // Plus lapsed: the list stays readable and tickable, only a new generation needs Plus again.
    PlusScenario::expire($household);
    $this->get(route('plan.shopping'))->assertOk()->assertSee('Cibuľa')->assertSee('Nové zostavenie vyžaduje Plus')->assertDontSee('je súčasťou programu Plus');
    Livewire::test('pages::plan.shopping')->call('toggle', $item->id)->assertOk();
    expect($item->fresh()->isChecked())->toBeFalse();
    Livewire::test('pages::plan.shopping')->call('generate')->assertForbidden();
});
