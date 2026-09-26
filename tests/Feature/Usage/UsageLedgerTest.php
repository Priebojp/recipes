<?php

use App\Ai\Agents\RecipeTextAgent;
use App\Enums\AiJobStatus;
use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Enums\UsageLedgerReason;
use App\Enums\UsageReservationState;
use App\Jobs\RunRecipeTextJob;
use App\Livewire\AiTextAssistant;
use App\Models\AdminAudit;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Models\UsageGrant;
use App\Models\UsageLedgerEntry;
use App\Models\UsageReservation;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Ai\AiJobLifecycle;
use App\Services\Ai\AiTextService;
use App\Services\Ai\AiUnavailableException;
use App\Services\RecipeService;
use App\Services\Usage\TrialGrants;
use App\Services\Usage\UsageLedger;
use App\Support\CurrentHousehold;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
});

function ledgerRecipe(array $household): Recipe
{
    $recipe = Recipe::factory()->create(['household_id' => $household['household']->id, 'title' => 'gulas', 'description' => 'hovadzi gulas']);

    return app(RecipeService::class)->update($recipe, $household['user'], [
        'ingredients' => [['name' => 'hovädzie', 'amount' => '500', 'unit' => 'g']],
        'steps' => [['text' => 'dusiť']],
    ]);
}

function ledgerSuggestion(): array
{
    return ['suggested_title' => 'Guláš', 'suggested_description' => 'Hovädzí guláš', 'ingredients' => [], 'steps' => [], 'questions' => [], 'change_summary' => []];
}

/** Trial granted and fully revoked, so the household starts at zero before the test's own grants. */
function withoutTrial(array $household): void
{
    app(TrialGrants::class)->ensureFor($household['household']);
    foreach (UsageGrant::query()->where('household_id', $household['household']->id)->get() as $grant) {
        app(UsageLedger::class)->revoke($grant, $grant->available(), 'test-revoke:'.$grant->id);
    }
}

it('grants the trial once per verified owner and never again for a rollout re-run or a second household', function () {
    $h = household();
    $trials = app(TrialGrants::class);

    expect($trials->ensureFor($h['household']))->toHaveCount(2)
        ->and($trials->ensureFor($h['household']))->toBe([])
        ->and($trials->backfill())->toBe(['households' => 1, 'granted' => 0]);

    $second = CurrentHousehold::createFor($h['user'], 'Druhá');
    expect($trials->ensureFor($second))->toBe([])
        ->and(UsageGrant::query()->where('source', UsageGrantSource::Trial)->count())->toBe(2)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::Text))->toBe(3)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::ImageStandard))->toBe(1);

    $this->artisan('app:usage-backfill-trials')->expectsOutputToContain('nových skúšobných grantov: 0')->assertSuccessful();
});

it('does not grant the trial to a household whose owner has not verified the e-mail', function () {
    $owner = User::factory()->unverified()->create();
    $household = CurrentHousehold::createFor($owner);

    expect(app(TrialGrants::class)->ensureFor($household))->toBe([]);

    $owner->markEmailAsVerified();
    event(new Verified($owner));

    expect(UsageGrant::query()->where('household_id', $household->id)->count())->toBe(2);
});

it('spends the grant expiring soonest before the oldest purchased one', function () {
    $h = household();
    withoutTrial($h);
    $ledger = app(UsageLedger::class);
    $recipe = ledgerRecipe($h);

    $addon = $ledger->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 100, 'addon:1', validFrom: now()->subMonth());
    $monthly = $ledger->grant($h['household'], UsageKind::Text, UsageGrantSource::Subscription, 1, 'sub:1', expiresAt: now()->addDays(10));

    RecipeTextAgent::fake([ledgerSuggestion(), ledgerSuggestion()]);
    $first = app(AiTextService::class)->request($recipe, $h['user'], 'description');
    $second = app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'steps');

    expect($first->usageReservation->usage_grant_id)->toBe($monthly->id)
        ->and($second->usageReservation->usage_grant_id)->toBe($addon->id)
        ->and($monthly->fresh()->consumed_quantity)->toBe(1)
        ->and($addon->fresh()->available())->toBe(99);

    $balance = $ledger->balance($h['household'], UsageKind::Text);
    expect($balance->includedAvailable)->toBe(0)->and($balance->includedTotal)->toBe(1)->and($balance->purchasedAvailable)->toBe(99)->and($balance->available())->toBe(99);
});

