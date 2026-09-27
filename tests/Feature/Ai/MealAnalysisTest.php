<?php

use App\Ai\Agents\MealAnalysisAgent;
use App\Ai\Agents\RecipeTextAgent;
use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Enums\MealAnalysisStatus;
use App\Enums\MealGramsOrigin;
use App\Enums\MembershipRole;
use App\Enums\UsageKind;
use App\Enums\UsageReservationState;
use App\Livewire\MealPhotoAnalyzer;
use App\Models\AiJob;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\HouseholdMembership;
use App\Models\MealAnalysis;
use App\Models\UsageGrant;
use App\Models\UsageReservation;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\MealAnalysisService;
use App\Services\Privacy\AccountErasure;
use App\Services\Usage\TrialGrants;
use App\Services\Usage\UsageLedger;
use App\Support\CurrentHousehold;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
});

/** A verified owner who already acknowledged the "photo goes to the provider" notice. */
function mealUser(): array
{
    $h = household();
    $h['user']->forceFill(['meal_photo_notice_accepted_at' => now()])->save();

    return $h;
}

/**
 * A JPEG with an EXIF APP1 segment (as a phone camera writes) so the test can prove it is gone after upload.
 */
function mealPhotoWithExif(int $width = 640, int $height = 480): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 120, 60));
    ob_start();
    imagejpeg($image, null, 80);
    $jpeg = (string) ob_get_clean();

    // Minimal little-endian TIFF header inside an APP1 "Exif" segment, inserted right after SOI.
    $tiff = "II*\x00\x08\x00\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $withExif = substr($jpeg, 0, 2).$segment.substr($jpeg, 2);

    $path = tempnam(sys_get_temp_dir(), 'meal').'.jpg';
    file_put_contents($path, $withExif);

    return new UploadedFile($path, 'obed.jpg', 'image/jpeg', null, true);
}

function mealFood(string $alias, array $nutrients, FoodPreparationState $state = FoodPreparationState::Cooked): FoodSourceRecord
{
    $record = FoodSourceRecord::factory()->create(array_merge(
        ['energy_kcal' => 0, 'energy_kj' => 0, 'protein_g' => 0, 'carbohydrate_g' => 0, 'fat_g' => 0, 'fiber_g' => 0],
        $nutrients,
        ['preparation_state' => $state, 'name_sk' => ucfirst($alias), 'name' => ucfirst($alias).', '.$state->value],
    ));
    FoodAlias::create(['food_source_record_id' => $record->id, 'alias' => $alias, 'normalized' => FoodAlias::normalize($alias), 'locale' => 'sk', 'preparation_state' => $state]);

    return $record;
}

function mealProposal(array $overrides = []): array
{
    return array_merge([
        'status' => 'recognized',
        'dish_name' => 'Kuracie s ryžou',
        'components' => [
            ['label' => 'ryža varená', 'is_unknown' => false, 'alternatives' => [], 'preparation_state' => 'cooked', 'estimated_grams' => 150, 'portion_basis' => 'bežná porcia', 'visible_evidence' => 'biela ryža na tanieri', 'assumptions' => []],
            ['label' => 'kuracie prsia', 'is_unknown' => false, 'alternatives' => ['kuracie stehno'], 'preparation_state' => 'cooked', 'estimated_grams' => 120, 'portion_basis' => 'jeden kus', 'visible_evidence' => 'grilované mäso', 'assumptions' => ['bez viditeľného oleja']],
        ],
        'questions' => [],
        'limitations' => ['Skrytý tuk nevidno.'],
    ], $overrides);
}

