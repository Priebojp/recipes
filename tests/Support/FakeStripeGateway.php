<?php

namespace Tests\Support;

use App\Models\BillingAccount;
use App\Services\Billing\Gateway\StripeGateway;
use Laravel\Cashier\Subscription;
use RuntimeException;

/**
 * In-memory Stripe: records what the application asked for and answers with deterministic IDs.
 */
class FakeStripeGateway implements StripeGateway
{
    /** @var list<array<string, mixed>> */
    public array $checkouts = [];

    /** @var list<array<string, mixed>> */
    public array $refunds = [];

    /** @var list<string> */
    public array $canceled = [];

    /** @var list<string> */
    public array $resumed = [];

    public bool $failRefunds = false;

    private int $sequence = 0;

    public function createSubscriptionCheckout(BillingAccount $account, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array
    {
        return $this->session('subscription', $account, $priceId, $metadata);
    }

    public function createPaymentCheckout(BillingAccount $account, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array
    {
        return $this->session('payment', $account, $priceId, $metadata);
    }

    public function billingPortalUrl(BillingAccount $account, string $returnUrl): string
    {
        return 'https://billing.stripe.test/session/'.$account->id.'?return='.urlencode($returnUrl);
    }

    public function cancelRenewal(Subscription $subscription): void
    {
        $this->canceled[] = $subscription->stripe_id;
        $subscription->forceFill(['ends_at' => now()->addDays(10)])->save();
    }

    public function resumeRenewal(Subscription $subscription): void
    {
        $this->resumed[] = $subscription->stripe_id;
        $subscription->forceFill(['ends_at' => null])->save();
    }

    public function refund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey, array $metadata): array
    {
        if ($this->failRefunds) {
            throw new RuntimeException('Stripe: charge already refunded');
        }

        foreach ($this->refunds as $existing) {
            if ($existing['idempotency_key'] === $idempotencyKey) {
                return ['id' => $existing['id'], 'status' => 'succeeded'];
            }
        }

        $id = 're_fake_'.(++$this->sequence);
        $this->refunds[] = ['id' => $id, 'payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'idempotency_key' => $idempotencyKey, 'metadata' => $metadata];

        return ['id' => $id, 'status' => 'succeeded'];
    }

    public function retrieveCheckoutSession(string $sessionId): ?array
    {
        foreach ($this->checkouts as $checkout) {
            if ($checkout['id'] === $sessionId) {
                return ['id' => $sessionId, 'payment_status' => 'unpaid', 'status' => 'open'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string}
     */
    private function session(string $mode, BillingAccount $account, string $priceId, array $metadata): array
    {
        if (! $account->stripe_id) {
            $account->forceFill(['stripe_id' => 'cus_fake_'.$account->id])->save();
        }

        $id = 'cs_fake_'.(++$this->sequence);
        $this->checkouts[] = ['id' => $id, 'mode' => $mode, 'customer' => $account->stripe_id, 'price' => $priceId, 'metadata' => $metadata];

        return ['id' => $id, 'url' => 'https://checkout.stripe.test/'.$id];
    }
}
