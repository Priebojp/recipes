<?php

namespace App\Services\Billing\Gateway;

use App\Models\BillingAccount;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Stripe\Exception\InvalidRequestException;

class CashierStripeGateway implements StripeGateway
{
    public function createSubscriptionCheckout(BillingAccount $account, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array
    {
        $session = $account->newSubscription('default', $priceId)
            ->withMetadata($metadata)
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
                'locale' => 'sk',
            ])
            ->asStripeCheckoutSession();

        return ['id' => (string) $session->id, 'url' => (string) $session->url];
    }

    public function createPaymentCheckout(BillingAccount $account, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array
    {
        $session = $account->checkout([$priceId => 1], [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'locale' => 'sk',
            'invoice_creation' => ['enabled' => true],
            'payment_intent_data' => ['metadata' => $metadata],
        ])->asStripeCheckoutSession();

        return ['id' => (string) $session->id, 'url' => (string) $session->url];
    }

    public function billingPortalUrl(BillingAccount $account, string $returnUrl): string
    {
        return $account->billingPortalUrl($returnUrl);
    }

    public function cancelRenewal(Subscription $subscription): void
    {
        $subscription->cancel();
    }

    public function resumeRenewal(Subscription $subscription): void
    {
        $subscription->resume();
    }

    public function refund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey, array $metadata): array
    {
        $refund = Cashier::stripe()->refunds->create(array_filter([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountCents,
            'metadata' => $metadata,
        ]), ['idempotency_key' => $idempotencyKey]);

        return ['id' => (string) $refund->id, 'status' => (string) $refund->status];
    }

    public function retrieveCheckoutSession(string $sessionId): ?array
    {
        try {
            return Cashier::stripe()->checkout->sessions->retrieve($sessionId)->toArray();
        } catch (InvalidRequestException) {
            return null;
        }
    }
}
