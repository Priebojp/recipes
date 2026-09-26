<?php

namespace App\Http\Controllers;

use App\Enums\StripeEventState;
use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signature is verified by Cashier's middleware (when STRIPE_WEBHOOK_SECRET is set). The event is stored in the
 * inbox first, Cashier's own sync runs, and domain processing is queued – Stripe is acknowledged only after the
 * event is durable. A replayed event ID is acknowledged without doing anything again.
 */
class StripeWebhookController extends CashierWebhookController
{
    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ! isset($payload['id'], $payload['type'])) {
            return new Response('Invalid payload', 400);
        }

        $object = Arr::get($payload, 'data.object', []);
        $object = is_array($object) ? $object : [];

        try {
            $event = StripeEvent::create([
                'event_id' => $payload['id'],
                'type' => $payload['type'],
                'object_id' => isset($object['id']) && is_string($object['id']) ? $object['id'] : null,
                'livemode' => (bool) ($payload['livemode'] ?? false),
                'state' => StripeEventState::Received,
                'payload' => $object,
                'event_created_at' => isset($payload['created']) ? now()->setTimestamp((int) $payload['created']) : null,
            ]);
        } catch (QueryException $e) {
            $existing = StripeEvent::query()->where('event_id', $payload['id'])->first();
            if ($existing === null) {
                throw $e;
            }

            // Duplicate delivery: retry only what failed before, never re-run what was processed.
            if ($existing->state === StripeEventState::Failed) {
                ProcessStripeEvent::dispatch($existing->id);
            }

            return new Response('Duplicate', 200);
        }

        // Cashier keeps subscriptions, customers and payment methods in sync.
        parent::handleWebhook($request);

        ProcessStripeEvent::dispatch($event->id);

        return new Response('Webhook received', 200);
    }
}