it('lets only one of two jobs competing for a single use reserve it and creates no job for the loser', function () {
    $h = household();
    withoutTrial($h);
    app(UsageLedger::class)->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 1, 'addon:one');
    $recipe = ledgerRecipe($h);
    RecipeTextAgent::fake([ledgerSuggestion(), ledgerSuggestion()]);

    $winner = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    expect(fn () => app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'steps'))
        ->toThrow(AiUnavailableException::class, 'Nemáš už žiadne voľné AI použitia');

    expect(AiJob::count())->toBe(1)
        ->and(UsageReservation::count())->toBe(1)
        ->and($winner->status)->toBe(AiJobStatus::Succeeded)
        ->and(UsageGrant::first()->available())->toBe(0);
});

it('never reserves or consumes a second use when the worker retries a job', function () {
    $h = household();
    withoutTrial($h);
    $grant = app(UsageLedger::class)->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 5, 'addon:five');
    $recipe = ledgerRecipe($h);
    RecipeTextAgent::fake([ledgerSuggestion(), ledgerSuggestion()]);

    $job = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    // Same worker payload delivered twice (queue redelivery): the finished job is left untouched.
    app(AiTextService::class)->run($job->fresh());
    app(AiJobLifecycle::class)->succeed($job->fresh(), []);

    // Explicit reservation of an already reserved job returns the existing row.
    DB::transaction(fn () => expect(app(UsageLedger::class)->reserve($job->fresh(), UsageKind::Text)->id)->toBe($job->usageReservation->id));

    expect($grant->fresh()->consumed_quantity)->toBe(1)
        ->and($grant->fresh()->reserved_quantity)->toBe(0)
        ->and($grant->fresh()->available())->toBe(4)
        ->and(UsageLedgerEntry::query()->where('reason', UsageLedgerReason::Consumed)->count())->toBe(1)
        ->and(app(UsageLedger::class)->reconcile($grant->fresh()))->toBeTrue();
});

it('releases the use exactly once on a definitive failure and consumes it atomically on success', function () {
    $h = household();
    withoutTrial($h);
    $grant = app(UsageLedger::class)->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 1, 'addon:one');
    $recipe = ledgerRecipe($h);

    RecipeTextAgent::fake([fn () => throw new RuntimeException('provider refused')]);
    $failed = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    expect($failed->status)->toBe(AiJobStatus::Failed)
        ->and($failed->usageReservation->state)->toBe(UsageReservationState::Released)
        ->and($grant->fresh()->available())->toBe(1);

    app(UsageLedger::class)->release($failed->fresh());
    expect(UsageLedgerEntry::query()->where('reason', UsageLedgerReason::Released)->count())->toBe(1)
        ->and($grant->fresh()->available())->toBe(1);

    RecipeTextAgent::fake([ledgerSuggestion()]);
    $ok = app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'description', fresh: true);

    expect($ok->status)->toBe(AiJobStatus::Succeeded)
        ->and($ok->usageReservation->state)->toBe(UsageReservationState::Consumed)
        ->and($grant->fresh()->available())->toBe(0)
        ->and($grant->fresh()->consumed_quantity)->toBe(1)
        ->and((int) UsageLedgerEntry::query()->where('usage_grant_id', $grant->id)->sum('movement'))->toBe(0);
});

