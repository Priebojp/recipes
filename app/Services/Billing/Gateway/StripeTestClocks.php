<?php

namespace App\Services\Billing\Gateway;

use App\Models\BillingAccount;
use Carbon\CarbonInterface;

/**
 * Stripe test clocks (sandbox only): a frozen customer whose subscription is advanced through time so renewals,
 * monthly grants and cancellations can be verified against the real Stripe account before launch.
 */
interface StripeTestClocks
{
    /** @return array{id: string, frozen_time: int, status: string} */
    public function create(string $name, CarbonInterface $frozenTime): array;

    /**
     * Create the Stripe customer on the clock (with a test card as default payment method) and start a paid
     * subscription for the given price. Returns Stripe IDs; the local Cashier subscription row is stored too.
     *
     * @param  array<string, string>  $metadata
     * @return array{customer: string, subscription: string, status: string}
     */
    public function startSubscription(BillingAccount $account, string $priceId, string $clockId, array $metadata): array;

    /** @return array{id: string, frozen_time: int, status: string} */
    public function advance(string $clockId, CarbonInterface $to): array;

    /** @return array{id: string, frozen_time: int, status: string}|null */
    public function retrieve(string $clockId): ?array;

    public function delete(string $clockId): void;

    /** @return list<array{id: string, name: string|null, frozen_time: int, status: string}> */
    public function all(): array;
}