it('spends one meal-analysis use for a delivered proposal – never a text or image use – and sends only the photo and the note', function () {
    $h = mealUser();
    $rice = mealFood('ryža varená', ['energy_kcal' => 130, 'protein_g' => 2.7, 'carbohydrate_g' => 28, 'fat_g' => 0.3]);
    mealFood('kuracie prsia', ['energy_kcal' => 165, 'protein_g' => 31, 'carbohydrate_g' => 0, 'fat_g' => 3.6]);
    MealAnalysisAgent::fake([mealProposal()]);

    $analysis = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif(), 'kuracie s ryžou');

    expect($analysis->status)->toBe(MealAnalysisStatus::NeedsReview)
        ->and($analysis->dish_name)->toBe('Kuracie s ryžou')
        ->and($analysis->items)->toHaveCount(2)
        ->and($analysis->items[0]->food_source_record_id)->toBe($rice->id)
        ->and($analysis->items[0]->grams_origin)->toBe(MealGramsOrigin::Estimated)
        ->and((float) $analysis->items[0]->grams)->toBe(150.0)
        ->and($analysis->items[0]->mapping_status)->toBe(FoodMappingStatus::Suggested)
        ->and($analysis->photo_retain_until)->not->toBeNull()
        ->and($analysis->expires_at)->not->toBeNull();

    $job = $analysis->aiJob;
    expect($job->kind)->toBe(AiJobKind::MealAnalysis)
        ->and($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->recipe_id)->toBeNull()
        ->and($job->prompt)->toContain('kuracie s ryžou')
        ->and($job->prompt)->not->toContain($h['user']->name)
        ->and($job->prompt)->not->toContain($h['user']->email);

    MealAnalysisAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 1 && str_contains($prompt->prompt, 'kuracie s ryžou'));
    RecipeTextAgent::assertNeverPrompted();

    $ledger = app(UsageLedger::class);
    expect($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(2)
        ->and($ledger->available($h['household'], UsageKind::Text))->toBe(3)
        ->and($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(1)
        ->and(UsageReservation::query()->where('ai_job_id', $job->id)->sole()->state)->toBe(UsageReservationState::Consumed);
});

it('strips EXIF, shrinks the photo and serves it only to its owner', function () {
    $h = mealUser();
    MealAnalysisAgent::fake([mealProposal()]);

    $analysis = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif(2400, 1800));
    $media = $analysis->photo();
    $bytes = (string) file_get_contents($media->getPath());
    [$width, $height] = getimagesize($media->getPath());

    expect($media->disk)->toBe('local')
        ->and(str_contains($bytes, 'Exif'))->toBeFalse()
        ->and(max($width, $height))->toBeLessThanOrEqual(1536)
        ->and($media->mime_type)->toBe('image/jpeg');

    $this->get(route('meals.photo', $analysis))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    // A member of the same household gets nothing – not the photo, not the record.
    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $h['household']->id, 'user_id' => $member->id, 'role' => MembershipRole::Editor]);
    $this->actingAs($member);
    app(CurrentHousehold::class)->set($h['household']);
    $this->get(route('meals.photo', $analysis))->assertForbidden();
    Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealPhotoAnalyzer::class)->assertForbidden();

    auth()->logout();
    $this->get(route('meals.photo', $analysis))->assertRedirect(route('login'));
});

it('returns the use and shows "Nedokážem určiť" without any numbers when there is no food on the photo', function () {
    $h = mealUser();
    MealAnalysisAgent::fake([['status' => 'not_food', 'dish_name' => null, 'components' => [['label' => 'stolička', 'is_unknown' => false, 'alternatives' => [], 'preparation_state' => 'unknown', 'estimated_grams' => 300, 'portion_basis' => null, 'visible_evidence' => null, 'assumptions' => []]], 'questions' => [], 'limitations' => ['Na fotografii nie je jedlo.']]]);

    $analysis = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif());
    $job = $analysis->aiJob;

    expect($analysis->status)->toBe(MealAnalysisStatus::Unusable)
        ->and($analysis->items)->toHaveCount(0)
        ->and($analysis->nutrition)->toBeNull()
        ->and($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->output['components'])->toBe([])
        ->and($job->duration_ms)->not->toBeNull()
        ->and(UsageReservation::query()->where('ai_job_id', $job->id)->sole()->state)->toBe(UsageReservationState::Released)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::MealAnalysis))->toBe(3);

    Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealPhotoAnalyzer::class)
        ->assertSee('Nedokážem určiť')
        ->assertSee('Na fotografii nie je jedlo.')
        ->assertDontSee('kcal')
        ->assertSeeHtml('data-test="meal-new"');
});

