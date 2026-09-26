<?php

use App\Enums\OrderStatus;
use App\Enums\StripeEventState;
use App\Enums\UsageKind;
use App\Livewire\AiTextAssistant;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use App\Models\Recipe;
use App\Models\StripeEvent;
use App\Models\UsageGrant;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\PlanStatus;
use App\Services\Usage\UsageLedger;
use Livewire\Livewire;
use Tests\Support\BillingScenario;
use Tests\Support\StripePayloads as Stripe;

it('rejects an invalid signature and stores nothing', function () {
    BillingScenario::start();
    $order = BillingScenario::startAddon();

    Stripe::post($this, Stripe::checkoutCompleted($order, 'payment'), secret: 'whsec_wrong')->assertForbidden();
    $this->postJson(route('cashier.webhook'), Stripe::checkoutCompleted($order, 'payment'))->assertForbidden();

    expect(StripeEvent::count())->toBe(0)->and(UsageGrant::query()->whereNot('source', 'trial')->count())->toBe(0);
});

it('adds a purchased pack once although Stripe repeats the event and sends several events about the same payment', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('images_20_standard');
    $completed = Stripe::checkoutCompleted($order, 'payment');

    Stripe::post($this, $completed)->assertOk();
    Stripe::post($this, $completed)->assertOk()->assertSee('Duplicate');
    Stripe::post($this, Stripe::asyncPaymentSucceeded($order, 'payment'))->assertOk();

    expect(StripeEvent::count())->toBe(2)
        ->and(StripeEvent::query()->where('state', StripeEventState::Processed)->count())->toBe(2)
        ->and(UsageGrant::query()->where('source', 'addon')->count())->toBe(1)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::ImageStandard))->toBe(21) // 1 trial + 20
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(UsageGrant::query()->where('source', 'addon')->sole()->order_id)->toBe($order->id);
});

it('activates Plus and the monthly grant only through webhooks, never through the success URL', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_monthly');
    $plans = app(PlanStatus::class);

    $this->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))->assertOk();
    expect($plans->isPlus($h['household']))->toBeFalse();

    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);
    // Stripe also sends the legacy event name for the same invoice.
    Stripe::post($this, Stripe::invoicePaid($order->billingAccount, 'in_'.$order->id.'_1', 'sub_'.$order->id, 'price_plus_monthly', now()->timestamp, now()->addMonth()->timestamp, 'subscription_create', 'invoice.payment_succeeded'))->assertOk();

    $ledger = app(UsageLedger::class);
    expect($plans->isPlus($h['household']))->toBeTrue()
        ->and($plans->paidThrough($h['household'])->timestamp)->toBe(now()->addMonth()->timestamp)
        ->and(PaidEntitlement::count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()->stripe_payment_intent_id)->toBe('pi_in_'.$order->id.'_1')
        ->and($ledger->balance($h['household'], UsageKind::Text)->includedTotal)->toBe(33) // trial 3 + monthly 30
        ->and($ledger->balance($h['household'], UsageKind::ImageStandard)->includedTotal)->toBe(6)
        ->and(UsageGrant::query()->where('paid_entitlement_id', PaidEntitlement::sole()->id)->count())->toBe(2);

    $this->get(route('subscription.edit'))->assertOk()->assertSee('Plus')->assertSee('Zaplatené do')->assertSee('Zrušiť obnovovanie');
    $this->get(route('usage.index'))->assertOk()->assertSee('Predplatné');
});

it('adds no uses for an incomplete or failed payment and activates once the payment succeeds', function () {
    $h = BillingScenario::start();
    $addon = BillingScenario::startAddon('text_100');

    $purchased = fn () => UsageGrant::query()->whereNot('source', 'trial')->count();
    Stripe::post($this, Stripe::checkoutCompleted($addon, 'payment', 'unpaid'))->assertOk();
    expect($purchased())->toBe(0)->and($addon->fresh()->status)->toBe(OrderStatus::Pending);

    Stripe::post($this, Stripe::asyncPaymentFailed($addon, 'payment'))->assertOk();
    expect($addon->fresh()->status)->toBe(OrderStatus::Failed)->and($purchased())->toBe(0);

    $retry = BillingScenario::startAddon('text_100');
    Stripe::post($this, Stripe::checkoutCompleted($retry, 'payment', 'unpaid'))->assertOk();
    Stripe::post($this, Stripe::asyncPaymentSucceeded($retry, 'payment'))->assertOk();
    expect(app(UsageLedger::class)->balance($h['household'], UsageKind::Text)->purchasedAvailable)->toBe(100);

    // An unpaid subscription invoice (SCA pending) creates no paid period.
    $plan = BillingScenario::startPlan();
    Stripe::post($this, Stripe::invoicePaid($plan->billingAccount, 'in_open', 'sub_'.$plan->id, 'price_plus_monthly', now()->timestamp, now()->addMonth()->timestamp, 'subscription_create', paid: false))->assertOk();
    expect(PaidEntitlement::count())->toBe(0)->and(app(PlanStatus::class)->isPlus($h['household']))->toBeFalse();
});

