<?php

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\LaunchCheckStatus;
use App\Enums\UsageKind;
use App\Livewire\AiImageAssistant;
use App\Models\AdminAudit;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Models\UsageGrant;
use App\Services\Admin\Compensations;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiImageService;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\AiUsageReport;
use App\Services\Ai\ImageProfile;
use App\Services\Ai\ImageProfileComparison;
use App\Services\ImageUploadService;
use App\Services\Launch\LaunchCheck;
use App\Services\Launch\LaunchReadiness;
use App\Services\Launch\LaunchSignoffs;
use App\Services\Usage\UsageLedger;
use Database\Seeders\AiCostRateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Image;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.image_model', 'gpt-image-2');
    $this->seed(AiCostRateSeeder::class);
});

function profilePng(): string
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);

    return base64_encode((string) ob_get_clean());
}

function profileRecipe(array $household, string $title = 'Paprikáš'): Recipe
{
    return Recipe::factory()->create(['household_id' => $household['household']->id, 'title' => $title, 'description' => 'Kuracie kúsky na paprike', 'side_requirement' => 'needs_side']);
}

/** @return array<string, LaunchCheck> */
function profileLaunchChecks(): array
{
    $checks = [];
    foreach (app(LaunchReadiness::class)->checks() as $check) {
        $checks[$check->key] = $check;
    }

    return $checks;
}

it('snapshots the profile code on the job and a later change of the default never alters a queued job', function () {
    $h = household();
    $recipe = profileRecipe($h);
    Image::fake([profilePng()]);

    $job = app(AiImageService::class)->create($recipe, $h['user'], 'Kuracie kúsky na paprike so smotanovou omáčkou', 'auto');
    expect($job->status)->toBe(AiJobStatus::Queued)
        ->and($job->profile)->toBe(ImageProfile::StandardV1->snapshot())
        ->and($job->profile['code'])->toBe('image_standard_v1');

    app(AiSettings::class)->update(['image_profile' => ImageProfile::EconomyV1->value], $h['user'], 'test');
    expect(app(AiSettings::class)->defaultImageProfile())->toBe(ImageProfile::EconomyV1);

    app(AiImageService::class)->run($job);

    Image::assertGenerated(fn ($prompt) => $prompt->quality === 'medium' && $prompt->size === '1:1');
    expect($job->fresh()->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->fresh()->profile['code'])->toBe('image_standard_v1')
        ->and($job->fresh()->estimated_cost_micro_usd)->toBe(53_000)
        ->and($job->fresh()->usageReservation->grant->kind)->toBe(UsageKind::ImageStandard);
});