it('keeps a single reservation across retries, returns it on a definitive failure and holds it on a timeout', function () {
    $h = mealUser();
    $service = app(MealAnalysisService::class);

    // Definitive provider error: the use goes back and the person may try again with a new use.
    MealAnalysisAgent::fake([fn () => throw new RuntimeException('invalid_request')]);
    $analysis = $service->request($h['user'], $h['household'], mealPhotoWithExif());
    $failed = $analysis->aiJob;
    expect($analysis->status)->toBe(MealAnalysisStatus::Uploaded)
        ->and($failed->status)->toBe(AiJobStatus::Failed)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::MealAnalysis))->toBe(3);

    MealAnalysisAgent::fake([mealProposal()]);
    $retry = $service->analyze($analysis->fresh());
    expect($retry->id)->not->toBe($failed->id)->and($retry->wasRecentlyCreated)->toBeTrue();
    $service->run($retry);
    expect($analysis->fresh()->status)->toBe(MealAnalysisStatus::NeedsReview)
        ->and($service->analyze($analysis->fresh())->id)->toBe($retry->id) // a double click reuses the delivered job
        ->and(UsageReservation::count())->toBe(2)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::MealAnalysis))->toBe(2);

    // Ambiguous outcome: the reservation is held, nothing is paid twice.
    MealAnalysisAgent::fake([fn () => throw new TimeoutExceededException('timed out')]);
    $held = $service->request($h['user'], $h['household'], mealPhotoWithExif());
    expect($held->status)->toBe(MealAnalysisStatus::Analyzing)
        ->and($held->aiJob->status)->toBe(AiJobStatus::Reconciling)
        ->and($service->analyze($held->fresh())->id)->toBe($held->ai_job_id)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::MealAnalysis))->toBe(1);

    Livewire::withQueryParams(['analyza' => $held->id])->test(MealPhotoAnalyzer::class)->assertSee('Výsledok sa overuje');
});

it('ignores identifiers and numbers from the model and only links foods the server found', function () {
    $h = mealUser();
    $rice = mealFood('ryža varená', ['energy_kcal' => 130]);
    $other = FoodSourceRecord::factory()->create(['name_sk' => 'Cudzia potravina']);
    MealAnalysisAgent::fake([mealProposal(['components' => [
        ['label' => 'ryža varená', 'is_unknown' => false, 'alternatives' => [], 'preparation_state' => 'cooked', 'estimated_grams' => 150, 'portion_basis' => null, 'visible_evidence' => null, 'assumptions' => [], 'food_id' => $other->id, 'kcal' => 999, 'confidence' => 0.97],
        ['label' => 'záhadná omáčka', 'is_unknown' => true, 'alternatives' => [], 'preparation_state' => 'unknown', 'estimated_grams' => null, 'portion_basis' => null, 'visible_evidence' => 'tmavá omáčka', 'assumptions' => []],
    ]])]);

    $analysis = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif());
    [$riceItem, $unknown] = $analysis->items->all();

    expect($riceItem->food_source_record_id)->toBe($rice->id)
        ->and($analysis->aiJob->output['components'][0])->not->toHaveKeys(['food_id', 'kcal', 'confidence'])
        ->and($unknown->is_unknown)->toBeTrue()
        ->and($unknown->food_source_record_id)->toBeNull()
        ->and($unknown->mapping_status)->toBe(FoodMappingStatus::Unresolved);

    // The browser cannot pick a record outside the server's candidates.
    $service = app(MealAnalysisService::class);
    expect(fn () => $service->chooseFood($riceItem, $other->id))->toThrow(InvalidArgumentException::class);
    Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealPhotoAnalyzer::class)
        ->set('choices.'.$riceItem->id, (string) $other->id)
        ->assertSet('error', fn ($e) => str_contains($e, 'nie je medzi návrhmi'));
    expect($riceItem->fresh()->food_source_record_id)->toBe($rice->id);

    // Unknown component: partial sum, never zero.
    $preview = $service->calculate($analysis->fresh());
    expect($preview->isPartial())->toBeTrue()->and($preview->missing)->toHaveCount(1)->and($preview->missing[0]['name'])->toBe('záhadná omáčka')
        ->and($preview->totals['energy_kcal'])->toBe(195.0);
});

