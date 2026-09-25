<?php

use App\Ai\Agents\RecipeTextAgent;
use App\Enums\AiJobStatus;
use App\Livewire\AiTextAssistant;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Services\Ai\AiConflictException;
use App\Services\Ai\AiTextService;
use App\Services\Ai\AiUnavailableException;
use App\Services\ImageUploadService;
use App\Services\RecipeService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
});

function aiRecipe(array $household): Recipe
{
    $recipe = Recipe::factory()->create(['household_id' => $household['household']->id, 'title' => 'gulas', 'description' => 'hovadzi gulas s cibulou']);

    return app(RecipeService::class)->update($recipe, $household['user'], [
        'ingredients' => [['name' => 'hovädzie', 'amount' => '500', 'unit' => 'g'], ['name' => 'cibuľa', 'amount' => '2']],
        'steps' => [['text' => 'opražiť cibuľu'], ['text' => 'pridať mäso a dusiť']],
    ]);
}

function fakeSuggestion(Recipe $recipe, array $overrides = []): array
{
    [$i1, $i2] = $recipe->ingredients->pluck('id')->all();
    [$s1, $s2] = $recipe->steps->pluck('id')->all();

    return array_merge([
        'suggested_title' => 'Guláš',
        'suggested_description' => 'Hovädzí guláš s cibuľou',
        'ingredients' => [
            ['source_id' => $i1, 'name' => 'hovädzie mäso', 'amount' => '500', 'unit' => 'g', 'note' => null],
            ['source_id' => $i2, 'name' => 'cibuľa', 'amount' => '2', 'unit' => 'ks', 'note' => null],
        ],
        'steps' => [
            ['source_id' => $s1, 'text' => 'Opražíme cibuľu.'],
            ['source_id' => $s2, 'text' => 'Pridáme mäso a dusíme.'],
        ],
        'questions' => ['Pri akej teplote sa dusí?'],
        'change_summary' => ['Opravená diakritika'],
    ], $overrides);
}

it('is unavailable without an API key and never blocks saving the recipe', function () {
    config()->set('ai.providers.openai.key', null);
    $h = household();
    $recipe = aiRecipe($h);

    expect(fn () => app(AiTextService::class)->request($recipe, $h['user'], 'description'))->toThrow(AiUnavailableException::class);

    Livewire::test(AiTextAssistant::class, ['recipeId' => $recipe->id])
        ->assertSee('nie je nakonfigurované')
        ->call('request')
        ->assertSet('jobId', null);

    $this->get(route('recipes.edit', $recipe))->assertOk();
    expect(app(RecipeService::class)->update($recipe, $h['user'], ['title' => 'Stále ide uložiť'])->title)->toBe('Stále ide uložiť');
});

it('stores a suggestion as a job and does not change the original until accepted', function () {
    $h = household();
    $recipe = aiRecipe($h);
    RecipeTextAgent::fake([fakeSuggestion($recipe)]);

    $job = app(AiTextService::class)->request($recipe, $h['user'], 'full');

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->output['suggested_title'])->toBe('Guláš')
        ->and($job->output['questions'])->toBe(['Pri akej teplote sa dusí?'])
        ->and($job->input['recipe'])->not->toHaveKey('household')
        ->and($job->prompt)->not->toContain($h['person']->name)
        ->and($recipe->fresh()->title)->toBe('gulas');

    // A retry with the same revision and scope reuses the job instead of paying twice.
    expect(app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'full')->id)->toBe($job->id);

    $applied = app(AiTextService::class)->apply($job, ['title', 'description', 'ingredients', 'steps'], $h['user']);

    expect($applied->title)->toBe('Guláš')
        ->and($applied->description)->toBe('Hovädzí guláš s cibuľou')
        ->and($applied->ingredients->pluck('name')->all())->toBe(['hovädzie mäso', 'cibuľa'])
        ->and($applied->steps->pluck('text')->all())->toBe(['Opražíme cibuľu.', 'Pridáme mäso a dusíme.'])
        ->and($applied->revisions->first()->source)->toBe('ai')
        ->and($applied->revisions->last()->snapshot['title'])->toBe('gulas')
        ->and($job->fresh()->applied_at)->not->toBeNull();
});

