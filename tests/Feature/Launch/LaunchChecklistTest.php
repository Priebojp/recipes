<?php

use App\Enums\LaunchCheckStatus;
use App\Models\AdminAudit;
use App\Models\Order;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Billing\Gateway\StripeInspector;
use App\Services\Billing\StripeWebhookEvents;
use App\Services\Launch\LaunchCheck;
use App\Services\Launch\LaunchReadiness;
use App\Services\Launch\LaunchSignoffs;
use App\Services\Legal\CheckoutReadiness;
use Database\Seeders\AiCostRateSeeder;
use Database\Seeders\CatalogSeeder;
use Laravel\Cashier\Console\WebhookCommand;
use Livewire\Livewire;
use Tests\Support\BillingScenario;
use Tests\Support\FakeStripeInspector;
use Tests\Support\LegalScenario;

/** @return array<string, LaunchCheck> */
function launchChecks(?StripeInspector $stripe = null): array
{
    $checks = [];
    foreach (app(LaunchReadiness::class)->checks($stripe) as $check) {
        $checks[$check->key] = $check;
    }

    return $checks;
}

/** Everything the checklist can verify automatically is in place; sign-offs and measurement are separate. */
function launchInfrastructure(): void
{
    test()->seed(CatalogSeeder::class);
    test()->seed(AiCostRateSeeder::class);
    LegalScenario::ready();
    config()->set('cashier.secret', 'sk_test_abc');
    config()->set('cashier.key', 'pk_test_abc');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('recipes.ai.text_model', 'gpt-6-luna');
    config()->set('recipes.ai.image_model', 'gpt-image-2');
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'smtp');
    config()->set('admin.require_two_factor', true);
    $admin = User::factory()->withTwoFactor()->create();
    $admin->forceFill(['is_platform_admin' => true, 'platform_admin_granted_at' => now()])->save();
}

function storeMeasurement(int $texts = 30, int $images = 30): void
{
    app(AppSettings::class)->set(LaunchReadiness::MEASUREMENT_KEY, [
        'run' => 'test-run', 'at' => now()->toIso8601String(), 'total_cost_micro' => 1_250_000,
        'kinds' => [
            'text' => ['jobs' => $texts, 'succeeded' => $texts, 'failed' => 0, 'unpriced' => 0, 'model' => 'gpt-6-luna', 'avg_cost_micro' => 1_500],
            'image' => ['jobs' => $images, 'succeeded' => $images, 'failed' => 0, 'unpriced' => 0, 'model' => 'gpt-image-2', 'avg_cost_micro' => 40_000],
        ],
        'projection' => ['plus_month_micro' => 245_000, 'images_20_micro' => 800_000, 'text_100_micro' => 150_000],
    ]);
}

it('keeps paid checkout off until the deploy switch is on, even with the legal side ready', function () {
    config()->set('recipes.billing.checkout_enabled', false);
    BillingScenario::start();

    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_monthly', ...BillingScenario::termsInput()])->assertSessionHasErrors('plan');
    expect(Order::query()->count())->toBe(0);
    $this->get(route('pricing'))->assertOk()->assertSee('Platby ešte nie sú zapnuté');
    expect(app(CheckoutReadiness::class)->blockers())->toContain('Platby nie sú zapnuté nasadením (RECIPES_CHECKOUT_ENABLED).');

    config()->set('recipes.billing.checkout_enabled', true);
    BillingScenario::startPlan();
    expect(Order::query()->count())->toBe(1);
});

it('reports every blocker on an empty install and passes once infrastructure, measurement and sign-offs are in place', function () {
    $checks = launchChecks();
    expect($checks['stripe.keys']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['catalog.active']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['legal.checkout']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['admin.account']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['ai.keys']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['ai.measurement']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['signoff.prices']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['stripe.webhook_endpoint']->status)->toBe(LaunchCheckStatus::Skip)
        // The switch is on (phpunit.xml) while the checklist fails: that is itself a blocker.
        ->and($checks['launch.switch']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['env.production']->status)->toBe(LaunchCheckStatus::Warn);
    $this->artisan('app:launch-check')->assertFailed();

    launchInfrastructure();
    $checks = launchChecks();
    expect($checks['stripe.keys']->status)->toBe(LaunchCheckStatus::Warn) // sandbox keys outside production: fine
        ->and($checks['catalog.active']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['catalog.env_match']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['legal.checkout']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['admin.account']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['ai.models']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['ai.measurement']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['env.queue']->status)->toBe(LaunchCheckStatus::Ok);

    // A short or outdated measurement only warns; the operator decides in the sign-off.
    storeMeasurement(texts: 10);
    expect(launchChecks()['ai.measurement']->status)->toBe(LaunchCheckStatus::Warn);
    storeMeasurement();
    expect(launchChecks()['ai.measurement']->status)->toBe(LaunchCheckStatus::Ok);

    $admin = User::query()->where('is_platform_admin', true)->firstOrFail();
    foreach (array_keys(LaunchSignoffs::ITEMS) as $key) {
        app(LaunchSignoffs::class)->confirm($key, $admin, 'overené v teste');
    }

    $checks = launchChecks();
    expect(app(LaunchReadiness::class)->summary(array_values($checks))['ready'])->toBeTrue()
        ->and($checks['launch.switch']->status)->toBe(LaunchCheckStatus::Ok);
    $this->artisan('app:launch-check')->assertSuccessful();
    $this->artisan('app:launch-check', ['--json' => true])->assertSuccessful()->expectsOutputToContain('"ready": true');

    // Switch off with a clean checklist: the last step is a deliberate deployment.
    config()->set('recipes.billing.checkout_enabled', false);
    expect(launchChecks()['launch.switch']->status)->toBe(LaunchCheckStatus::Warn);
});