it('lets the person correct components without AI and freezes a database calculation on confirmation', function () {
    $h = mealUser();
    mealFood('ryža varená', ['energy_kcal' => 130, 'protein_g' => 2.7, 'carbohydrate_g' => 28, 'fat_g' => 0.3]);
    mealFood('kuracie prsia', ['energy_kcal' => 165, 'protein_g' => 31, 'carbohydrate_g' => 0, 'fat_g' => 3.6]);
    $oil = mealFood('olej', ['energy_kcal' => 884, 'fat_g' => 100], FoodPreparationState::Raw);
    MealAnalysisAgent::fake([mealProposal()]);

    $analysis = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif());
    [$rice, $chicken] = $analysis->items->all();
    $jobsBefore = AiJob::count();

    $component = Livewire::withQueryParams(['analyza' => $analysis->id])->test(MealPhotoAnalyzer::class)
        ->assertSee('Skontroluj')
        ->assertSee('Kuracie s ryžou')
        ->assertSeeHtml('data-test="grams-origin-'.$rice->id.'"')
        ->assertSee('odhad')
        // Weighed rice replaces the estimate; the chicken estimate is confirmed as is.
        ->set('gramsInput.'.$rice->id, '180')->set('originInput.'.$rice->id, 'measured')->call('saveGrams', $rice->id)
        ->set('originInput.'.$chicken->id, 'confirmed')->call('saveGrams', $chicken->id)
        // An added component with a typed amount, and a removed nothing.
        ->set('newLabel', 'olej')->set('newGrams', '10')->call('addItem')
        ->assertHasNoErrors();

    $analysis->refresh();
    expect($analysis->items)->toHaveCount(3)
        ->and((float) $rice->fresh()->grams)->toBe(180.0)->and($rice->fresh()->grams_origin)->toBe(MealGramsOrigin::Measured)
        ->and($chicken->fresh()->grams_origin)->toBe(MealGramsOrigin::Confirmed)
        ->and($analysis->items->last()->food_source_record_id)->toBe($oil->id)
        ->and(AiJob::count())->toBe($jobsBefore);

    $component->set('keepPhoto', true)->call('confirm')->assertHasNoErrors()->assertSee('Potvrdené');

    $analysis->refresh();
    // 180 g rice (234) + 120 g chicken (198) + 10 g oil (88.4) = 520.4 kcal, every component with its source and grams.
    expect($analysis->status)->toBe(MealAnalysisStatus::Confirmed)
        ->and($analysis->nutrition['completeness'])->toBe('complete')
        ->and(round($analysis->nutrition['totals']['energy_kcal'], 1))->toBe(520.4)
        ->and($analysis->nutrition['components'])->toHaveCount(3)
        ->and($analysis->nutrition['components'][0]['source']['external_id'])->not->toBeEmpty()
        ->and($analysis->nutrition['components'][0]['grams_origin'])->toBe('user_entered')
        ->and($analysis->nutrition['assumptions'])->toBe([])
        ->and($analysis->expires_at)->toBeNull()
        ->and($analysis->photo_retain_until)->toBeNull()
        ->and($rice->fresh()->mapping_status)->toBe(FoodMappingStatus::Confirmed);

    $component->assertSeeHtml('data-test="meal-kcal"')->assertSee('520 kcal');

    // Saving without calories is always possible, and estimates stay marked as assumptions.
    MealAnalysisAgent::fake([mealProposal()]);
    $second = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif());
    $withEstimates = app(MealAnalysisService::class)->confirm($second, withNutrition: true);
    expect($withEstimates->nutrition['assumptions'])->toHaveCount(2)->and($withEstimates->nutrition['assumptions'][0])->toContain('odhad');

    MealAnalysisAgent::fake([mealProposal()]);
    $third = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif());
    Livewire::withQueryParams(['analyza' => $third->id])->test(MealPhotoAnalyzer::class)->call('confirm', false)->assertSee('bez kalórií');
    expect($third->fresh()->nutrition)->toBeNull()->and($third->fresh()->status)->toBe(MealAnalysisStatus::Confirmed);
});

