<?php

namespace Tests\Support;

use App\Services\Billing\Gateway\StripeInspector;

/**
 * Scripted view of a Stripe account for the launch check.
 */
class FakeStripeInspector implements StripeInspector
{
    /** @var array<string, array{id: string, active: bool, livemode: bool, currency: string, unit_amount: int|null, interval: string|null}> */
    public array $prices = [];

    /** @var list<array{id: string, url: string, status: string, livemode: bool, enabled_events: list<string>, api_version: string|null}> */
    public array $endpoints = [];

    public function price(string $priceId): ?array
    {
        return $this->prices[$priceId] ?? null;
    }

    public function webhookEndpoints(): array
    {
        return $this->endpoints;
    }

    /** Convenience: a price row matching the catalogue. */
    public function withPrice(string $id, int $cents, ?string $interval, bool $live = false, bool $active = true, string $currency = 'eur'): static
    {
        $this->prices[$id] = ['id' => $id, 'active' => $active, 'livemode' => $live, 'currency' => $currency, 'unit_amount' => $cents, 'interval' => $interval];

        return $this;
    }

    /** @param list<string> $events */
    public function withEndpoint(string $url, array $events, string $status = 'enabled', bool $live = false): static
    {
        $this->endpoints[] = ['id' => 'we_'.(count($this->endpoints) + 1), 'url' => $url, 'status' => $status, 'livemode' => $live, 'enabled_events' => $events, 'api_version' => null];

        return $this;
    }
}