it('holds the use for a timed-out call until an operator resolves it, then releases it once', function () {
    $h = household();
    withoutTrial($h);
    $grant = app(UsageLedger::class)->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 1, 'addon:one');
    $recipe = ledgerRecipe($h);
    RecipeTextAgent::fake([fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds')]);

    $job = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    expect($job->status)->toBe(AiJobStatus::Reconciling)
        ->and($job->usageReservation->state)->toBe(UsageReservationState::Reserved)
        ->and($grant->fresh()->available())->toBe(0);

    Livewire::test(AiTextAssistant::class, ['recipeId' => $recipe->id])->assertSee('Výsledok sa overuje');

    $this->artisan('app:ai-reconcile')->expectsOutputToContain((string) $job->id)->assertSuccessful();
    $this->artisan('app:ai-reconcile', ['job' => $job->id, '--fail' => true, '--reason' => 'poskytovateľ výsledok neeviduje'])->assertSuccessful();

    expect($job->fresh()->status)->toBe(AiJobStatus::Failed)
        ->and($job->fresh()->error)->toContain('poskytovateľ výsledok neeviduje')
        ->and($job->usageReservation->fresh()->state)->toBe(UsageReservationState::Released)
        ->and($grant->fresh()->available())->toBe(1)
        ->and(AdminAudit::query()->where('action', 'ai.job.reconciled_failed')->count())->toBe(1);
});

it('marks a job whose worker was killed as reconciling instead of failed', function () {
    $h = household();
    $recipe = ledgerRecipe($h);
    $job = AiJob::create([
        'household_id' => $h['household']->id, 'recipe_id' => $recipe->id, 'kind' => 'text', 'status' => AiJobStatus::Running,
        'request_key' => str_repeat('a', 64), 'prompt_version' => '1', 'input' => [],
    ]);

    (new RunRecipeTextJob($job->id))->failed(new TimeoutExceededException('timeout'));

    expect($job->fresh()->status)->toBe(AiJobStatus::Reconciling);
});

it('finishes a job reserved on a grant that expired meanwhile and does not carry a released use over', function () {
    $h = household();
    withoutTrial($h);
    $ledger = app(UsageLedger::class);
    $grant = $ledger->grant($h['household'], UsageKind::Text, UsageGrantSource::Subscription, 2, 'sub:expiring', expiresAt: now()->addMinutes(5));
    $recipe = ledgerRecipe($h);

    RecipeTextAgent::fake([fn () => throw new ConnectionException('Operation timed out'), fn () => throw new ConnectionException('Operation timed out')]);
    $consumedLater = app(AiTextService::class)->request($recipe, $h['user'], 'description');
    $releasedLater = app(AiTextService::class)->request($recipe->fresh(), $h['user'], 'steps');
    expect($consumedLater->status)->toBe(AiJobStatus::Reconciling);

    $this->travel(10)->minutes();

    app(AiJobLifecycle::class)->succeed($consumedLater->fresh(), ['output' => ledgerSuggestion()]);
    app(AiJobLifecycle::class)->resolveAsFailed($releasedLater->fresh(), null, 'test');

    expect($consumedLater->fresh()->status)->toBe(AiJobStatus::Succeeded)
        ->and($grant->fresh()->consumed_quantity)->toBe(1)
        ->and($grant->fresh()->available())->toBe(1)
        ->and($ledger->available($h['household'], UsageKind::Text))->toBe(0);
});

it('revokes only unused uses of one grant and rebuilds counters from the ledger', function () {
    $h = household();
    withoutTrial($h);
    $ledger = app(UsageLedger::class);
    $grant = $ledger->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 3, 'addon:three');
    $other = $ledger->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 3, 'addon:other');
    $recipe = ledgerRecipe($h);
    RecipeTextAgent::fake([ledgerSuggestion()]);
    app(AiTextService::class)->request($recipe, $h['user'], 'description');

    expect(fn () => $ledger->revoke($grant, 3, 'refund:1'))->toThrow(InvalidArgumentException::class);

    $ledger->revoke($grant, 2, 'refund:1');
    $ledger->revoke($grant, 2, 'refund:1'); // duplicate webhook

    expect($grant->fresh()->available())->toBe(0)
        ->and($grant->fresh()->revoked_at)->not->toBeNull()
        ->and($grant->fresh()->consumed_quantity)->toBe(1)
        ->and($other->fresh()->available())->toBe(3)
        ->and(UsageLedgerEntry::query()->where('usage_grant_id', $grant->id)->where('reason', UsageLedgerReason::Revoked)->count())->toBe(1);

    $grant->forceFill(['consumed_quantity' => 0, 'reserved_quantity' => 5])->save();
    expect($ledger->reconcile($grant))->toBeFalse()
        ->and($grant->fresh()->consumed_quantity)->toBe(1)
        ->and($grant->fresh()->reserved_quantity)->toBe(0)
        ->and($ledger->reconcile($grant->fresh()))->toBeTrue();
});