it('answers at most two clarifications in the same session without a second use', function () {
    $h = mealUser();
    mealFood('ryža varená', ['energy_kcal' => 130]);
    MealAnalysisAgent::fake([
        mealProposal(['status' => 'needs_clarification', 'questions' => ['Je omáčka smotanová alebo paradajková?']]),
        mealProposal(['dish_name' => 'Kuracie na smotane s ryžou']),
        mealProposal(['dish_name' => 'Kuracie na smotane s ryžou a hráškom']),
    ]);
    $service = app(MealAnalysisService::class);

    $analysis = $service->request($h['user'], $h['household'], mealPhotoWithExif());
    expect($analysis->questions)->toBe(['Je omáčka smotanová alebo paradajková?']);

    $first = $service->clarify($analysis, 'smotanová');
    expect($first->parent_ai_job_id)->toBe($analysis->ai_job_id)
        ->and($first->status)->toBe(AiJobStatus::Succeeded)
        ->and($first->prompt)->toContain('smotanová')
        ->and(UsageReservation::query()->where('ai_job_id', $first->id)->exists())->toBeFalse()
        ->and($analysis->fresh()->dish_name)->toBe('Kuracie na smotane s ryžou')
        ->and($analysis->fresh()->clarification_count)->toBe(1)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::MealAnalysis))->toBe(2);

    $service->clarify($analysis->fresh(), 'aj hrášok');
    expect(fn () => $service->clarify($analysis->fresh(), 'a ešte'))->toThrow(InvalidArgumentException::class, 'max. 2');
    expect(AiJob::query()->where('parent_ai_job_id', $analysis->ai_job_id)->count())->toBe(2)
        ->and(UsageReservation::count())->toBe(1);
});

it('applies the kill switch, household blocking, the daily cap and the one-time trial', function () {
    $h = mealUser();
    $service = app(MealAnalysisService::class);
    MealAnalysisAgent::fake([mealProposal(), mealProposal(), mealProposal()]);

    app(AppSettings::class)->set('ai.enabled', false);
    expect(fn () => $service->request($h['user'], $h['household'], mealPhotoWithExif()))->toThrow(AiUnavailableException::class, 'dočasne nedostupné');
    app(AppSettings::class)->set('ai.enabled', true);

    $h['household']->forceFill(['blocked_at' => now(), 'blocked_reason' => 'test'])->save();
    expect(fn () => $service->request($h['user'], $h['household']->fresh(), mealPhotoWithExif()))->toThrow(AiUnavailableException::class, 'pozastavené');
    $h['household']->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();

    config()->set('recipes.ai.daily_meal_analysis_limit', 1);
    $service->request($h['user'], $h['household']->fresh(), mealPhotoWithExif());
    expect(fn () => $service->request($h['user'], $h['household']->fresh(), mealPhotoWithExif()))->toThrow(AiUnavailableException::class, 'Denný limit');
    config()->set('recipes.ai.daily_meal_analysis_limit', 10);

    // The trial is bound to the verified owner: a second household of the same person gets none, and a household
    // that already held the older text/image trial receives the meal trial exactly once through the backfill.
    $second = CurrentHousehold::createFor($h['user'], 'Druhá');
    expect(app(TrialGrants::class)->ensureFor($second))->toBe([])
        ->and(UsageGrant::query()->where('source_key', 'trial:meal_analysis:user:'.$h['user']->id)->value('quantity'))->toBe(3);

    $old = household();
    config()->set('recipes.usage.trial.meal_analysis', 0);
    app(TrialGrants::class)->ensureFor($old['household']);
    config()->set('recipes.usage.trial.meal_analysis', 3);
    expect(UsageGrant::query()->where('household_id', $old['household']->id)->count())->toBe(2)
        ->and(app(TrialGrants::class)->ensureFor($old['household']))->toHaveCount(1)
        ->and(app(TrialGrants::class)->ensureFor($old['household']))->toBe([])
        ->and(app(UsageLedger::class)->available($old['household'], UsageKind::MealAnalysis))->toBe(3);

    // Without any use left the button is disabled and the ledger message explains why.
    foreach (UsageGrant::query()->where('household_id', $old['household']->id)->get() as $grant) {
        app(UsageLedger::class)->revoke($grant, $grant->available(), 'test-revoke:'.$grant->id);
    }
    $old['user']->forceFill(['meal_photo_notice_accepted_at' => now()])->save();
    Livewire::test(MealPhotoAnalyzer::class)->assertSee('žiadne voľné AI použitia (analýzy jedla)');
});

