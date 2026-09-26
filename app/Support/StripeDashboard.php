<?php

namespace App\Support;

/**
 * Deep links into the Stripe dashboard for administrators (test or live mode follows the configured secret key).
 */
class StripeDashboard
{
    public static function isLive(): bool
    {
        return str_starts_with((string) config('cashier.secret'), 'sk_live_');
    }

    public static function customer(?string $id): ?string
    {
        return self::link('customers', $id);
    }

    public static function subscription(?string $id): ?string
    {
        return self::link('subscriptions', $id);
    }

    public static function paymentIntent(?string $id): ?string
    {
        return self::link('payments', $id);
    }

    public static function invoice(?string $id): ?string
    {
        return self::link('invoices', $id);
    }

    public static function refund(?string $id): ?string
    {
        return self::link('refunds', $id);
    }

    public static function dispute(?string $id): ?string
    {
        return self::link('disputes', $id);
    }

    public static function event(?string $id): ?string
    {
        return self::link('events', $id);
    }

    private static function link(string $section, ?string $id): ?string
    {
        if (! $id) {
            return null;
        }

        return 'https://dashboard.stripe.com/'.(self::isLive() ? '' : 'test/').$section.'/'.rawurlencode($id);
    }
}
