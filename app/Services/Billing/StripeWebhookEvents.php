<?php

namespace App\Services\Billing;

use Laravel\Cashier\Console\WebhookCommand;

/**
 * The one list of Stripe events the application needs delivered: Cashier's own synchronisation events plus the
 * events the inbox processor handles. `php artisan cashier:webhook` registers exactly this list and the launch check
 * compares the endpoint in Stripe against it.
 */
class StripeWebhookEvents
{
    /** Events handled by StripeEventProcessor (orders, entitlements, refunds, disputes). */
    public const HANDLED = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'invoice.paid',
        'invoice.payment_succeeded',
        'invoice.payment_failed',
        'charge.refunded',
        'charge.dispute.created',
    ];

    /** @return list<string> */
    public static function required(): array
    {
        return array_values(array_unique([...WebhookCommand::DEFAULT_EVENTS, ...self::HANDLED]));
    }

    /**
     * Required events missing from an endpoint's enabled list (an endpoint subscribed to "*" misses nothing).
     *
     * @param  list<string>  $enabled
     * @return list<string>
     */
    public static function missingFrom(array $enabled): array
    {
        if (in_array('*', $enabled, true)) {
            return [];
        }

        return array_values(array_diff(self::required(), $enabled));
    }
}