it('keeps grants intact and manual editing possible when the AI kill switch is off', function () {
    $h = household();
    $recipe = ledgerRecipe($h);
    app(TrialGrants::class)->ensureFor($h['household']);
    app(AppSettings::class)->set('ai.enabled', false);

    expect(fn () => app(AiTextService::class)->request($recipe, $h['user'], 'description'))->toThrow(AiUnavailableException::class, 'dočasne nedostupné');
    expect(app(RecipeService::class)->update($recipe->fresh(), $h['user'], ['title' => 'Ručne'])->title)->toBe('Ručne')
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::Text))->toBe(3)
        ->and(UsageReservation::count())->toBe(0);
});

it('runs without reservations when the ledger is not enforced', function () {
    config()->set('recipes.usage.enforce', false);
    $h = household();
    $recipe = ledgerRecipe($h);
    RecipeTextAgent::fake([ledgerSuggestion()]);

    $job = app(AiTextService::class)->request($recipe, $h['user'], 'description');

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and(UsageGrant::count())->toBe(0)
        ->and(UsageReservation::count())->toBe(0);

    Livewire::test(AiTextAssistant::class, ['recipeId' => $recipe->id])->assertDontSee('Spotrebuje 1 použitie');
});

it('shows the remaining uses next to the AI button and an exhaustion notice at zero', function () {
    $h = household();
    $recipe = ledgerRecipe($h);

    Livewire::test(AiTextAssistant::class, ['recipeId' => $recipe->id])
        ->assertSeeHtml('data-test="ai-text-balance"')
        ->assertSee('zostáva 3')
        ->assertSee('skúšobné: 3/3');

    withoutTrial($h);

    Livewire::test(AiTextAssistant::class, ['recipeId' => $recipe->id])
        ->assertSee('Nemáš už žiadne voľné AI použitia')
        ->call('request')
        ->assertSet('jobId', null);
    expect(AiJob::count())->toBe(0);
});

it('issues an audited compensation grant once per key', function () {
    $h = household();

    $this->artisan('app:usage-compensate', ['household' => $h['household']->id, 'kind' => 'image_standard', 'quantity' => 2, '--reason' => 'zlyhané obrázky 25. 9.', '--key' => 'ticket-42'])->assertSuccessful();
    $this->artisan('app:usage-compensate', ['household' => $h['household']->id, 'kind' => 'image_standard', 'quantity' => 2, '--reason' => 'zlyhané obrázky 25. 9.', '--key' => 'ticket-42'])->expectsOutputToContain('už existuje')->assertSuccessful();

    $grant = UsageGrant::query()->where('source', UsageGrantSource::Compensation)->sole();
    expect($grant->quantity)->toBe(2)
        ->and($grant->expires_at)->toBeNull()
        ->and(AdminAudit::query()->where('action', 'usage.compensation.granted')->count())->toBe(1);

    $this->get(route('usage.index'))->assertOk()->assertSee('Kompenzácia')->assertSee('zlyhané obrázky');
});

it('renders the usage settings page with included and purchased balances', function () {
    $h = household();
    app(UsageLedger::class)->grant($h['household'], UsageKind::Text, UsageGrantSource::Addon, 100, 'addon:text');

    $this->get(route('usage.index'))
        ->assertOk()
        ->assertSee('Textové operácie')
        ->assertSee('3/3')
        ->assertSee('Spolu k dispozícii')
        ->assertSee('103');
});
