<?php

namespace App\Services\Billing;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\Household;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\Gateway\StripeGateway;

/**
 * Starts hosted Stripe Checkout for an offer code. Price and product come from the catalogue snapshot;
 * the success URL never grants anything – webhooks do.
 */
class CheckoutService
{
    public function __construct(
        private Catalog $catalog,
        private BillingAccounts $accounts,
        private PlanStatus $plans,
        private StripeGateway $gateway,
    ) {}

    /**
     * @throws CheckoutException
     */
    public function startSubscription(Household $household, User $by, string $planCode): StartedCheckout
    {
        $plan = $this->catalog->plan($planCode);
        if ($plan === null || ! $plan->stripe_price_id) {
            throw new CheckoutException('Táto ponuka momentálne nie je dostupná.');
        }

        if ($this->plans->hasSubscription($household)) {
            throw new CheckoutException('Domácnosť už má predplatné Plus. Zmenu obdobia urobíš v správe platby.');
        }

        $account = $this->accounts->forHousehold($household, $by);

        $order = Order::create([
            'household_id' => $household->id,
            'billing_account_id' => $account->id,
            'created_by' => $by->id,
            'kind' => OrderKind::Subscription,
            'plan_version_id' => $plan->id,
            'product_snapshot' => $plan->snapshot(),
            'amount_cents' => $plan->final_price_cents,
            'currency' => $plan->currency,
            'status' => OrderStatus::Pending,
        ]);

        $session = $this->gateway->createSubscriptionCheckout(
            $account,
            $plan->stripe_price_id,
            route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            route('checkout.cancel', ['order' => $order->id]),
            $this->metadata($order),
        );

        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return new StartedCheckout($order, $session['url']);
    }

    /**
     * @throws CheckoutException
     */
    public function startAddon(Household $household, User $by, string $addonCode): StartedCheckout
    {
        $addon = $this->catalog->addon($addonCode);
        if ($addon === null || ! $addon->stripe_price_id) {
            throw new CheckoutException('Tento balík momentálne nie je dostupný.');
        }

        $account = $this->accounts->forHousehold($household, $by);

        $order = Order::create([
            'household_id' => $household->id,
            'billing_account_id' => $account->id,
            'created_by' => $by->id,
            'kind' => OrderKind::Addon,
            'addon_version_id' => $addon->id,
            'product_snapshot' => $addon->snapshot(),
            'amount_cents' => $addon->final_price_cents,
            'currency' => $addon->currency,
            'status' => OrderStatus::Pending,
        ]);

        $session = $this->gateway->createPaymentCheckout(
            $account,
            $addon->stripe_price_id,
            route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            route('checkout.cancel', ['order' => $order->id]),
            $this->metadata($order),
        );

        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return new StartedCheckout($order, $session['url']);
    }

    /** The customer left Checkout: a pending order is closed, nothing else changes. */
    public function cancel(Order $order): void
    {
        if ($order->status === OrderStatus::Pending) {
            $order->update(['status' => OrderStatus::Canceled]);
        }
    }

    /** @return array<string, string> */
    private function metadata(Order $order): array
    {
        return ['order_id' => (string) $order->id, 'household_id' => (string) $order->household_id];
    }
}
