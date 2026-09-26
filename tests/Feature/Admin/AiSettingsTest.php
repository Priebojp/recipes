<?php

use App\Ai\Agents\RecipeTextAgent;
use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Models\AdminAudit;
use App\Models\AiCostRate;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiImageService;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiTextService;
use App\Services\Ai\AiUnavailableException;
use App\Services\RecipeService;
use Database\Seeders\AiCostRateSeeder;
use Laravel\Ai\Image;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
    config()->set('recipes.ai.image_model', 'gpt-image-2');
});

function settingsRecipe(array $household): Recipe
{
    $recipe = Recipe::factory()->create(['household_id' => $household['household']->id, 'title' => 'gulas', 'description' => 'hovadzi gulas']);

    return app(RecipeService::class)->update($recipe, $household['user'], [
        'ingredients' => [['name' => 'hovädzie', 'amount' => '500', 'unit' => 'g']],
        'steps' => [['text' => 'dusiť']],
    ]);
}

function settingsPng(): string
{
    $image = imagecreatetruecolor(8, 6);
    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

function suggestionFor(Recipe $recipe): array
{
    return [
        'suggested_title' => 'Guláš',
        'suggested_description' => 'Hovädzí guláš',
        'ingredients' => [['source_id' => $recipe->ingredients->first()->id, 'name' => 'hovädzie mäso', 'amount' => '500', 'unit' => 'g', 'note' => null]],
        'steps' => [['source_id' => $recipe->steps->first()->id, 'text' => 'Dusíme.']],
        'questions' => [],
        'change_summary' => ['Diakritika'],
    ];
}

it('falls back to the .env configuration and lets the administrator override it with an audit trail', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $settings = app(AiSettings::class);

    expect($settings->textModel())->toBe('gpt-6-luna')
        ->and($settings->textReasoningEffort())->toBe('low')
        ->and($settings->imageQuality())->toBe('medium')
        ->and($settings->imagePixelSize())->toBe('1024x1024')
        ->and($settings->enabled())->toBeTrue();

    $settings->update(['text_reasoning_effort' => 'medium', 'image_quality' => 'low', 'monthly_budget_micro_usd' => 5_000_000], $admin, 'test');

    expect($settings->textReasoningEffort())->toBe('medium')
        ->and($settings->imageQuality())->toBe('low')
        ->and($settings->monthlyBudgetMicroUsd())->toBe(5_000_000);

    $audit = AdminAudit::query()->where('action', 'ai.settings.updated')->firstOrFail();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->changes['before']['text_reasoning_effort'])->toBe('low')
        ->and($audit->changes['after']['text_reasoning_effort'])->toBe('medium')
        ->and($audit->reason)->toBe('test');

    // Unchanged values do not produce audit noise.
    $settings->update(['text_reasoning_effort' => 'medium'], $admin);
    expect(AdminAudit::query()->where('action', 'ai.settings.updated')->count())->toBe(1);

    // Reset removes the overrides.
    $settings->update(array_fill_keys(array_keys(AiSettings::KEYS), null), $admin);
    expect($settings->textReasoningEffort())->toBe('low')->and($settings->imageQuality())->toBe('medium');
});

it('stops new jobs with the kill switch and keeps manual editing working', function () {
    $h = household();
    $recipe = settingsRecipe($h);
    app(AiSettings::class)->update(['enabled' => false], $h['user']);

    expect(app(AiAvailability::class)->reasonUnavailable($h['household'], AiJobKind::Text))->toContain('dočasne nedostupné');
    expect(fn () => app(AiTextService::class)->request($recipe, $h['user'], 'description'))->toThrow(AiUnavailableException::class);
    expect(app(RecipeService::class)->update($recipe->fresh(), $h['user'], ['title' => 'Ručne'])->title)->toBe('Ručne');
});

