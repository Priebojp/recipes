<?php

use App\Enums\Preference;
use App\Livewire\PlanPanel;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Services\CookingHistoryService;
use App\Services\ImageUploadService;
use App\Services\MealPlanningService;
use App\Services\PreferenceService;
use App\Services\RecipeService;
use App\Services\StaleRecipeException;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('saves a recipe with only a title (quick add) and makes it usable immediately', function () {
    ['household' => $household] = household();

    Livewire::test('pages::recipes.create')
        ->set('title', '  Praženica ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $recipe = Recipe::where('household_id', $household->id)->firstOrFail();

    expect($recipe->title)->toBe('Praženica')
        ->and($recipe->description)->toBeNull()
        ->and($recipe->mealTypes)->toHaveCount(0)
        ->and($recipe->active_revision_id)->not->toBeNull();

    $this->get(route('recipes.index', ['q' => 'praž']))->assertOk()->assertSee('Praženica');
    $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Praženica');
});

it('requires a non-empty title of at most 200 characters', function () {
    household();

    Livewire::test('pages::recipes.create')->set('title', '   ')->call('save')->assertHasErrors(['title']);
    Livewire::test('pages::recipes.create')->set('title', str_repeat('a', 201))->call('save')->assertHasErrors(['title']);
});

it('warns about duplicate titles but still allows saving', function () {
    ['household' => $household] = household();
    Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Guláš']);

    Livewire::test('pages::recipes.create')
        ->set('title', 'guláš')
        ->assertSee('rovnakým názvom')
        ->call('save')
        ->assertHasNoErrors();

    expect(Recipe::where('household_id', $household->id)->count())->toBe(2);
});

it('stores ingredients with numeric or textual amounts, steps in order and a revision snapshot', function () {
    ['household' => $household, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);

    $recipe = app(RecipeService::class)->update($recipe, $user, [
        'base_servings' => 2,
        'prep_minutes' => 10,
        'cook_minutes' => 20,
        'meal_types' => ['lunch', 'dinner'],
        'ingredients' => [
            ['name' => 'Kuracie prsia', 'amount' => '500', 'unit' => 'g'],
            ['name' => 'Soľ', 'amount' => 'podľa chuti'],
            ['name' => '   '],
            ['name' => 'Cibuľa'],
        ],
        'steps' => [['text' => 'Nakrájať'], ['text' => ''], ['text' => 'Opiecť']],
    ], $recipe->version);

    expect($recipe->ingredients)->toHaveCount(3)
        ->and($recipe->ingredients[0]->numeric_amount)->toBe('500.000')
        ->and($recipe->ingredients[1]->text_amount)->toBe('podľa chuti')
        ->and($recipe->ingredients[1]->numeric_amount)->toBeNull()
        ->and($recipe->ingredients[2]->position)->toBe(2)
        ->and($recipe->steps->pluck('text')->all())->toBe(['Nakrájať', 'Opiecť'])
        ->and($recipe->totalMinutes())->toBe(30)
        ->and($recipe->mealTypeEnums())->toHaveCount(2)
        ->and($recipe->revisions)->toHaveCount(1)
        ->and($recipe->revisions->first()->snapshot['ingredients'])->toHaveCount(3);
});

it('rejects a stale update when the recipe changed meanwhile', function () {
    ['household' => $household, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    app(RecipeService::class)->update($recipe, $user, ['title' => 'Nový'], $recipe->version);

    expect(fn () => app(RecipeService::class)->update($recipe->fresh(), $user, ['title' => 'Ešte novší'], $recipe->version))
        ->toThrow(StaleRecipeException::class);
});

it('keeps history and plans when archiving and keeps the title snapshot when deleting', function () {
    ['household' => $household, 'user' => $user] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Halušky']);
    $plan = MealPlan::factory()->create(['household_id' => $household->id, 'recipe_id' => $recipe->id]);
    $event = app(CookingHistoryService::class)->record($household, $recipe, ['cooked_on' => now()->toDateString()], $user);

    app(RecipeService::class)->archive($recipe);
    expect($recipe->fresh()->isArchived())->toBeTrue()
        ->and($plan->fresh())->not->toBeNull()
        ->and($event->fresh()->recipe_id)->toBe($recipe->id);

    app(RecipeService::class)->destroy($recipe->fresh());
    expect(Recipe::find($recipe->id))->toBeNull()
        ->and($event->fresh()->recipe_id)->toBeNull()
        ->and($event->fresh()->recipe_title_snapshot)->toBe('Halušky');

    $this->get(route('plan.history'))->assertOk()->assertSee('Halušky');
});

it('serves cover images only to the owning household and strips metadata', function () {
    ['household' => $household] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $file = UploadedFile::fake()->image('cover.jpg', 800, 600);

    $media = app(ImageUploadService::class)->addCover($recipe, $file);

    expect($recipe->fresh()->cover_media_id)->toBe($media->id)
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue();

    $this->get(route('media.show', [$media, 'thumb']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    // Another household cannot read it, even with the id.
    household();
    $this->get(route('media.show', [$media, 'thumb']))->assertNotFound();
});

it('lets a step keep several photos and restores a previous cover', function () {
    ['household' => $household] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);
    $step = RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'text' => 'Krok']);
    $uploads = app(ImageUploadService::class);

    $uploads->addStepImage($step, UploadedFile::fake()->image('a.png', 300, 300));
    $uploads->addStepImage($step, UploadedFile::fake()->image('b.png', 300, 300));
    $first = $uploads->addCover($recipe, UploadedFile::fake()->image('c1.jpg', 400, 300));
    $second = $uploads->addCover($recipe, UploadedFile::fake()->image('c2.jpg', 400, 300));

    expect($step->getMedia(RecipeStep::IMAGES_COLLECTION))->toHaveCount(2)
        ->and($recipe->fresh()->cover_media_id)->toBe($second->id);

    $uploads->activateCover($recipe, $first);
    expect($recipe->fresh()->cover_media_id)->toBe($first->id);
});

it('isolates households: foreign recipes are not visible, editable or plannable', function () {
    ['household' => $other] = household();
    $foreignRecipe = Recipe::factory()->create(['household_id' => $other->id, 'title' => 'Cudzí recept']);
    $foreignPerson = Person::factory()->create(['household_id' => $other->id]);

    ['household' => $mine, 'user' => $user, 'person' => $me] = household();

    $this->get(route('recipes.show', $foreignRecipe))->assertNotFound();
    $this->get(route('recipes.edit', $foreignRecipe))->assertNotFound();
    $this->get(route('recipes.index'))->assertOk()->assertDontSee('Cudzí recept');

    expect(fn () => app(MealPlanningService::class)->create($mine, $foreignRecipe, ['mode' => 'someday'], $user))
        ->toThrow(InvalidArgumentException::class);

    $myRecipe = Recipe::factory()->create(['household_id' => $mine->id]);
    $plan = app(MealPlanningService::class)->create($mine, $myRecipe, ['mode' => 'someday', 'person_ids' => [$foreignPerson->id, $me->id]], $user);
    expect($plan->people->pluck('id')->all())->toBe([$me->id]);

    expect(fn () => app(PreferenceService::class)->set($foreignPerson, $myRecipe, Preference::Favorite))
        ->toThrow(InvalidArgumentException::class);

    Livewire::test(PlanPanel::class)->call('openFor', $foreignRecipe->id)->assertNotFound();
});
