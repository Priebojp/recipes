<?php

namespace Tests\Support;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Order;
use App\Models\Person;
use App\Models\User;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Usage\UsageProvisioner;
use Database\Seeders\CatalogSeeder;

/**
 * Shared arrangement for billing feature tests: seeded catalogue, in-memory Stripe, an owner household.
 */
class BillingScenario
{
    /** @return array{user: User, household: Household, person: Person, stripe: FakeStripeGateway} */
    public static function start(): array
    {
        test()->seed(CatalogSeeder::class);
        $stripe = new FakeStripeGateway;
        app()->instance(StripeGateway::class, $stripe);

        $h = household();
        $h['stripe'] = $stripe;
        app(UsageProvisioner::class)->ensureFor($h['household']); // the verified owner already holds the trial

        return $h;
    }

    /** A plain member (not owner) of the household, logged in. */
    public static function actingAsMember(array $h): User
    {
        $member = User::factory()->create();
        HouseholdMembership::create(['household_id' => $h['household']->id, 'user_id' => $member->id, 'role' => MembershipRole::Member]);
        test()->actingAs($member);

        return $member;
    }

    public static function startPlan(string $plan = 'plus_monthly'): Order
    {
        test()->post(route('checkout.plan'), ['plan' => $plan])->assertRedirectContains('checkout.stripe.test');

        return Order::query()->latest('id')->firstOrFail();
    }

    public static function startAddon(string $addon = 'images_20_standard'): Order
    {
        test()->post(route('checkout.addon'), ['addon' => $addon])->assertRedirectContains('checkout.stripe.test');

        return Order::query()->latest('id')->firstOrFail();
    }

    /** Stripe's three messages for a paid first subscription period. */
    public static function paySubscription(Order $order, int $periodStart, int $periodEnd, ?string $invoiceId = null): void
    {
        $account = $order->billingAccount;
        $subscription = 'sub_'.$order->id;
        $priceId = (string) $order->product_snapshot['stripe_price_id'];

        StripePayloads::post(test(), StripePayloads::subscriptionCreated($account, $subscription, $priceId))->assertOk();
        StripePayloads::post(test(), StripePayloads::checkoutCompleted($order, 'subscription'))->assertOk();
        StripePayloads::post(test(), StripePayloads::invoicePaid($account, $invoiceId ?? 'in_'.$order->id.'_1', $subscription, $priceId, $periodStart, $periodEnd, 'subscription_create', orderId: $order->id))->assertOk();
    }

    public static function payAddon(Order $order): void
    {
        StripePayloads::post(test(), StripePayloads::checkoutCompleted($order, 'payment'))->assertOk();
    }
}