it('verifies catalogue prices and the webhook endpoint against the Stripe account', function () {
    launchInfrastructure();
    $monthly = PlanVersion::query()->where('code', 'plus_monthly')->firstOrFail();

    $stripe = (new FakeStripeInspector)
        ->withPrice('price_plus_monthly', 249, 'month')
        ->withPrice('price_plus_yearly', 2400, 'year')
        ->withPrice('price_images_20', 399, null)
        ->withPrice('price_text_100', 199, null)
        ->withEndpoint(route('cashier.webhook'), StripeWebhookEvents::required());

    $checks = launchChecks($stripe);
    expect($checks['catalog.stripe.plus_monthly']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['catalog.stripe.text_100']->status)->toBe(LaunchCheckStatus::Ok)
        ->and($checks['stripe.webhook_endpoint']->status)->toBe(LaunchCheckStatus::Ok);

    // Wrong amount in Stripe, a yearly price billed monthly, a live price under test keys, a missing price.
    $stripe->withPrice('price_plus_monthly', 299, 'month')->withPrice('price_plus_yearly', 2400, 'month')->withPrice('price_text_100', 199, null, live: true);
    unset($stripe->prices['price_images_20']);
    $checks = launchChecks($stripe);
    expect($checks['catalog.stripe.plus_monthly']->status)->toBe(LaunchCheckStatus::Fail)
        ->and($checks['catalog.stripe.plus_monthly']->detail)->toContain('2,99 €')->toContain('2,49 €')
        ->and($checks['catalog.stripe.plus_yearly']->detail)->toContain('interval month ≠ year')
        ->and($checks['catalog.stripe.text_100']->detail)->toContain('live')
        ->and($checks['catalog.stripe.images_20_standard']->detail)->toContain('neexistuje');

    // Endpoint without the checkout/refund events (Cashier's default list) blocks; a disabled or missing endpoint too.
    $stripe->endpoints = [];
    $stripe->withEndpoint(route('cashier.webhook'), WebhookCommand::DEFAULT_EVENTS);
    $check = launchChecks($stripe)['stripe.webhook_endpoint'];
    expect($check->status)->toBe(LaunchCheckStatus::Fail)->and($check->detail)->toContain('checkout.session.completed')->toContain('charge.refunded');

    $stripe->endpoints = [];
    $stripe->withEndpoint(route('cashier.webhook'), ['*'], status: 'disabled');
    expect(launchChecks($stripe)['stripe.webhook_endpoint']->detail)->toContain('vypnutý');

    $stripe->endpoints = [];
    $stripe->withEndpoint('https://other.example/stripe/webhook', ['*']);
    expect(launchChecks($stripe)['stripe.webhook_endpoint']->detail)->toContain('žiadny endpoint');

    // Catalogue selling an ID different from the environment is a blocker (a stale seeded ID).
    $monthly->update(['stripe_price_id' => 'price_old']);
    expect(launchChecks()['catalog.env_match']->status)->toBe(LaunchCheckStatus::Fail);

    // Live keys outside production never pass.
    config()->set('cashier.secret', 'sk_live_abc');
    config()->set('cashier.key', 'pk_live_abc');
    expect(launchChecks()['stripe.keys']->detail)->toContain('živé kľúče mimo produkcie');
});

it('lets the administrator confirm and withdraw manual sign-offs with an audit trail on the launch page', function () {
    household();
    $this->get(route('admin.launch'))->assertForbidden();

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.launch'))->assertOk()->assertSee('Launch checklist')->assertSee('Launch je blokovaný')->assertSee('Ceny a limity potvrdené');

    $page = Livewire::test('pages::admin.launch')
        ->call('confirm', 'prices')->assertHasErrors(['notes.prices'])
        ->set('notes.prices', 'Ceny potvrdil prevádzkovateľ 26. 9. 2026')
        ->call('confirm', 'prices')->assertHasNoErrors()
        ->assertSee('Ceny potvrdil prevádzkovateľ');

    $signoffs = app(LaunchSignoffs::class);
    expect($signoffs->isConfirmed('prices'))->toBeTrue()
        ->and($signoffs->get('prices')['by'])->toBe($admin->id)
        ->and(AdminAudit::query()->where('action', 'launch.signoff.confirmed')->where('target_type', 'launch:prices')->where('actor_id', $admin->id)->exists())->toBeTrue()
        ->and(launchChecks()['signoff.prices']->status)->toBe(LaunchCheckStatus::Ok);

    $page->call('withdraw', 'prices')->assertHasErrors(['notes.prices'])
        ->set('notes.prices', 'účtovník žiada prepočet')
        ->call('withdraw', 'prices')->assertHasNoErrors();
    expect($signoffs->isConfirmed('prices'))->toBeFalse()
        ->and(AdminAudit::query()->where('action', 'launch.signoff.withdrawn')->exists())->toBeTrue();

    expect(fn () => $signoffs->confirm('unknown', $admin, 'x'))->toThrow(InvalidArgumentException::class);

    // "Verify in Stripe" runs the checks with the inspector and shows the verdicts.
    launchInfrastructure();
    app()->instance(StripeInspector::class, (new FakeStripeInspector)
        ->withPrice('price_plus_monthly', 249, 'month')->withPrice('price_plus_yearly', 2400, 'year')
        ->withPrice('price_images_20', 399, null)->withPrice('price_text_100', 199, null)
        ->withEndpoint(route('cashier.webhook'), ['*']));
    Livewire::test('pages::admin.launch')->assertSee('neoverené proti Stripe')->call('verifyStripe')->assertSee('sedí')->assertDontSee('neoverené proti Stripe');
});