it('keeps a purchased pack through renewals and after Plus ends, with recipes still accessible', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $ledger = app(UsageLedger::class);

    $addon = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($addon);
    $plan = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($plan, now()->timestamp, now()->addMonth()->timestamp);

    $this->travel(1)->month();
    Stripe::post($this, Stripe::invoicePaid($plan->billingAccount, 'in_renewal', 'sub_'.$plan->id, 'price_plus_monthly', now()->timestamp, now()->addMonth()->timestamp))->assertOk();

    $balance = $ledger->balance($h['household'], UsageKind::Text);
    expect($balance->purchasedAvailable)->toBe(100)
        ->and($balance->includedAvailable)->toBe(33) // trial 3 + the new month's 30; the first month's 30 expired
        ->and(PaidEntitlement::count())->toBe(2);

    $this->travel(2)->months();
    expect(app(PlanStatus::class)->isPlus($h['household']))->toBeFalse()
        ->and($ledger->balance($h['household'], UsageKind::Text)->purchasedAvailable)->toBe(100)
        ->and($ledger->balance($h['household'], UsageKind::Text)->includedAvailable)->toBe(3); // only the trial is left
    $this->get(route('recipes.index'))->assertOk();
});

it('does not add a second grant for a plan change at the period boundary and none for an unpaid renewal', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $plan = BillingScenario::startPlan('plus_monthly');
    $account = $plan->billingAccount;
    $sub = 'sub_'.$plan->id;
    BillingScenario::paySubscription($plan, now()->timestamp, now()->addMonth()->timestamp);

    $this->travel(1)->month();
    $start = now()->timestamp;
    // Renewal invoice and the "swap" invoice for the same period start.
    Stripe::post($this, Stripe::invoicePaid($account, 'in_cycle', $sub, 'price_plus_monthly', $start, now()->addMonth()->timestamp))->assertOk();
    Stripe::post($this, Stripe::invoicePaid($account, 'in_swap', $sub, 'price_plus_monthly', $start, now()->addMonth()->timestamp, 'subscription_update'))->assertOk();

    expect(UsageGrant::query()->where('source', 'subscription')->where('kind', 'text')->count())->toBe(2) // month 1 + month 2
        ->and(app(UsageLedger::class)->balance($h['household'], UsageKind::Text)->includedAvailable)->toBe(33);

    $this->travel(1)->month();
    Stripe::post($this, Stripe::invoicePaymentFailed($account, 'in_failed', $sub))->assertOk();
    $account->subscription('default')->update(['stripe_status' => 'past_due']);

    $plans = app(PlanStatus::class);
    expect(app(UsageLedger::class)->balance($h['household'], UsageKind::Text)->includedAvailable)->toBe(3) // trial only: no new monthly uses
        ->and($plans->isPlus($h['household']))->toBeTrue() // 3-day tolerance for Plus features
        ->and($plans->inRenewalGrace($h['household']))->toBeTrue();
    $this->get(route('subscription.edit'))->assertOk()->assertSee('obnova zlyhala');

    $this->travel(4)->days();
    expect($plans->isPlus($h['household']))->toBeFalse()->and(UsageGrant::query()->where('source', 'subscription')->count())->toBe(4);
});

it('keeps the bought snapshot, paid period and old-price invoices unchanged when a new price version appears', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);
    $oldVersion = PlanVersion::query()->where('code', 'plus_monthly')->sole();

    $oldVersion->update(['state' => 'retired', 'retired_at' => now()]);
    PlanVersion::create([...$oldVersion->only(['code', 'product_code', 'name', 'interval', 'currency', 'text_uses_per_period', 'image_uses_per_period', 'features']), 'version' => 2, 'final_price_cents' => 299, 'stripe_price_id' => 'price_plus_monthly_v2', 'state' => 'active', 'effective_from' => now()]);

    $this->travel(1)->month();
    Stripe::post($this, Stripe::invoicePaid($order->billingAccount, 'in_renewal', 'sub_'.$order->id, 'price_plus_monthly', now()->timestamp, now()->addMonth()->timestamp))->assertOk();

    expect($order->fresh()->amount_cents)->toBe(249)
        ->and($order->fresh()->product_snapshot['final_price_cents'])->toBe(249)
        ->and(PaidEntitlement::query()->pluck('plan_version_id')->unique()->all())->toBe([$oldVersion->id])
        ->and(app(PlanStatus::class)->isPlus($h['household']))->toBeTrue();
    $this->get(route('pricing'))->assertSee('2,99 €');
});

it('opens yearly monthly grants only for the current interval, also after a scheduler outage, and cancelling renewal does not stop them', function () {
    $h = BillingScenario::start();
    $this->travelTo(now()->parse('2027-01-31 10:00', 'Europe/Bratislava'));
    $order = BillingScenario::startPlan('plus_yearly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addYear()->timestamp);

    $textGrants = fn () => UsageGrant::query()->where('source', 'subscription')->where('kind', 'text')->orderBy('valid_from')->get();
    expect($textGrants())->toHaveCount(1)->and($textGrants()[0]->quantity)->toBe(30)->and($textGrants()[0]->expires_at->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-02-28 10:00');

    // The owner cancels renewal in settings: Plus and its monthly grants continue to the paid end.
    Livewire::test('pages::settings.subscription')->call('cancelRenewal')->assertSee('Obnovovanie je zrušené');
    expect($h['stripe']->canceled)->toBe(['sub_'.$order->id])->and($order->billingAccount->subscription('default')->onGracePeriod())->toBeTrue();

    // Nobody ran the scheduler for three months; the first use opens only the current month, never the skipped ones.
    $this->travelTo(now()->parse('2027-05-05 12:00', 'Europe/Bratislava'));
    Livewire::test(AiTextAssistant::class, ['recipeId' => Recipe::factory()->create(['household_id' => $h['household']->id])->id])->assertSee('zostáva 33');
    app(BillingReconciler::class)->run();

    $grants = $textGrants();
    expect($grants)->toHaveCount(2)
        ->and($grants[1]->valid_from->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-04-30 10:00')
        ->and($grants[1]->expires_at->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-05-31 10:00')
        ->and(app(PlanStatus::class)->isPlus($h['household']))->toBeTrue();
});
