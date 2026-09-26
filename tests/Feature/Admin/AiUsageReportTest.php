<?php

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Models\AiCostRate;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Services\Ai\AiCostCalculator;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReport;
use Carbon\CarbonImmutable;
use Database\Seeders\AiCostRateSeeder;

function usageJob(array $h, Recipe $recipe, array $attributes = []): AiJob
{
    return AiJob::create(array_merge([
        'household_id' => $h['household']->id,
        'recipe_id' => $recipe->id,
        'kind' => AiJobKind::Text,
        'status' => AiJobStatus::Succeeded,
        'request_key' => hash('sha256', (string) fake()->unique()->uuid()),
        'provider' => 'openai',
        'model' => 'gpt-6-luna',
        'prompt_version' => '1',
        'input_tokens' => 3000,
        'output_tokens' => 2000,
        'reasoning_tokens' => 800,
        'estimated_cost_micro_usd' => 1300,
    ], $attributes));
}

it('aggregates jobs, tokens and cost per period, model, household and day', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id]);
    $tz = config('recipes.default_timezone');
    $now = CarbonImmutable::now($tz);

    usageJob($h, $recipe);
    usageJob($h, $recipe, ['kind' => AiJobKind::Image, 'model' => 'gpt-image-2', 'input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => null, 'estimated_cost_micro_usd' => 53_000]);
    usageJob($h, $recipe, ['status' => AiJobStatus::Failed, 'input_tokens' => null, 'output_tokens' => null, 'reasoning_tokens' => null, 'estimated_cost_micro_usd' => null]);
    usageJob($h, $recipe, ['estimated_cost_micro_usd' => null]); // delivered but unpriced
    $old = usageJob($h, $recipe, ['estimated_cost_micro_usd' => 999_999]);
    $old->forceFill(['created_at' => $now->subDays(45)->utc()])->save();

    $report = app(AiUsageReport::class);
    $summary = $report->summary($now->subDays(29)->startOfDay(), $now->endOfDay());

    expect($summary['jobs'])->toBe(4)
        ->and($summary['succeeded'])->toBe(3)
        ->and($summary['failed'])->toBe(1)
        ->and($summary['input_tokens'])->toBe(6000)
        ->and($summary['reasoning_tokens'])->toBe(1600)
        ->and($summary['cost_micro'])->toBe(54_300)
        ->and($summary['unpriced'])->toBe(1);

    $byModel = $report->byModel($now->subDays(29)->startOfDay(), $now->endOfDay());
    expect($byModel->firstWhere('model', 'gpt-image-2')->cost_micro)->toBe(53_000)
        ->and($byModel->firstWhere('model', 'gpt-6-luna')->jobs)->toBe(3)
        ->and($byModel->firstWhere('model', 'gpt-6-luna')->avg_cost_micro)->toBe(650);

    $byHousehold = $report->byHousehold($now->subDays(29)->startOfDay(), $now->endOfDay());
    expect($byHousehold)->toHaveCount(1)
        ->and($byHousehold->first()->household_id)->toBe($h['household']->id)
        ->and($byHousehold->first()->text_jobs)->toBe(3)
        ->and($byHousehold->first()->image_jobs)->toBe(1);

    $daily = $report->daily($now->subDays(6)->startOfDay(), $now->endOfDay());
    expect($daily)->toHaveCount(7)
        ->and(end($daily)['date'])->toBe($now->toDateString())
        ->and(end($daily)['jobs'])->toBe(4)
        ->and(end($daily)['failed'])->toBe(1)
        ->and(end($daily)['cost_micro'])->toBe(54_300)
        ->and($daily[0]['jobs'])->toBe(0);

    $jobs = $report->recentJobs($now->subDays(29)->startOfDay(), $now->endOfDay(), kind: 'image');
    expect($jobs)->toHaveCount(1)->and($jobs->first()->getAttributes())->not->toHaveKey('prompt');
});

it('compares month-to-date spend with the soft budget', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id]);
    usageJob($h, $recipe, ['estimated_cost_micro_usd' => 3_000_000]);

    $report = app(AiUsageReport::class);
    expect($report->budget()['budget_micro'])->toBeNull()->and($report->budget()['exceeded'])->toBeFalse();

    app(AiSettings::class)->update(['monthly_budget_micro_usd' => 2_000_000], $h['user']);
    $budget = $report->budget();
    expect($budget['spent_micro'])->toBe(3_000_000)->and($budget['exceeded'])->toBeTrue()->and($budget['ratio'])->toBe(1.5);
});

it('picks the newest effective rate that is not in the future and the most specific image tier', function () {
    $this->seed(AiCostRateSeeder::class);
    $calc = app(AiCostCalculator::class);

    $current = $calc->rateFor('openai', 'gpt-6-luna', 'text', null, null, now());
    expect($current)->not->toBeNull()->and($current->input_per_million)->toBe(100_000);

    AiCostRate::create(['provider' => 'openai', 'model' => 'gpt-6-luna', 'modality' => 'text', 'input_per_million' => 200_000, 'output_per_million' => 900_000, 'effective_from' => now()->addMonth()]);
    expect($calc->rateFor('openai', 'gpt-6-luna', 'text', null, null, now())->input_per_million)->toBe(100_000)
        ->and($calc->rateFor('openai', 'gpt-6-luna', 'text', null, null, now()->addMonths(2))->input_per_million)->toBe(200_000)
        ->and($calc->rateFor('openai', 'gpt-6-luna', 'text', null, null, CarbonImmutable::parse('2020-01-01')))->toBeNull()
        ->and($calc->rateFor('openai', null, 'text', null, null, now()))->toBeNull();

    expect($calc->rateFor('openai', 'gpt-image-2', 'image', 'medium', '1024x1024', now())->per_unit)->toBe(53_000)
        ->and($calc->rateFor('openai', 'gpt-image-2', 'image', 'high', '1536x1024', now())->per_unit)->toBe(165_000)
        ->and($calc->rateFor('openai', 'gpt-image-2', 'image', 'medium', '1024x1536', now()))->toBeNull();

    // A generic fallback (no quality/size) applies only when no specific tier matches.
    AiCostRate::create(['provider' => 'openai', 'model' => 'gpt-image-2', 'modality' => 'image', 'per_unit' => 70_000, 'effective_from' => now()->subYear()]);
    expect($calc->rateFor('openai', 'gpt-image-2', 'image', 'medium', '1024x1024', now())->per_unit)->toBe(53_000)
        ->and($calc->rateFor('openai', 'gpt-image-2', 'image', 'medium', '1024x1536', now())->per_unit)->toBe(70_000);
});
