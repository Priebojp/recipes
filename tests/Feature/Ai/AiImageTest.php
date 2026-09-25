<?php

use App\Enums\AiJobStatus;
use App\Livewire\AiImageAssistant;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Services\Ai\AiImageService;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Image;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
});

function pngBase64(): string
{
    $image = imagecreatetruecolor(8, 6);
    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

it('generates an image as an alternative, keeps the existing photo and activates only after approval', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id, 'title' => 'Paprikáš', 'description' => 'Kuracie kúsky na paprike', 'side_requirement' => 'needs_side']);
    $original = app(ImageUploadService::class)->addCover($recipe, UploadedFile::fake()->image('own.jpg', 400, 300));
    Image::fake([pngBase64()]);

    $service = app(AiImageService::class);
    $job = $service->request($recipe, $h['user'], 'Kuracie kúsky na paprike so smotanovou omáčkou', 'auto');

    Image::assertGenerated(fn ($prompt) => str_contains($prompt->prompt, 'v hrnci') || str_contains($prompt->prompt, 'v kastróle'));

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->result_media_id)->not->toBeNull()
        ->and($recipe->fresh()->cover_media_id)->toBe($original->id)
        ->and($service->resultMedia($job)->getCustomProperty('origin'))->toBe('ai');

    // A repeated request with identical inputs does not start another paid generation.
    expect($service->request($recipe->fresh(), $h['user'], 'Kuracie kúsky na paprike so smotanovou omáčkou', 'auto')->id)->toBe($job->id)
        ->and(AiJob::count())->toBe(1);

    $service->approve($job);
    expect($recipe->fresh()->cover_media_id)->toBe($job->result_media_id)
        ->and($recipe->fresh()->coverIsAi())->toBeTrue();

    // The user's own photo stays restorable.
    app(ImageUploadService::class)->activateCover($recipe->fresh(), $original);
    expect($recipe->fresh()->cover_media_id)->toBe($original->id);
});

it('asks for a description for an ambiguous title-only recipe', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id, 'title' => 'Babkina dobrota']);
    Image::fake();

    expect(fn () => app(AiImageService::class)->request($recipe, $h['user'], '', 'auto'))->toThrow(InvalidArgumentException::class);
    Image::assertNothingGenerated();

    Livewire::test(AiImageAssistant::class, ['recipeId' => $recipe->id])
        ->set('open', true)
        ->assertSee('Doplň krátky opis');
});

it('leaves the recipe usable when generation fails and an explicit new variant is a new job', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id, 'title' => 'Rizoto', 'description' => 'Hríbové rizoto', 'side_requirement' => 'complete']);
    Image::fake([fn () => throw new RuntimeException('provider timeout')]);

    $job = app(AiImageService::class)->request($recipe, $h['user'], 'Hríbové rizoto', 'auto');
    expect($job->status)->toBe(AiJobStatus::Failed)->and($recipe->fresh()->cover_media_id)->toBeNull();
    $this->get(route('recipes.show', $recipe))->assertOk();

    Image::fake([pngBase64()]);
    $variant = app(AiImageService::class)->request($recipe->fresh(), $h['user'], 'Hríbové rizoto', 'auto', variant: true);
    expect($variant->id)->not->toBe($job->id)->and($variant->status)->toBe(AiJobStatus::Succeeded);
});
