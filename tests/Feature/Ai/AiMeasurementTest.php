<?php

use App\Ai\Agents\RecipeTextAgent;
use App\Enums\AiJobStatus;
use App\Enums\UsageKind;
use App\Models\AdminAudit;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Models\UsageGrant;
use App\Services\Admin\AppSettings;
use App\Services\Ai\AiMeasurement;
use App\Services\Launch\LaunchReadiness;
use App\Services\RecipeService;
use Database\Seeders\AiCostRateSeeder;
use Illuminate\Support\Str;
use Laravel\Ai\Image;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
    config()->set('recipes.ai.image_model', 'gpt-image-2');
    $this->seed(AiCostRateSeeder::class);
});

function measurementRecipe(array $household, string $title): Recipe
{
    $recipe = Recipe::factory()->create(['household_id' => $household['household']->id, 'title' => $title, 'description' => 'domáce jedlo s cibuľou']);

    return app(RecipeService::class)->update($recipe, $household['user'], [
        'ingredients' => [['name' => 'cibuľa', 'amount' => '2']],
        'steps' => [['text' => 'opražiť cibuľu']],
    ]);
}

function measurementSuggestion(): array
{
    return ['suggested_title' => 'Jedlo', 'suggested_description' => 'Opis', 'ingredients' => [['name' => 'cibuľa', 'amount' => '2', 'unit' => 'ks']], 'steps' => [['text' => 'Opražíme cibuľu.']], 'questions' => [], 'change_summary' => ['diakritika']];
}

function measurementPng(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

it('runs the measured jobs against a dedicated grant, ignores the daily caps and stores the summary for the launch checklist', function () {
    $h = household();
    measurementRecipe($h, 'Guláš');
    measurementRecipe($h, 'Halušky');
    config()->set('recipes.ai.daily_text_limit', 1);
    config()->set('recipes.ai.daily_image_limit', 1);
    RecipeTextAgent::fake([measurementSuggestion(), measurementSuggestion(), measurementSuggestion()]);
    Image::fake([measurementPng(), measurementPng()]);

    $seen = [];
    $summary = app(AiMeasurement::class)->run($h['household'], 3, 2, 'full', function (AiJob $job) use (&$seen) {
        $seen[] = $job->status;
    });

    expect($seen)->toHaveCount(5)->each->toBe(AiJobStatus::Succeeded);
    $jobs = AiJob::query()->where('input->measurement_run', $summary['run'])->get();
    expect($jobs)->toHaveCount(5)
        ->and($jobs->where('kind', 'text')->count())->toBe(3)
        ->and($jobs->pluck('recipe_id')->unique())->toHaveCount(2)
        ->and($summary['kinds']['text']['succeeded'])->toBe(3)
        ->and($summary['kinds']['image']['succeeded'])->toBe(2)
        ->and($summary['kinds']['text']['model'])->toBe('gpt-6-luna')
        ->and($summary['projection']['plus_month_micro'])->not->toBeNull()
        ->and($summary['total_cost_micro'])->toBeGreaterThanOrEqual(0);

    // Uses came from the measurement's own compensation grants (never the trial) and are consumed there.
    $textGrant = UsageGrant::query()->where('source_key', 'compensation:'.Str::slug("measure:{$summary['run']}:text"))->firstOrFail();
    $imageGrant = UsageGrant::query()->where('source_key', 'compensation:'.Str::slug("measure:{$summary['run']}:image"))->firstOrFail();
    expect($textGrant->kind)->toBe(UsageKind::Text)->and($textGrant->quantity)->toBe(3)->and($textGrant->consumed_quantity)->toBe(3)
        ->and($imageGrant->quantity)->toBe(2)->and($imageGrant->consumed_quantity)->toBe(2)
        ->and(UsageGrant::query()->where('household_id', $h['household']->id)->where('source_key', 'like', 'trial:%')->sum('consumed_quantity'))->toBe(0);

    expect(app(AppSettings::class)->get(LaunchReadiness::MEASUREMENT_KEY)['run'])->toBe($summary['run'])
        ->and(app(LaunchReadiness::class)->measurement()['kinds']['image']['jobs'])->toBe(2)
        ->and(AdminAudit::query()->where('action', 'ai.measurement.completed')->exists())->toBeTrue();

    $this->artisan('app:ai-measure', ['--report' => $summary['run']])->assertSuccessful()->expectsOutputToContain('Meranie '.$summary['run']);
});

it('refuses to run without recipes or a provider key and runs from the command with an explicit confirmation', function () {
    $h = household();
    expect(fn () => app(AiMeasurement::class)->run($h['household'], 1, 0))->toThrow(InvalidArgumentException::class, 'nemá recepty');

    measurementRecipe($h, 'Guláš');
    config()->set('ai.providers.openai.key', null);
    expect(fn () => app(AiMeasurement::class)->run($h['household'], 1, 0))->toThrow(InvalidArgumentException::class, 'API kľúč');
    config()->set('ai.providers.openai.key', 'test-key');

    RecipeTextAgent::fake([measurementSuggestion()]);
    $this->artisan('app:ai-measure', ['household' => $h['household']->id, '--text' => 1, '--images' => 0])->expectsConfirmation("Spustiť 1 textových a 0 obrázkových úloh na reálnom kľúči pre domácnosť #{$h['household']->id} „{$h['household']->name}“? Volá to platené API.", 'no')->assertSuccessful();
    expect(AiJob::query()->count())->toBe(0);

    $this->artisan('app:ai-measure', ['household' => $h['household']->id, '--text' => 1, '--images' => 0, '--yes' => true])->assertSuccessful()->expectsOutputToContain('Plný mesiac Plus');
    expect(AiJob::query()->count())->toBe(1);
});