it('applies only the selected fields', function () {
    $h = household();
    $recipe = aiRecipe($h);
    RecipeTextAgent::fake([fakeSuggestion($recipe)]);
    $job = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    $applied = app(AiTextService::class)->apply($job, ['description'], $h['user']);

    expect($applied->title)->toBe('gulas')->and($applied->description)->toBe('Hovädzí guláš s cibuľou')->and($applied->steps->first()->text)->toBe('opražiť cibuľu');
});

it('shows a conflict instead of applying when the recipe changed after the job started', function () {
    $h = household();
    $recipe = aiRecipe($h);
    RecipeTextAgent::fake([fakeSuggestion($recipe)]);
    $job = app(AiTextService::class)->request($recipe, $h['user'], 'full');

    app(RecipeService::class)->update($recipe->fresh(), $h['user'], ['title' => 'Medzitým zmenené']);

    expect(fn () => app(AiTextService::class)->apply($job, ['title'], $h['user']))->toThrow(AiConflictException::class)
        ->and($recipe->fresh()->title)->toBe('Medzitým zmenené');

    Livewire::test(AiTextAssistant::class, ['recipeId' => $recipe->id])
        ->assertSee('staršej verzie')
        ->call('apply')
        ->assertSet('error', fn ($e) => str_contains($e, 'medzitým'));
});

it('keeps step photos attached and requires confirmation when a photo step is merged', function () {
    $h = household();
    $recipe = aiRecipe($h);
    [$s1, $s2] = $recipe->steps->all();
    app(ImageUploadService::class)->addStepImage($s2, UploadedFile::fake()->image('step.jpg', 200, 200));

    RecipeTextAgent::fake([fakeSuggestion($recipe, ['steps' => [['source_id' => $s1->id, 'text' => 'Opražíme cibuľu, pridáme mäso a dusíme.']]])]);
    $job = app(AiTextService::class)->request($recipe, $h['user'], 'steps');

    expect(app(AiTextService::class)->stepsNeedingPhotoConfirmation($job))->toBe([$s2->id]);
    expect(fn () => app(AiTextService::class)->apply($job, ['steps'], $h['user']))->toThrow(InvalidArgumentException::class);

    $applied = app(AiTextService::class)->apply($job, ['steps'], $h['user'], confirmPhotoAssignment: true);

    expect($applied->steps)->toHaveCount(1)
        ->and($applied->steps->first()->id)->toBe($s1->id)
        ->and($applied->steps->first()->getMedia(RecipeStep::IMAGES_COLLECTION))->toHaveCount(1);
});

it('records a failure without touching the recipe and allows an explicit retry with a new key', function () {
    $h = household();
    $recipe = aiRecipe($h);
    RecipeTextAgent::fake([fn () => throw new RuntimeException('timeout')]);

    $job = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    expect($job->status)->toBe(AiJobStatus::Failed)->and($job->error)->toContain('timeout')->and($recipe->fresh()->description)->toBe('hovadzi gulas s cibulou');

    RecipeTextAgent::fake([fakeSuggestion($recipe)]);
    $retry = app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'description', fresh: true);
    expect($retry->id)->not->toBe($job->id)->and($retry->status)->toBe(AiJobStatus::Succeeded)->and(AiJob::count())->toBe(2);
});

it('respects the daily household limit', function () {
    config()->set('recipes.ai.daily_text_limit', 1);
    $h = household();
    $recipe = aiRecipe($h);
    RecipeTextAgent::fake([fakeSuggestion($recipe), fakeSuggestion($recipe)]);

    app(AiTextService::class)->request($recipe, $h['user'], 'description');
    expect(fn () => app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'steps'))->toThrow(AiUnavailableException::class);
});