it('requires the one-time notice before the first upload and validates the photo', function () {
    $h = household();
    MealAnalysisAgent::fake([mealProposal()]);

    Livewire::test(MealPhotoAnalyzer::class)
        ->assertSee('Kam ide fotka')
        ->assertDontSeeHtml('data-test="meal-analyze"')
        ->call('acceptNotice')
        ->assertSeeHtml('data-test="meal-analyze"')
        ->set('photo', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))
        ->call('analyze')
        ->assertHasErrors(['photo'])
        ->set('photo', UploadedFile::fake()->image('obed.jpg', 300, 200))
        ->set('note', 'halušky')
        ->call('analyze')
        ->assertHasNoErrors()
        ->assertSet('analysisId', fn ($id) => $id !== null)
        ->assertSee('Kuracie s ryžou');

    expect($h['user']->fresh()->meal_photo_notice_accepted_at)->not->toBeNull()
        ->and(MealAnalysis::query()->where('user_id', $h['user']->id)->count())->toBe(1);
});

it('deletes working photos after their TTL, keeps kept ones, and removes unfinished drafts after the draft TTL', function () {
    $h = mealUser();
    MealAnalysisAgent::fake([mealProposal(), mealProposal(), mealProposal()]);
    $service = app(MealAnalysisService::class);

    $kept = $service->confirm($service->request($h['user'], $h['household'], mealPhotoWithExif()), keepPhoto: true);
    $confirmed = $service->confirm($service->request($h['user'], $h['household'], mealPhotoWithExif()));
    $draft = $service->request($h['user'], $h['household'], mealPhotoWithExif());
    $paths = [$kept->photo()->getPath(), $confirmed->photo()->getPath(), $draft->photo()->getPath()];

    $this->artisan('app:meal-analysis-cleanup')->assertSuccessful();
    expect(array_map('is_file', $paths))->toBe([true, true, true]);

    $this->travel(25)->hours();
    $this->artisan('app:meal-analysis-cleanup')->expectsOutputToContain('Zmazané fotky: 2')->assertSuccessful();
    expect(array_map('is_file', $paths))->toBe([true, false, false])
        ->and($confirmed->fresh()->photo_removed_at)->not->toBeNull()
        ->and($confirmed->fresh()->nutrition)->not->toBeNull()
        ->and($draft->fresh())->not->toBeNull();

    Livewire::withQueryParams(['analyza' => $confirmed->id])->test(MealPhotoAnalyzer::class)->assertSeeHtml('data-test="meal-photo-removed"');
    $this->get(route('meals.photo', $confirmed))->assertNotFound();

    $this->travel(7)->days();
    $this->artisan('app:meal-analysis-cleanup')->expectsOutputToContain('nedokončené návrhy: 1')->assertSuccessful();
    expect(MealAnalysis::query()->find($draft->id))->toBeNull()
        ->and(MealAnalysis::query()->find($kept->id))->not->toBeNull()
        ->and(is_file($paths[0]))->toBeTrue();
});

it('shows the administrator only job metadata and erases analyses with the account', function () {
    $h = mealUser();
    MealAnalysisAgent::fake([mealProposal()]);
    $analysis = app(MealAnalysisService::class)->request($h['user'], $h['household'], mealPhotoWithExif(), 'tajný obed');
    $path = $analysis->photo()->getPath();

    actingAsPlatformAdmin();
    $this->get(route('admin.ai', ['kind' => 'meal_analysis']))
        ->assertOk()
        ->assertSee('Analýza jedla')
        ->assertDontSee('tajný obed')
        ->assertDontSee('Kuracie s ryžou')
        ->assertDontSee('ryža varená');
    $this->get(route('meals.photo', $analysis))->assertForbidden();

    Mail::fake();
    app(AccountErasure::class)->erase($h['user']->fresh());
    expect(MealAnalysis::query()->find($analysis->id))->toBeNull()
        ->and(is_file($path))->toBeFalse();
});