it('derives the profile on the server from the entitlements: Economy never spends a Standard grant and vice versa', function () {
    $h = household();
    $recipe = profileRecipe($h);
    $ledger = app(UsageLedger::class);
    $availability = app(AiAvailability::class);
    $service = app(AiImageService::class);
    Image::fake(array_fill(0, 6, profilePng()));

    // Default Standard, the trial holds one Standard image: the job is Standard and spends it.
    expect($availability->imageProfileFor($h['household']))->toBe(ImageProfile::StandardV1);
    $first = $service->request($recipe, $h['user'], 'Kuracie kúsky na paprike', 'auto');
    expect($first->profile['code'])->toBe('image_standard_v1')
        ->and($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(0)
        ->and($ledger->available($h['household'], UsageKind::ImageEconomy))->toBe(0);

    // Nothing left of any kind: the exhausted message names the default profile's kind, no job is created.
    expect(fn () => $service->request($recipe->fresh(), $h['user'], 'Kuracie kúsky na paprike', 'auto', variant: true))
        ->toThrow(AiUnavailableException::class, 'obrázky Standard');
    expect(AiJob::count())->toBe(1);

    // The usage page lists Economy only once the household holds such a grant.
    $this->get('/settings/usage')->assertOk()->assertSee('Obrázky Standard')->assertDontSee('Obrázky Economy');

    // An Economy grant (no Standard left): the server switches to the Economy profile, low quality goes to the API.
    app(Compensations::class)->grantUses($h['household'], UsageKind::ImageEconomy, 2, 'test economy', null, 'eco-1', $h['user']);
    $this->get('/settings/usage')->assertOk()->assertSee('Obrázky Economy');
    expect($availability->imageProfileFor($h['household']))->toBe(ImageProfile::EconomyV1);
    $economy = $service->request($recipe->fresh(), $h['user'], 'Kuracie kúsky na paprike', 'auto', variant: true);
    expect($economy->profile['code'])->toBe('image_economy_v1')
        ->and($economy->status)->toBe(AiJobStatus::Succeeded)
        ->and($economy->estimated_cost_micro_usd)->toBe(6_000)
        ->and($economy->usageReservation->grant->kind)->toBe(UsageKind::ImageEconomy)
        ->and($ledger->available($h['household'], UsageKind::ImageEconomy))->toBe(1)
        ->and($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(0);
    Image::assertGenerated(fn ($prompt) => $prompt->quality === 'low');

    // Both available and Economy is the default: Economy is used, the Standard grant stays untouched.
    app(AiSettings::class)->update(['image_profile' => ImageProfile::EconomyV1->value], $h['user']);
    app(Compensations::class)->grantUses($h['household'], UsageKind::ImageStandard, 1, 'test standard', null, 'std-1', $h['user']);
    $second = $service->request($recipe->fresh(), $h['user'], 'Kuracie kúsky na paprike', 'auto', variant: true);
    expect($second->profile['code'])->toBe('image_economy_v1')
        ->and($ledger->available($h['household'], UsageKind::ImageEconomy))->toBe(0)
        ->and($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(1);

    // Economy exhausted: the Standard entitlement is spent only by the Standard profile.
    expect($availability->imageProfileFor($h['household']))->toBe(ImageProfile::StandardV1);
    $third = $service->request($recipe->fresh(), $h['user'], 'Kuracie kúsky na paprike', 'auto', variant: true);
    expect($third->profile['code'])->toBe('image_standard_v1')
        ->and($third->profile['quality'])->toBe('medium')
        ->and($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(0);
});

it('offers the client no way to choose a profile and shows the server-derived kind of use', function () {
    $h = household();
    $recipe = profileRecipe($h);
    Image::fake([profilePng()]);

    expect(array_keys(get_class_vars(AiImageAssistant::class)))->not->toContain('profile', 'quality', 'size');

    Livewire::test(AiImageAssistant::class, ['recipeId' => $recipe->id])
        ->set('open', true)
        ->assertSee('obrázok Standard')
        ->set('description', 'Kuracie kúsky na paprike so smotanou')
        ->call('generate')
        ->assertSet('error', '');

    expect(AiJob::sole()->profile['code'])->toBe('image_standard_v1');
});

it('keeps the stored originals and the serving rules whatever the profile', function () {
    $h = household();
    $recipe = profileRecipe($h);
    $own = app(ImageUploadService::class)->addCover($recipe, UploadedFile::fake()->image('own.jpg', 400, 300));
    app(Compensations::class)->grantUses($h['household'], UsageKind::ImageEconomy, 1, 'test', null, 'eco', $h['user']);
    app(AiSettings::class)->update(['image_profile' => ImageProfile::EconomyV1->value], $h['user']);
    Image::fake([profilePng()]);

    $service = app(AiImageService::class);
    $job = $service->request($recipe, $h['user'], 'Kuracie kúsky na paprike', 'auto');
    $media = $service->resultMedia($job);

    expect($job->profile['code'])->toBe('image_economy_v1')
        ->and($media->collection_name)->toBe(Recipe::COVER_COLLECTION)
        ->and($media->hasGeneratedConversion('card'))->toBeTrue()
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($recipe->fresh()->cover_media_id)->toBe($own->id);

    $service->approve($job);
    expect($recipe->fresh()->cover_media_id)->toBe($media->id);
    $this->get(route('media.show', [$media, 'card']))->assertOk();

    app(ImageUploadService::class)->activateCover($recipe->fresh(), $own);
    expect($recipe->fresh()->cover_media_id)->toBe($own->id);
});

it('reads a pre-v2.1 job without a code as Standard, keeps its stored quality when it runs and backfills the code', function () {
    $h = household();
    $recipe = profileRecipe($h);
    $legacy = AiJob::create([
        'household_id' => $h['household']->id,
        'recipe_id' => $recipe->id,
        'kind' => AiJobKind::Image,
        'status' => AiJobStatus::Queued,
        'request_key' => 'legacy',
        'provider' => 'openai',
        'model' => 'gpt-image-2',
        'profile' => ['quality' => 'low', 'size' => '1:1', 'pixel_size' => '1024x1024', 'count' => 1],
        'prompt_version' => '1',
        'prompt' => 'Paprikáš',
    ]);

    expect(ImageProfile::fromSnapshot($legacy->profile))->toBe(ImageProfile::StandardV1)
        ->and(ImageProfile::fromSnapshot(null))->toBe(ImageProfile::StandardV1);

    $rows = app(AiUsageReport::class)->byImageProfile(now()->subDay(), now()->addDay());
    expect($rows)->toHaveCount(1)->and($rows[0]->code)->toBe('image_standard_v1')->and($rows[0]->jobs)->toBe(1);

    $this->artisan('app:ai-backfill-image-profiles', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Dry run: 1 z 1');
    expect($legacy->fresh()->profile)->not->toHaveKey('code');

    $this->artisan('app:ai-backfill-image-profiles')->assertSuccessful()->expectsOutputToContain('1 z 1');
    expect($legacy->fresh()->profile['code'])->toBe('image_standard_v1')
        ->and($legacy->fresh()->profile['quality'])->toBe('low');
    $this->artisan('app:ai-backfill-image-profiles')->assertSuccessful()->expectsOutputToContain('0 z 1');

    // Running it sends what was snapshotted, never an upgrade.
    Image::fake([profilePng()]);
    app(AiImageService::class)->run($legacy->fresh());
    Image::assertGenerated(fn ($prompt) => $prompt->quality === 'low');
    expect($legacy->fresh()->status)->toBe(AiJobStatus::Succeeded);
});

it('runs the low/medium comparison on dedicated grants, lets the administrator rate it and records the decision as a sign-off', function () {
    $h = household();
    $comparison = app(ImageProfileComparison::class);
    Image::fake(array_fill(0, 40, profilePng()));

    expect($comparison->estimate())->toMatchArray(['total' => 20 * 6_000 + 20 * 53_000, 'model' => 'gpt-image-2']);
    expect(profileLaunchChecks()['signoff.image_profile']->status)->toBe(LaunchCheckStatus::Warn);

    $seen = 0;
    $record = $comparison->run($h['household'], $h['user'], function (AiJob $job) use (&$seen) {
        $seen++;
    });
    $run = $record['run'];

    $jobs = AiJob::query()->where('input->comparison_run', $run)->get();
    expect($seen)->toBe(40)
        ->and($jobs)->toHaveCount(40)
        ->and($jobs->filter(fn (AiJob $j) => $j->profile['code'] === 'image_economy_v1'))->toHaveCount(20)
        ->and($jobs->filter(fn (AiJob $j) => $j->profile['code'] === 'image_standard_v1'))->toHaveCount(20)
        ->and($jobs->where('status', AiJobStatus::Succeeded))->toHaveCount(40)
        ->and($jobs->pluck('input.comparison_dish')->unique())->toHaveCount(10)
        ->and($h['household']->recipes()->count())->toBe(10)
        ->and($h['household']->recipes()->whereNotNull('cover_media_id')->count())->toBe(0)
        ->and($jobs->sum('estimated_cost_micro_usd'))->toBe(20 * 6_000 + 20 * 53_000);

    // One compensation grant per kind, fully consumed; the trial stays untouched.
    $economyGrant = UsageGrant::query()->where('source_key', 'compensation:'.Str::slug("compare:{$run}:image_economy_v1"))->firstOrFail();
    $standardGrant = UsageGrant::query()->where('source_key', 'compensation:'.Str::slug("compare:{$run}:image_standard_v1"))->firstOrFail();
    expect($economyGrant->kind)->toBe(UsageKind::ImageEconomy)->and($economyGrant->quantity)->toBe(20)->and($economyGrant->consumed_quantity)->toBe(20)
        ->and($standardGrant->kind)->toBe(UsageKind::ImageStandard)->and($standardGrant->consumed_quantity)->toBe(20)
        ->and(UsageGrant::query()->where('source_key', 'like', 'trial:%')->sum('consumed_quantity'))->toBe(0)
        ->and(AdminAudit::query()->where('action', 'ai.image_comparison.completed')->exists())->toBeTrue();

    $summary = $comparison->summarize($run);
    expect($summary['profiles']['image_economy_v1']['succeeded'])->toBe(20)
        ->and($summary['profiles']['image_economy_v1']['avg_cost_micro'])->toBe(6_000)
        ->and($summary['dishes'])->toHaveCount(10)
        ->and($summary['criterion_met'])->toBeNull()
        ->and($comparison->runs()->first()['run'])->toBe($run);

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.ai.comparison', $run))->assertOk()->assertSee('Bryndzové halušky')->assertSee('ohodnoť všetky Economy');
    $this->get(route('admin.ai', ['days' => 7]))->assertOk()->assertSee($run)->assertSee('bez rozhodnutia');

    $economyJobs = $jobs->filter(fn (AiJob $j) => $j->profile['code'] === 'image_economy_v1')->values();
    $page = Livewire::test('pages::admin.ai-comparison', ['run' => $run]);
    foreach ($economyJobs as $index => $job) {
        $page->set("verdicts.{$job->id}", $index < 18 ? 'yes' : 'no')->set("notes.{$job->id}", $index < 18 ? '' : 'príloha navyše')->call('rate', $job->id);
    }
    expect($comparison->summarize($run)['profiles']['image_economy_v1'])->toMatchArray(['evaluated' => 20, 'acceptable' => 18])
        ->and($comparison->summarize($run)['criterion_met'])->toBeTrue();

    // A job of another run cannot be rated here.
    $foreignJob = AiJob::create([
        'household_id' => $h['household']->id, 'recipe_id' => $h['household']->recipes()->first()->id, 'kind' => AiJobKind::Image,
        'status' => AiJobStatus::Failed, 'request_key' => 'other', 'prompt_version' => '1', 'input' => ['comparison_run' => 'other'],
    ]);
    $page->set("verdicts.{$foreignJob->id}", 'yes')->call('rate', $foreignJob->id)->assertHasErrors(['verdicts.'.$foreignJob->id]);

    $page->set('decision', 'image_economy_v1')->set('decision_note', 'bez systematických zámen')->call('decide')->assertHasNoErrors();

    $signoff = app(LaunchSignoffs::class)->get('image_profile');
    expect($signoff['by'])->toBe($admin->id)
        ->and($signoff['note'])->toContain('Economy prijateľných 18/20')->toContain('kritérium ≥ 18/20 low splnené')->toContain('image_economy_v1')->toContain('bez systematických zámen')
        ->and($comparison->record($run)['decision']['profile'])->toBe('image_economy_v1')
        ->and(profileLaunchChecks()['signoff.image_profile']->status)->toBe(LaunchCheckStatus::Ok);

    // The rating page serves only images of this run.
    $mediaId = $economyJobs->first()->result_media_id;
    $this->get(route('admin.ai.comparison.media', [$run, $mediaId, 'card']))->assertOk();
    $this->get(route('admin.ai.comparison.media', ['other-run', $mediaId, 'card']))->assertNotFound();
    $foreignMedia = app(ImageUploadService::class)->addCover(Recipe::factory()->create(['household_id' => $h['household']->id]), UploadedFile::fake()->image('own.jpg', 400, 300));
    $this->get(route('admin.ai.comparison.media', [$run, $foreignMedia->id, 'card']))->assertNotFound();

    $this->artisan('app:ai-compare-images', ['--report' => $run])->assertSuccessful()->expectsOutputToContain('Porovnanie '.$run)->expectsOutputToContain('18/20');
});

it('shows the estimate first and runs the comparison only after an explicit confirmation', function () {
    $h = household();

    $this->artisan('app:ai-compare-images', ['household' => $h['household']->id])
        ->expectsOutputToContain('Odhad nákladu')
        ->expectsConfirmation('Spustiť 40 obrázkových úloh na reálnom kľúči? Volá to platené API.', 'no')
        ->assertSuccessful();
    expect(AiJob::count())->toBe(0)->and(UsageGrant::query()->where('source_key', 'like', 'compensation:compare-%')->count())->toBe(0);

    config()->set('ai.providers.openai.key', null);
    $this->artisan('app:ai-compare-images', ['household' => $h['household']->id, '--yes' => true])->assertFailed()->expectsOutputToContain('API kľúč');

    config()->set('ai.providers.openai.key', 'test-key');
    Image::fake(array_fill(0, 40, profilePng()));
    $this->artisan('app:ai-compare-images', ['household' => $h['household']->id, '--yes' => true])->assertSuccessful()->expectsOutputToContain('Hodnotenie');
    expect(AiJob::query()->where('status', AiJobStatus::Succeeded)->count())->toBe(40);
});
