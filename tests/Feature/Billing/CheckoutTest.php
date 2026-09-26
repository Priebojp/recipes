<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlanVersion;
use App\Models\UsageGrant;
use App\Services\Billing\PlanStatus;
use Tests\Support\BillingScenario;

it('lets only the household owner start a checkout or open the billing portal', function () {
    $h = BillingScenario::start();
    BillingScenario::actingAsMember($h);

    $this->post(route('checkout.plan'), ['plan' => 'plus_monthly'])->assertForbidden();
    $this->post(route('checkout.addon'), ['addon' => 'text_100'])->assertForbidden();
    $this->get(route('billing.portal'))->assertForbidden();
    expect(Order::count())->toBe(0);

    $this->actingAs($h['user']);
    $order = BillingScenario::startPlan('plus_monthly');

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->amount_cents)->toBe(249)
        ->and($order->product_snapshot['stripe_price_id'])->toBe('price_plus_monthly')
        ->and($order->created_by)->toBe($h['user']->id)
        ->and($h['stripe']->checkouts[0]['metadata']['order_id'])->toBe((string) $order->id)
        ->and($h['stripe']->checkouts[0]['price'])->toBe('price_plus_monthly');

    $this->get(route('billing.portal'))->assertRedirectContains('billing.stripe.test');
});

it('rejects client-side prices and unknown offers; the amount always comes from the catalogue', function () {
    $h = BillingScenario::start();

    $this->post(route('checkout.plan'), ['plan' => 'plus_monthly', 'price_id' => 'price_cheap', 'amount' => 1, ...BillingScenario::termsInput()])->assertRedirectContains('checkout.stripe.test');
    expect(Order::sole()->amount_cents)->toBe(249)->and($h['stripe']->checkouts[0]['price'])->toBe('price_plus_monthly');

    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_free', ...BillingScenario::termsInput()])->assertSessionHasErrors('plan');
    $this->from(route('pricing'))->post(route('checkout.addon'), ['addon' => 'images_high', ...BillingScenario::termsInput()])->assertSessionHasErrors('addon');

    PlanVersion::query()->where('code', 'plus_yearly')->update(['stripe_price_id' => null]);
    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_yearly', ...BillingScenario::termsInput()])->assertSessionHasErrors('plan');
    expect(Order::count())->toBe(1);
});

it('refuses a second subscription while one is alive and shows the return page without activating anything', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startPlan();

    $this->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))->assertOk()->assertSee('Platbu overujeme');
    expect(app(PlanStatus::class)->isPlus($h['household']))->toBeFalse()->and(UsageGrant::query()->whereNot('source', 'trial')->count())->toBe(0);

    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);

    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_yearly', ...BillingScenario::termsInput()])->assertSessionHasErrors('plan');
    $this->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))->assertOk()->assertSee('Zaplatené');
});

it('closes a pending order when the customer leaves Checkout and grants nothing', function () {
    BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');

    $this->get(route('checkout.cancel', $order))->assertRedirect(route('subscription.edit'));

    expect($order->fresh()->status)->toBe(OrderStatus::Canceled)->and(UsageGrant::query()->whereNot('source', 'trial')->count())->toBe(0);
});

it('renders the pricing page with the yearly amount and the settings subscription page for Free', function () {
    BillingScenario::start();

    $this->get(route('pricing', ['interval' => 'year']))->assertOk()->assertSee('24,00 €')->assertSee('účtovaných raz ročne')->assertSee('2,00 €');
    $this->get(route('subscription.edit'))->assertOk()->assertSee('Free')->assertSee('Získať Plus');
});
