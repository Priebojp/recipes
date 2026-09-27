<?php

use App\Enums\CatalogState;
use App\Enums\LaunchCheckStatus;
use App\Enums\LegalDocumentState;
use App\Enums\LegalDocumentType;
use App\Enums\ManualNutritionOrigin;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\UsageKind;
use App\Models\AddonVersion;
use App\Models\LegalDocumentVersion;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use App\Models\UsageGrant;
use App\Services\Admin\AppSettings;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\ImageProfile;
use App\Services\Billing\CatalogManager;
use App\Services\Billing\PlanStatus;
use App\Services\Billing\RefundService;
use App\Services\Diary\ConsumptionPortion;
use App\Services\Diary\MealDiaryService;
use App\Services\Launch\LaunchCheck;
use App\Services\Launch\LaunchReadiness;
use App\Services\Launch\LaunchSignoffs;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
use App\Services\Usage\UsageLedger;
use App\Services\Usage\UsageProvisioner;
use Database\Seeders\AiCostRateSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LegalDocumentSeeder;
use Livewire\Livewire;
use Tests\Support\BillingScenario;
use Tests\Support\StripePayloads as Stripe;

/** @return array<string, LaunchCheck> */
function v21Checks(): array
{
    $checks = [];
    foreach (app(LaunchReadiness::class)->checks() as $check) {
        $checks[$check->key] = $check;
    }

    return $checks;
}

/** Version 2 of the monthly plan: 20 Economy images and 30 photo analyses, activated by the administrator. */
function activatePlanV2(): PlanVersion
{
    $manager = app(CatalogManager::class);
    $v1 = PlanVersion::query()->where('code', 'plus_monthly')->where('version', 1)->sole();
    $v2 = $manager->newPlanVersion($v1, ['stripe_price_id' => 'price_plus_monthly_v2', 'image_uses_per_period' => 20, 'image_profile_code' => ImageProfile::EconomyV1->value, 'meal_analysis_uses_per_period' => 30], 'ponuka v2.1 po vyhodnotení');
    $manager->activate($v2, 'ponuka v2.1');

    return $v2->fresh();
}