it('snapshots the model and reasoning effort on the text job and records usage and cost', function () {
    $this->seed(AiCostRateSeeder::class);
    $h = household();
    $recipe = settingsRecipe($h);
    app(AiSettings::class)->update(['text_reasoning_effort' => 'medium'], $h['user']);
    RecipeTextAgent::fake([suggestionFor($recipe)]);

    $job = app(AiTextService::class)->request($recipe, $h['user'], 'full');

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->model)->toBe('gpt-6-luna')
        ->and($job->profile)->toBe(['reasoning_effort' => 'medium'])
        ->and($job->input_tokens)->toBe(0)
        ->and($job->output_tokens)->toBe(0)
        ->and($job->estimated_cost_micro_usd)->toBe(0)
        ->and($job->cost_rate_id)->not->toBeNull()
        ->and($job->duration_ms)->not->toBeNull()
        ->and($job->costRate->model)->toBe('gpt-6-luna');
});

it('snapshots quality and size on the image job so a later change never upgrades a queued job', function () {
    $this->seed(AiCostRateSeeder::class);
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id, 'title' => 'Paprikáš', 'description' => 'Kuracie kúsky na paprike', 'side_requirement' => 'needs_side']);
    Image::fake([settingsPng()]);

    $job = app(AiImageService::class)->request($recipe, $h['user'], 'Kuracie kúsky na paprike so smotanovou omáčkou', 'auto');

    expect($job->profile['quality'])->toBe('medium')
        ->and($job->profile['size'])->toBe('1:1')
        ->and($job->profile['pixel_size'])->toBe('1024x1024')
        ->and($job->model)->toBe('gpt-image-2');

    Image::assertGenerated(fn ($prompt) => $prompt->quality === 'medium' && $prompt->size === '1:1');

    // Flat Standard price from the catalogue: 0,053 USD per delivered 1024x1024 medium image (fake usage reports no tokens).
    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->estimated_cost_micro_usd)->toBe(53_000)
        ->and($job->costRate?->quality)->toBe('medium');
});

it('sends the reasoning effort only to OpenAI', function () {
    $agent = (new RecipeTextAgent)->withReasoningEffort('medium');

    expect($agent->providerOptions('openai'))->toBe(['reasoning' => ['effort' => 'medium']])
        ->and($agent->providerOptions(\Laravel\Ai\Enums\Lab::OpenAI))->toBe(['reasoning' => ['effort' => 'medium']])
        ->and($agent->providerOptions('anthropic'))->toBe([])
        ->and((new RecipeTextAgent)->withReasoningEffort('default')->providerOptions('openai'))->toBe([])
        ->and((new RecipeTextAgent)->withReasoningEffort('bogus')->reasoningEffort())->toBeNull();
});

it('saves the settings form and adds cost rates from the admin pages', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin);

    Livewire::test('pages::admin.ai-settings')
        ->assertSet('text_reasoning_effort', 'low')
        ->set('text_reasoning_effort', 'medium')
        ->set('image_quality', 'high')
        ->set('monthly_budget_usd', '2,50')
        ->set('reason', 'skúška kvality')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(AiSettings::class);
    expect($settings->textReasoningEffort())->toBe('medium')
        ->and($settings->imageQuality())->toBe('high')
        ->and($settings->monthlyBudgetMicroUsd())->toBe(2_500_000)
        ->and(AdminAudit::query()->where('action', 'ai.settings.updated')->where('reason', 'skúška kvality')->exists())->toBeTrue();

    Livewire::test('pages::admin.ai-settings')
        ->set('monthly_budget_usd', 'veľa')
        ->call('save')
        ->assertHasErrors(['monthly_budget_usd']);

    Livewire::test('pages::admin.ai-rates')
        ->set('provider', 'openai')
        ->set('model', 'gpt-6-luna')
        ->set('modality', 'text')
        ->set('input_usd', '0,10')
        ->set('output_usd', '0.50')
        ->set('effective_from', '2026-10-01')
        ->call('save')
        ->assertHasNoErrors();

    $rate = AiCostRate::query()->where('model', 'gpt-6-luna')->firstOrFail();
    expect($rate->input_per_million)->toBe(100_000)
        ->and($rate->output_per_million)->toBe(500_000)
        ->and($rate->created_by)->toBe($admin->id)
        ->and(AdminAudit::query()->where('action', 'ai.rate.created')->exists())->toBeTrue();
});
