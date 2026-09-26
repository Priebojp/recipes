<?php

namespace App\Services\Billing\Gateway;

use App\Models\BillingAccount;
use Laravel\Cashier\Subscription;

/**
 * Every call that leaves for Stripe goes through here, so the application flow can be tested without the network.
 */
interface StripeGateway
{
    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string}
     */
    public function createSubscriptionCheckout(BillingAccount $account, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array;

    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string}
     */
    public function createPaymentCheckout(BillingAccount $account, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array;

    public function billingPortalUrl(BillingAccount $account, string $returnUrl): string;

    public function cancelRenewal(Subscription $subscription): void;

    public function resumeRenewal(Subscription $subscription): void;

    /** Pull the subscription's current status / period end from Stripe into the local Cashier row. */
    public function syncSubscription(Subscription $subscription): void;

    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, status: string}
     */
    public function refund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey, array $metadata): array;

    /** @return array<string, mixed>|null */
    public function retrieveCheckoutSession(string $sessionId): ?array;
}
