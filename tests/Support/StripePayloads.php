<?php

namespace Tests\Support;

use App\Models\BillingAccount;
use App\Models\Order;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Minimal Stripe webhook payloads shaped like the real objects the processor reads, signed like Stripe signs them.
 */
class StripePayloads
{
    private static int $sequence = 0;

    /** Signed POST to the webhook endpoint with the raw JSON body. */
    /** @param  TestCase|object  $test  the current test case (or Pest's proxy to it) */
    public static function post(object $test, array $event, ?string $secret = 'whsec_test'): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $secret);

        return $test->call('POST', route('cashier.webhook'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public static function event(string $type, array $object, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'evt_'.(++self::$sequence),
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => time(),
            'data' => ['object' => $object],
        ];
    }

    public static function subscriptionCreated(BillingAccount $account, string $subscriptionId, string $priceId, string $status = 'active'): array
    {
        return self::event('customer.subscription.created', [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'customer' => $account->stripe_id,
            'status' => $status,
            'metadata' => ['type' => 'default'],
            'items' => ['data' => [[
                'id' => 'si_'.$subscriptionId,
                'price' => ['id' => $priceId, 'product' => 'prod_plus'],
                'quantity' => 1,
            ]]],
        ]);
    }

    public static function checkoutCompleted(Order $order, string $mode, string $paymentStatus = 'paid', array $extra = []): array
    {
        return self::event('checkout.session.completed', array_merge([
            'id' => $order->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'mode' => $mode,
            'payment_status' => $paymentStatus,
            'customer' => $order->billingAccount->stripe_id,
            'metadata' => ['order_id' => (string) $order->id, 'household_id' => (string) $order->household_id],
            'payment_intent' => $mode === 'payment' ? 'pi_'.$order->id : null,
            'subscription' => $mode === 'subscription' ? 'sub_'.$order->id : null,
            'invoice' => $mode === 'subscription' ? 'in_'.$order->id.'_1' : null,
        ], $extra));
    }

    public static function asyncPaymentSucceeded(Order $order, string $mode): array
    {
        $event = self::checkoutCompleted($order, $mode, 'paid');
        $event['type'] = 'checkout.session.async_payment_succeeded';

        return $event;
    }

    public static function asyncPaymentFailed(Order $order, string $mode): array
    {
        $event = self::checkoutCompleted($order, $mode, 'unpaid');
        $event['type'] = 'checkout.session.async_payment_failed';

        return $event;
    }

    public static function invoicePaid(
        BillingAccount $account,
        string $invoiceId,
        string $subscriptionId,
        string $priceId,
        int $periodStart,
        int $periodEnd,
        string $billingReason = 'subscription_cycle',
        string $type = 'invoice.paid',
        ?int $orderId = null,
        bool $paid = true,
    ): array {
        return self::event($type, [
            'id' => $invoiceId,
            'object' => 'invoice',
            'customer' => $account->stripe_id,
            'status' => $paid ? 'paid' : 'open',
            'paid' => $paid,
            'billing_reason' => $billingReason,
            'subscription' => $subscriptionId,
            'payment_intent' => 'pi_'.$invoiceId,
            'parent' => ['subscription_details' => ['subscription' => $subscriptionId, 'metadata' => array_filter(['order_id' => $orderId !== null ? (string) $orderId : null])]],
            'lines' => ['data' => [[
                'id' => 'il_'.$invoiceId,
                'price' => ['id' => $priceId],
                'pricing' => ['price_details' => ['price' => $priceId]],
                'period' => ['start' => $periodStart, 'end' => $periodEnd],
            ]]],
        ]);
    }

    public static function invoicePaymentFailed(BillingAccount $account, string $invoiceId, string $subscriptionId): array
    {
        return self::event('invoice.payment_failed', [
            'id' => $invoiceId,
            'object' => 'invoice',
            'customer' => $account->stripe_id,
            'status' => 'open',
            'paid' => false,
            'subscription' => $subscriptionId,
            'parent' => ['subscription_details' => ['subscription' => $subscriptionId]],
        ]);
    }

    public static function chargeRefunded(string $paymentIntent, int $amount, int $amountRefunded, array $refunds): array
    {
        return self::event('charge.refunded', [
            'id' => 'ch_'.$paymentIntent,
            'object' => 'charge',
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'amount_refunded' => $amountRefunded,
            'refunded' => $amountRefunded >= $amount,
            'refunds' => ['data' => array_map(fn ($r) => ['id' => $r['id'], 'amount' => $r['amount'], 'currency' => 'eur', 'status' => 'succeeded'], $refunds)],
        ]);
    }

    public static function disputeCreated(string $paymentIntent, int $amount, string $disputeId = 'dp_1'): array
    {
        return self::event('charge.dispute.created', [
            'id' => $disputeId,
            'object' => 'dispute',
            'payment_intent' => $paymentIntent,
            'charge' => 'ch_'.$paymentIntent,
            'amount' => $amount,
            'reason' => 'fraudulent',
        ]);
    }
}
