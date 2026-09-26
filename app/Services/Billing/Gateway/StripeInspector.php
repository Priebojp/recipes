<?php

namespace App\Services\Billing\Gateway;

/**
 * Read-only look into the configured Stripe account for the launch check (prices, webhook endpoints). Separated
 * from StripeGateway so the check can be faked without the sales flow.
 */
interface StripeInspector
{
    /**
     * @return array{id: string, active: bool, livemode: bool, currency: string, unit_amount: int|null, interval: string|null}|null null when the price does not exist
     */
    public function price(string $priceId): ?array;

    /**
     * @return list<array{id: string, url: string, status: string, livemode: bool, enabled_events: list<string>, api_version: string|null}>
     */
    public function webhookEndpoints(): array;
}
