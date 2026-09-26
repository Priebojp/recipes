<?php

use App\Enums\StripeEventState;
use App\Models\AdminAudit;
use App\Models\BillingAccount;
use App\Models\PaidEntitlement;
use App\Models\StripeEvent;
use Livewire\Livewire;
use Tests\Support\BillingScenario;
use Tests\Support\StripePayloads as Stripe;

it('lists failed webhook events and lets the administrator process them again once the cause is fixed', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_monthly');
    $account = $order->billingAccount;

    // The invoice arrives for a customer we do not know yet → the event fails and waits.
    $ghost = new BillingAccount(['household_id' => $h['household']->id]);
    $ghost->stripe_id = 'cus_not_yet_synced';
    Stripe::post($this, Stripe::invoicePaid($ghost, 'in_late', 'sub_'.$order->id, 'price_plus_monthly', now()->timestamp, now()->addMonth()->timestamp, 'subscription_create', orderId: $order->id))->assertOk();

    $event = StripeEvent::query()->where('event_id', '!=', '')->latest('id')->firstOrFail();
    expect($event->state)->toBe(StripeEventState::Failed)
        ->and($event->attempts)->toBe(1)
        ->and(PaidEntitlement::count())->toBe(0);

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.stripe-events', ['state' => 'failed']))->assertOk()->assertSee($event->event_id)->assertSee('Neznámy Stripe zákazník');
    $this->get(route('admin.index'))->assertOk()->assertSee('1 zlyhaných Stripe udalostí');

    // Retry before the fix: still failed, attempts grow, nothing granted.
    Livewire::test('pages::admin.stripe-events')->call('retry', $event->id);
    expect($event->fresh()->state)->toBe(StripeEventState::Failed)->and($event->fresh()->attempts)->toBe(2);

    $account->forceFill(['stripe_id' => 'cus_not_yet_synced'])->save();
    Livewire::test('pages::admin.stripe-events')->call('retry', $event->id);
    Livewire::test('pages::admin.stripe-events')->call('retry', $event->id); // a processed event is not run again

    expect($event->fresh()->state)->toBe(StripeEventState::Processed)
        ->and($event->fresh()->attempts)->toBe(3)
        ->and(PaidEntitlement::count())->toBe(1)
        ->and(AdminAudit::query()->where('action', 'billing.stripe_event.retried')->where('actor_id', $admin->id)->count())->toBe(2);
});

it('syncs and cancels a subscription from the subscriptions page through the gateway with an audited reason', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_yearly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addYear()->timestamp);
    $subscription = $order->billingAccount->subscription('default');
    $h['stripe']->syncStatus = 'past_due';
    actingAsPlatformAdmin();

    Livewire::test('pages::admin.subscriptions')
        ->assertSee('sub_'.$order->id)
        ->call('sync', $subscription->id)
        ->call('cancelRenewal', $subscription->id)
        ->assertHasErrors(['reason'])
        ->set('reason', 'Zákazník požiadal telefonicky')
        ->call('cancelRenewal', $subscription->id)
        ->assertHasNoErrors()
        ->set('reason', 'Rozmyslel si to')
        ->call('resumeRenewal', $subscription->id)
        ->assertHasNoErrors();

    $audit = AdminAudit::query()->where('action', 'billing.subscription.synced')->sole();
    expect($subscription->fresh()->stripe_status)->toBe('past_due')
        ->and($audit->changes['before']['stripe_status'])->toBe('active')
        ->and($audit->changes['after']['stripe_status'])->toBe('past_due')
        ->and($h['stripe']->canceled)->toBe(['sub_'.$order->id])
        ->and($h['stripe']->resumed)->toBe(['sub_'.$order->id])
        ->and(AdminAudit::query()->where('action', 'billing.subscription.renewal_canceled')->sole()->reason)->toBe('Zákazník požiadal telefonicky')
        ->and(AdminAudit::query()->where('action', 'billing.subscription.renewal_resumed')->exists())->toBeTrue();

    $this->get(route('admin.subscriptions', ['status' => 'past_due']))->assertOk()->assertSee('sub_'.$order->id);
    $this->get(route('admin.subscriptions', ['status' => 'canceled']))->assertOk()->assertDontSee('sub_'.$order->id);
});
