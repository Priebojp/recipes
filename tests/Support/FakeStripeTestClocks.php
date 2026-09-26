<?php

namespace Tests\Support;

use App\Models\BillingAccount;
use App\Services\Billing\Gateway\StripeTestClocks;
use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription;

/**
 * In-memory test clocks: records what the simulation asked for and stores the local Cashier subscription row the
 * way Cashier would after creating it in Stripe.
 */
class FakeStripeTestClocks implements StripeTestClocks
{
    /** @var array<string, array{id: string, name: string|null, frozen_time: int, status: string}> */
    public array $clocks = [];

    /** @var list<array<string, mixed>> */
    public array $subscriptions = [];

    /** @var list<array{clock: string, to: int}> */
    public array $advances = [];

    /** @var list<string> */
    public array $deleted = [];

    private int $sequence = 0;

    public function create(string $name, CarbonInterface $frozenTime): array
    {
        $id = 'clock_'.(++$this->sequence);
        $this->clocks[$id] = ['id' => $id, 'name' => $name, 'frozen_time' => $frozenTime->getTimestamp(), 'status' => 'ready'];

        return $this->public($id);
    }

    public function startSubscription(BillingAccount $account, string $priceId, string $clockId, array $metadata): array
    {
        $n = ++$this->sequence;
        $account->forceFill(['stripe_id' => 'cus_clock_'.$n])->save();

        $subscription = $account->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_clock_'.$n,
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);
        $subscription->items()->create(['stripe_id' => 'si_clock_'.$n, 'stripe_product' => 'prod_test', 'stripe_price' => $priceId, 'quantity' => 1]);

        $this->subscriptions[] = ['account' => $account->id, 'price' => $priceId, 'clock' => $clockId, 'metadata' => $metadata];

        return ['customer' => 'cus_clock_'.$n, 'subscription' => 'sub_clock_'.$n, 'status' => 'active'];
    }

    public function advance(string $clockId, CarbonInterface $to): array
    {
        $this->advances[] = ['clock' => $clockId, 'to' => $to->getTimestamp()];
        $this->clocks[$clockId]['frozen_time'] = $to->getTimestamp();

        return $this->public($clockId);
    }

    public function retrieve(string $clockId): ?array
    {
        return isset($this->clocks[$clockId]) ? $this->public($clockId) : null;
    }

    public function delete(string $clockId): void
    {
        $this->deleted[] = $clockId;
        unset($this->clocks[$clockId]);
    }

    public function all(): array
    {
        return array_values(array_map(fn (array $c) => ['name' => $c['name'], ...$this->public($c['id'])], $this->clocks));
    }

    /** @return array{id: string, frozen_time: int, status: string} */
    private function public(string $id): array
    {
        $c = $this->clocks[$id];

        return ['id' => $c['id'], 'frozen_time' => $c['frozen_time'], 'status' => $c['status']];
    }
}
