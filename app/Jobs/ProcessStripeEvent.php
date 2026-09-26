<?php

namespace App\Jobs;

use App\Enums\StripeEventState;
use App\Models\StripeEvent;
use App\Services\Billing\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Domain processing of a stored Stripe event, off the webhook request. Failures stay in the inbox and are retried
 * by the daily reconciliation, so a lost job never loses an event.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $stripeEventId) {}

    public function handle(StripeEventProcessor $processor): void
    {
        $event = StripeEvent::find($this->stripeEventId);
        if ($event !== null && in_array($event->state, [StripeEventState::Received, StripeEventState::Failed], true)) {
            $processor->process($event);
        }
    }
}
