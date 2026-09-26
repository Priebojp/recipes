<?php

use App\Models\Person;
use App\Models\Recipe;
use App\Services\RecipeSelectionService;

it('renders every main page for a household member', function () {
    ['household' => $household, 'person' => $person] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Praženica']);
    Person::factory()->create(['household_id' => $household->id, 'name' => 'Eva']);

    $this->get(route('cook.index'))->assertOk()->assertSee('Vyber mi jedlo');
    $this->get(route('cook.select'))->assertOk()->assertSee('Pre koho varíš');
    $this->get(route('recipes.index'))->assertOk()->assertSee('Praženica');
    $this->get(route('recipes.create'))->assertOk();
    $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Praženica');
    $this->get(route('recipes.edit', $recipe))->assertOk()->assertSee('Upraviť recept');
    $this->get(route('plan.index'))->assertOk()->assertSee('Týždeň');
    $this->get(route('plan.history'))->assertOk();
    $this->get(route('plan.propose'))->assertOk()->assertSee('Návrh týždenného jedálnička');
    $this->get(route('plan.shopping'))->assertOk()->assertSee('Nákupný zoznam');
    $this->get(route('family.index'))->assertOk()->assertSee('Eva');
    $this->get(route('household.edit'))->assertOk();

    $session = app(RecipeSelectionService::class)->start($household, null, ['person_ids' => [$person->id], 'term' => 'today']);
    $this->get(route('cook.session', $session))->assertOk()->assertSee('Praženica');
});

it('shows empty states when there are no recipes or people', function () {
    household();

    $this->get(route('cook.index'))->assertOk()->assertSee('Pridaj prvé jedlo');
});
