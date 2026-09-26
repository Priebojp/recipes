<?php

namespace App\Services\Billing\Gateway;

use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;

class CashierStripeInspector implements StripeInspector
{
    public function price(string $priceId): ?array
    {
        try {
            $price = Cashier::stripe()->prices->retrieve($priceId);
        } catch (InvalidRequestException) {
            return null;
        }

        return [
            'id' => (string) $price->id,
            'active' => (bool) $price->active,
            'livemode' => (bool) $price->livemode,
            'currency' => (string) $price->currency,
            'unit_amount' => $price->unit_amount !== null ? (int) $price->unit_amount : null,
            'interval' => $price->recurring?->interval !== null ? (string) $price->recurring->interval : null,
        ];
    }

    public function webhookEndpoints(): array
    {
        $endpoints = [];
        foreach (Cashier::stripe()->webhookEndpoints->all(['limit' => 100])->autoPagingIterator() as $endpoint) {
            $endpoints[] = [
                'id' => (string) $endpoint->id,
                'url' => (string) $endpoint->url,
                'status' => (string) $endpoint->status,
                'livemode' => (bool) $endpoint->livemode,
                'enabled_events' => array_values(array_map('strval', (array) $endpoint->enabled_events)),
                'api_version' => $endpoint->api_version !== null ? (string) $endpoint->api_version : null,
            ];
        }

        return $endpoints;
    }
}
