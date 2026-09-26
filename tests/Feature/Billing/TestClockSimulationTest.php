<?php

use App\Enums\UsageGrantSource;
use App\Models\AdminAudit;
use App\Models\PaidEntitlement;
use App\Models\UsageGrant;
use App\Models\User;
use App\Services\Billing\Gateway\StripeTestClocks;
use App\Services\Billing\TestClockSimulation;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Tests\Support\BillingScenario;
use Tests\Support\FakeStripeTestClocks;
use Tests\Support\StripePayloads;

it('starts a frozen yearly subscription for a household, opens the monthly grant after advancing and refuses unsafe cases', function () {
    $h = BillingScenario::start();
    $clocks = new FakeStripeTestClocks;
    app()->instance(StripeTestClocks::class, $clocks);
    config()->set('cashier.secret', 'sk_test_abc');
    $tz = config('recipes.billing.timezone');
    // Stripe's clock is frozen in the past while the application keeps real time (frozen here for determinism).
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00', $tz));

    $this->artisan('app:billing-test-clock', ['action' => 'start', 'target' => $h['household']->id, '--plan' => 'plus_yearly', '--at' => '2026-01-31 10:00'])
        ->assertSuccessful()
        ->expectsOutputToContain('clock_1');

    $account = $h['household']->billingAccount()->firstOrFail();
    expect($account->stripe_id)->toStartWith('cus_clock_')
        ->and($clocks->clocks['clock_1']['frozen_time'])->toBe(CarbonImmutable::parse('2026-01-31 10:00', $tz)->getTimestamp())
        ->and($clocks->subscriptions[0]['price'])->toBe('price_plus_yearly')
        ->and($clocks->subscriptions[0]['metadata']['household_id'])->toBe((string) $h['household']->id)
        ->and(app(TestClockSimulation::class)->registry())->toHaveKey('clock_1')
        ->and(AdminAudit::query()->where('action', 'billing.test_clock.started')->exists())->toBeTrue();

    // Stripe pays the first invoice on the clock; the webhook creates the paid year like in production.
    $start = CarbonImmutable::parse('2026-01-31 10:00', $tz);
    StripePayloads::post($this, StripePayloads::invoicePaid($account->fresh(), 'in_clock_1', 'sub_clock_2', 'price_plus_yearly', $start->getTimestamp(), $start->addYear()->getTimestamp(), 'subscription_create'))->assertOk();
    expect(PaidEntitlement::query()->where('household_id', $h['household']->id)->count())->toBe(1)
        ->and(UsageGrant::query()->where('household_id', $h['household']->id)->where('source', UsageGrantSource::Subscription)->count())->toBe(2); // the interval containing "now" (31. 8. – 30. 9.) opened

    // Advancing into the next month opens the following interval (anchor 31st, clipped to the 30th) without duplicating the current one.
    $this->artisan('app:billing-test-clock', ['action' => 'advance', 'target' => 'clock_1', '--at' => '2026-10-05 12:00', '--wait' => 0])->assertSuccessful();
    expect($clocks->advances[0]['to'])->toBe(CarbonImmutable::parse('2026-10-05 12:00', $tz)->getTimestamp());
    $grants = UsageGrant::query()->where('household_id', $h['household']->id)->where('source', UsageGrantSource::Subscription)->orderBy('valid_from')->get();
    expect($grants)->toHaveCount(4)
        ->and($grants->pluck('valid_from')->map(fn ($d) => $d->timezone($tz)->format('Y-m-d'))->unique()->values()->all())->toBe(['2026-08-31', '2026-09-30']);

    $this->artisan('app:billing-test-clock', ['action' => 'status', 'target' => 'clock_1'])->assertSuccessful()->expectsOutputToContain('sub_clock_2')->expectsOutputToContain('in_clock_1');
    $this->artisan('app:billing-test-clock', ['action' => 'list'])->assertSuccessful()->expectsOutputToContain('plus_yearly');

    // The same household cannot get a second simulation; a live key refuses everything.
    $this->artisan('app:billing-test-clock', ['action' => 'start', 'target' => $h['household']->id])->assertExitCode(2);

    $other = CurrentHousehold::createFor(User::factory()->create());
    config()->set('cashier.secret', 'sk_live_abc');
    $this->artisan('app:billing-test-clock', ['action' => 'start', 'target' => $other->id])->assertExitCode(2);
    config()->set('cashier.secret', 'sk_test_abc');

    $this->artisan('app:billing-test-clock', ['action' => 'delete', 'target' => 'clock_1'])->assertSuccessful();
    expect($clocks->deleted)->toBe(['clock_1'])
        ->and(app(TestClockSimulation::class)->registry())->toBe([])
        ->and(PaidEntitlement::query()->where('household_id', $h['household']->id)->count())->toBe(1); // local rows stay for inspection
});
