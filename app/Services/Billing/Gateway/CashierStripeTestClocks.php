<?php

namespace App\Services\Billing\Gateway;

use App\Models\BillingAccount;
use Carbon\CarbonInterface;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;
use Stripe\TestHelpers\TestClock;

class CashierStripeTestClocks implements StripeTestClocks
{
    /** Stripe's reusable test card; never a real payment method. */
    public const TEST_PAYMENT_METHOD = 'pm_card_visa';

    public function create(string $name, CarbonInterface $frozenTime): array
    {
        return $this->clock(Cashier::stripe()->testHelpers->testClocks->create([
            'name' => mb_substr($name, 0, 300),
            'frozen_time' => $frozenTime->getTimestamp(),
        ]));
    }

    public function startSubscription(BillingAccount $account, string $priceId, string $clockId, array $metadata): array
    {
        // Cashier creates the customer (on the clock), attaches the test card as default payment method, creates the
        // subscription and stores the local row. error_if_incomplete: the first invoice is paid right away or we fail
        // loudly – a simulation must not leave an incomplete subscription behind.
        $subscription = $account->newSubscription('default', $priceId)
            ->withMetadata($metadata)
            ->create(self::TEST_PAYMENT_METHOD, ['test_clock' => $clockId], [
                'metadata' => $metadata,
                'payment_behavior' => 'error_if_incomplete',
            ]);

        return [
            'customer' => (string) $account->fresh()->stripe_id,
            'subscription' => (string) $subscription->stripe_id,
            'status' => (string) $subscription->stripe_status,
        ];
    }

    public function advance(string $clockId, CarbonInterface $to): array
    {
        return $this->clock(Cashier::stripe()->testHelpers->testClocks->advance($clockId, ['frozen_time' => $to->getTimestamp()]));
    }

    public function retrieve(string $clockId): ?array
    {
        try {
            return $this->clock(Cashier::stripe()->testHelpers->testClocks->retrieve($clockId));
        } catch (InvalidRequestException) {
            return null;
        }
    }

    public function delete(string $clockId): void
    {
        Cashier::stripe()->testHelpers->testClocks->delete($clockId);
    }

    public function all(): array
    {
        $clocks = [];
        foreach (Cashier::stripe()->testHelpers->testClocks->all(['limit' => 100])->autoPagingIterator() as $clock) {
            $clocks[] = ['name' => $clock->name !== null ? (string) $clock->name : null, ...$this->clock($clock)];
        }

        return $clocks;
    }

    /** @return array{id: string, frozen_time: int, status: string} */
    private function clock(TestClock $clock): array
    {
        return ['id' => (string) $clock->id, 'frozen_time' => (int) $clock->frozen_time, 'status' => (string) $clock->status];
    }
}