it('grants nothing new to a running period when version 2 is activated and opens the new kinds only from the renewal (scenario 17)', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);
    $ledger = app(UsageLedger::class);
    expect($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(6) // 1 trial + 5 Standard
        ->and($ledger->available($h['household'], UsageKind::ImageEconomy))->toBe(0)
        ->and($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(3); // the stage-11 trial only

    $v2 = activatePlanV2();
    expect($v2->usesPerPeriod())->toBe(['text' => 30, 'image_economy' => 20, 'meal_analysis' => 30])
        ->and(PlanVersion::query()->where('code', 'plus_monthly')->where('version', 1)->sole()->state)->toBe(CatalogState::Retired);

    // The running period keeps its version-1 grants: nothing is added, nothing is taken away.
    app(UsageProvisioner::class)->ensureFor($h['household']);
    expect($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(6)
        ->and($ledger->available($h['household'], UsageKind::ImageEconomy))->toBe(0)
        ->and($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(3)
        ->and(UsageGrant::query()->where('source', 'subscription')->count())->toBe(2)
        ->and(PaidEntitlement::query()->sole()->plan_version_id)->toBe(PlanVersion::query()->where('code', 'plus_monthly')->where('version', 1)->sole()->id)
        ->and(app(AiAvailability::class)->imageProfileFor($h['household']))->toBe(ImageProfile::StandardV1);
    $this->get(route('pricing'))->assertSee('20 × obrázky Economy')->assertSee('30 × analýzy jedla');

    // The renewal is invoiced under the new price → the new period gets the version-2 kinds; the old grants stay as they were.
    $this->travel(1)->month();
    Stripe::post($this, Stripe::invoicePaid($order->billingAccount, 'in_renewal_v2', 'sub_'.$order->id, 'price_plus_monthly_v2', now()->timestamp, now()->addMonth()->timestamp))->assertOk();
    app(UsageProvisioner::class)->ensureFor($h['household']);

    expect(PaidEntitlement::query()->latest('id')->first()->plan_version_id)->toBe($v2->id)
        ->and($ledger->available($h['household'], UsageKind::ImageEconomy))->toBe(20)
        ->and($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(33)
        ->and($ledger->available($h['household'], UsageKind::ImageStandard))->toBe(1) // the 5 of the old period expired; the trial stays
        ->and(UsageGrant::query()->where('kind', UsageKind::ImageStandard)->where('source', 'subscription')->sole()->quantity)->toBe(5)
        ->and(app(PlanStatus::class)->isPlus($h['household']))->toBeTrue();
});

it('sells the seeded analyses pack only after activation, grants it once through the webhook and refunds its unused units', function () {
    $h = BillingScenario::start();
    $draft = AddonVersion::query()->where('code', 'meal_analyses_100')->sole();
    expect($draft->state)->toBe(CatalogState::Draft)->and($draft->final_price_cents)->toBe(199)->and($draft->unit_kind)->toBe(UsageKind::MealAnalysis);
    $this->get(route('pricing'))->assertDontSee('100 analýz jedla');
    $this->post(route('checkout.addon'), ['addon' => 'meal_analyses_100', ...BillingScenario::termsInput()])->assertSessionHasErrors();

    $draft->update(['stripe_price_id' => 'price_meal_analyses_100']);
    app(CatalogManager::class)->activate($draft->fresh(), 'cena potvrdená');
    $this->get(route('pricing'))->assertSee('100 analýz jedla')->assertSee('100 × analýzy jedla');

    $order = BillingScenario::startAddon('meal_analyses_100');
    $completed = Stripe::checkoutCompleted($order, 'payment');
    Stripe::post($this, $completed)->assertOk();
    Stripe::post($this, $completed)->assertOk()->assertSee('Duplicate');
    $ledger = app(UsageLedger::class);
    expect($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(103)
        ->and(UsageGrant::query()->where('source', 'addon')->sole()->kind)->toBe(UsageKind::MealAnalysis)
        ->and($order->fresh()->product_snapshot['unit_kind'])->toBe('meal_analysis');

    $case = app(RefundService::class)->request($order, RefundKind::Withdrawal, 199, 'Odstúpenie do 14 dní', ['meal_analysis' => 100], by: $h['user']);
    expect($case->status)->toBe(RefundStatus::Processed)
        ->and($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(3);
});

it('keeps the diary readable and corrections working after Plus ends while a new analysis needs an entitlement', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    activatePlanV2();
    $order = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);
    $ledger = app(UsageLedger::class);
    expect($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(33);

    $diary = app(MealDiaryService::class);
    $entry = $diary->logManual($h['user'], $h['household'], 'Obed', ['energy_kcal' => 600, 'protein_g' => 30, 'carbohydrate_g' => 60, 'fat_g' => 20], ManualNutritionOrigin::Estimate, now(), 'Europe/Bratislava');

    // Use up the trial as well, then let the period run out without a renewal.
    UsageGrant::query()->where('household_id', $h['household']->id)->where('kind', UsageKind::MealAnalysis)->update(['expires_at' => now()->subMinute()]);
    $this->travel(2)->months();
    expect(app(PlanStatus::class)->isPlus($h['household']))->toBeFalse()
        ->and($ledger->available($h['household'], UsageKind::MealAnalysis))->toBe(0)
        ->and(app(AiAvailability::class)->reasonUnavailable($h['household'], UsageKind::MealAnalysis))->not->toBeNull();

    $this->get(route('diary.index', ['den' => $entry->eaten_on->toDateString()]))->assertOk()->assertSee('Obed')->assertSee('600 kcal');
    $snapshot = $diary->adjust($entry, ConsumptionPortion::fraction(0.5));
    expect($snapshot->totals['energy_kcal'])->toEqual(300.0)->and($snapshot->revision)->toBe(2);
});

it('blocks the launch without rates for a sold profile or a measurement of analyses, and warns about the USDA key, the cleanup and the Stripe identity', function () {
    $this->seed(CatalogSeeder::class);
    $checks = v21Checks();
    expect($checks['ai.profile_rates']->status)->toBe(LaunchCheckStatus::Fail) // version 1 sells Standard images and no rate is loaded yet
        ->and($checks['ai.meal_measurement']->status)->toBe(LaunchCheckStatus::Warn)
        ->and($checks['food.usda']->status)->toBe(LaunchCheckStatus::Warn)
        ->and($checks['env.meal_cleanup']->status)->toBe(LaunchCheckStatus::Warn)
        ->and($checks['legal.stripe_identity']->status)->toBe(LaunchCheckStatus::Warn)
        ->and($checks['signoff.image_profile']->status)->toBe(LaunchCheckStatus::Warn);

    // Selling Economy images and analyses turns the missing pieces into blockers.
    activatePlanV2();
    config()->set('recipes.ai.image_model', 'gpt-image-2');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
    $checks = v21Checks();
    expect($checks['ai.profile_rates']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['ai.meal_measurement']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['signoff.image_profile']->status)->toBe(LaunchCheckStatus::Fail);

    $this->seed(AiCostRateSeeder::class);
    app(AppSettings::class)->set(LaunchReadiness::MEASUREMENT_KEY, ['run' => 'r', 'at' => now()->toIso8601String(), 'kinds' => [
        'meal_analysis' => ['jobs' => 12, 'succeeded' => 12, 'model' => 'gpt-6-luna', 'analyses' => 12, 'delivered' => 11, 'median_cost_micro' => 2_100, 'p95_cost_micro' => 4_800],
    ]]);
    $admin = actingAsPlatformAdmin();
    app(LaunchSignoffs::class)->confirm('image_profile', $admin, '19/20 Economy prijateľných');
    config()->set('services.usda.key', 'demo');
    $this->artisan('app:meal-analysis-cleanup')->assertSuccessful();
    app(OperatorIdentity::class)->update(['public_business_name' => 'Moje recepty', 'statement_descriptor' => 'MOJE-RECEPTY.SK'], $admin, 'Stripe');

    $checks = v21Checks();
    expect($checks['ai.profile_rates']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['ai.meal_measurement']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['ai.meal_measurement']->detail)->toContain('11 rozpoznaných z 12')
        ->and($checks['food.usda']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['env.meal_cleanup']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['legal.stripe_identity']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['signoff.image_profile']->status)->toBe(LaunchCheckStatus::Ok);

    $this->get(route('admin.ai'))->assertOk()->assertSee('Analýzy jedla – náklady')->assertSee('Integračné kľúče')->assertDontSee('demo');
    $this->get(route('admin.legal'))->assertOk()->assertSee('Statement descriptor');
});

it('drafts the v2.1 privacy and terms texts for an installation with older published versions and refuses to publish a placeholder', function () {
    $admin = actingAsPlatformAdmin();
    $this->seed(LegalDocumentSeeder::class);
    $privacy = LegalDocumentVersion::query()->where('type', LegalDocumentType::Privacy)->sole();
    expect($privacy->content)->toContain(LegalDocumentSeeder::V21_MARKER)->and($privacy->content)->toContain('OpenAI');

    // An older install: version 1 without the new purposes was published; the seeder adds a draft of version 2.
    $documents = app(LegalDocuments::class);
    $documents->updateDraft($privacy, ['content' => 'Starý text bez nového účelu.'], $admin);
    $documents->publish($privacy->fresh(), 'schválené', $admin);
    $this->seed(LegalDocumentSeeder::class);
    $this->seed(LegalDocumentSeeder::class);
    $draft = $documents->draft(LegalDocumentType::Privacy);
    expect($draft)->not->toBeNull()
        ->and($draft->version)->toBe(2)
        ->and($draft->state)->toBe(LegalDocumentState::Draft)
        ->and($draft->content)->toContain('Denník „Zjedol som“')
        ->and(LegalDocumentVersion::query()->where('type', LegalDocumentType::Privacy)->count())->toBe(2)
        ->and($documents->current(LegalDocumentType::Privacy)->version)->toBe(1);

    expect(fn () => $documents->publish($draft, 'schválené', $admin))->toThrow(InvalidArgumentException::class);
    $this->get(route('legal.show', ['slug' => 'ochrana-osobnych-udajov']))->assertOk()->assertDontSee('Zjedol som');

    $terms = $documents->latest(LegalDocumentType::Terms);
    expect($terms->content)->toContain('nie o medicínske meranie')->and($terms->content)->toContain('analýz jedla z fotografie');
});

it('lets the administrator draft a plan with Economy images and analyses and create a new pack code', function () {
    $this->seed(CatalogSeeder::class);
    actingAsPlatformAdmin();
    $v1 = PlanVersion::query()->where('code', 'plus_yearly')->sole();

    Livewire::test('pages::admin.catalog')
        ->call('startDraft', 'plan', $v1->id)
        ->assertSet('draft_image_profile', 'image_standard_v1')
        ->set('draft_image_uses', '20')
        ->set('draft_image_profile', 'image_economy_v1')
        ->set('draft_meal_analyses', '30')
        ->set('draft_reason', 'ponuka v2.1 po vyhodnotení')
        ->call('saveDraft')
        ->assertHasNoErrors();
    $v2 = PlanVersion::query()->where('code', 'plus_yearly')->where('version', 2)->sole();
    expect($v2->state)->toBe(CatalogState::Draft)
        ->and($v2->imageProfile())->toBe(ImageProfile::EconomyV1)
        ->and($v2->meal_analysis_uses_per_period)->toBe(30)
        ->and($v1->fresh()->state)->toBe(CatalogState::Active)
        ->and($v1->fresh()->imageProfile())->toBe(ImageProfile::StandardV1);

    Livewire::test('pages::admin.catalog')
        ->call('startCreate')
        ->set('new_code', 'images_economy_20')
        ->set('new_name', '20 obrázkov Economy')
        ->set('new_kind', 'image_economy')
        ->set('new_unit_count', '20')
        ->set('new_price', '1,49')
        ->set('new_reason', 'cena po porovnaní')
        ->call('createAddon')
        ->assertHasNoErrors()
        ->assertSet('creating', false);
    $pack = AddonVersion::query()->where('code', 'images_economy_20')->sole();
    expect($pack->state)->toBe(CatalogState::Draft)->and($pack->unit_kind)->toBe(UsageKind::ImageEconomy)->and($pack->final_price_cents)->toBe(149);

    Livewire::test('pages::admin.catalog')
        ->call('startCreate')
        ->set('new_code', 'images_economy_20')
        ->set('new_name', 'Duplicita')
        ->set('new_kind', 'image_economy')
        ->set('new_unit_count', '20')
        ->set('new_price', '1,49')
        ->set('new_reason', 'znova ten istý kód')
        ->call('createAddon')
        ->assertHasErrors(['new_code']);
});
