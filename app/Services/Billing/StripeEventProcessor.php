<?php

namespace App\Services\Billing;

use App\Enums\OrderStatus;
use App\Enums\StripeEventState;
use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Models\BillingAccount;
use App\Models\Order;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use App\Models\StripeEvent;
use App\Services\Usage\UsageLedger;
use App\Services\Usage\UsageProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Domain side of Stripe webhooks (Cashier keeps its own subscription sync). Every effect is keyed by a business
 * identifier (invoice, order, refund, dispute), so replays and different events about the same payment are no-ops.
 */
class StripeEventProcessor
{
    public function __construct(
        private Catalog $catalog,
        private UsageLedger $ledger,
        private UsageProvisioner $provisioner,
        private RefundService $refunds,
    ) {}

    public function process(StripeEvent $event): void
    {
        $event->increment('attempts');

        try {
            $handled = match ($event->type) {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->checkoutSession($event),
                'checkout.session.async_payment_failed' => $this->checkoutFailed($event, 'Asynchrónna platba zlyhala.'),
                'checkout.session.expired' => $this->checkoutFailed($event, null),
                'invoice.paid', 'invoice.payment_succeeded' => $this->invoicePaid($event),
                'invoice.payment_failed' => $this->invoiceFailed($event),
                'charge.refunded' => $this->chargeRefunded($event),
                'charge.dispute.created' => $this->disputeCreated($event),
                default => false,
            };

            $event->update([
                'state' => $handled ? StripeEventState::Processed : StripeEventState::Ignored,
                'processed_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $event->update(['state' => StripeEventState::Failed, 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }

    private function checkoutSession(StripeEvent $event): bool
    {
        $session = $event->object();
        $order = $this->orderForSession($session);
        if ($order === null) {
            return false;
        }

        DB::transaction(function () use ($order, $session) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $order->fill(array_filter([
                'stripe_checkout_session_id' => $order->stripe_checkout_session_id ?? ($session['id'] ?? null),
                'stripe_payment_intent_id' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
                'stripe_subscription_id' => is_string($session['subscription'] ?? null) ? $session['subscription'] : null,
                'stripe_invoice_id' => is_string($session['invoice'] ?? null) ? $session['invoice'] : null,
            ]))->save();

            if (($session['mode'] ?? '') === 'payment' && ($session['payment_status'] ?? '') === 'paid') {
                $this->fulfilAddon($order);
            }

            // A subscription's paid period arrives with invoice.paid; if it came first, attach it to the order now.
            if ($order->stripe_subscription_id) {
                PaidEntitlement::query()
                    ->where('stripe_subscription_id', $order->stripe_subscription_id)
                    ->whereNull('order_id')
                    ->update(['order_id' => $order->id]);

                if ($order->status === OrderStatus::Pending && $order->entitlements()->exists()) {
                    $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
                }
            }
        });

        return true;
    }

    private function checkoutFailed(StripeEvent $event, ?string $reason): bool
    {
        $order = $this->orderForSession($event->object());
        if ($order === null || $order->status !== OrderStatus::Pending) {
            return false;
        }

        $order->update([
            'status' => $reason === null ? OrderStatus::Expired : OrderStatus::Failed,
            'failure_reason' => $reason,
        ]);

        return true;
    }

    /**
     * A paid subscription invoice is the proof of a paid period. Only invoices with a plan line count; the
     * entitlement is keyed by the invoice, the monthly grant by the household + kind + period start.
     */
    private function invoicePaid(StripeEvent $event): bool
    {
        $invoice = $event->object();
        $paid = ($invoice['paid'] ?? false) || ($invoice['status'] ?? null) === 'paid';
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        if (! $paid || ! is_string($subscriptionId)) {
            return false;
        }

        $reason = $invoice['billing_reason'] ?? 'subscription_cycle';
        if (! in_array($reason, ['subscription_create', 'subscription_cycle', 'subscription_update'], true)) {
            return false;
        }

        $account = BillingAccount::query()->where('stripe_id', $invoice['customer'] ?? '')->first();
        if ($account === null) {
            throw new RuntimeException('Neznámy Stripe zákazník '.($invoice['customer'] ?? '?').' pre faktúru '.($invoice['id'] ?? '?'));
        }

        $line = $this->planLine($invoice);
        if ($line === null) {
            return false;
        }

        [$plan, $start, $end] = $line;
        $household = $account->household;

        DB::transaction(function () use ($invoice, $subscriptionId, $plan, $start, $end, $household) {
            $order = Order::query()
                ->where('household_id', $household->id)
                ->where(fn ($q) => $q->where('stripe_subscription_id', $subscriptionId)
                    ->orWhereKey((int) ($invoice['subscription_details']['metadata']['order_id'] ?? $invoice['parent']['subscription_details']['metadata']['order_id'] ?? 0)))
                ->orderByDesc('id')
                ->first();

            $entitlement = PaidEntitlement::query()->firstOrCreate(['source_key' => 'invoice:'.$invoice['id']], [
                'household_id' => $household->id,
                'order_id' => $order?->id,
                'plan_version_id' => $plan->id,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_invoice_id' => $invoice['id'],
                'starts_at' => $start,
                'ends_at' => $end,
            ]);

            if ($order !== null) {
                $paymentIntent = $invoice['payment_intent'] ?? $invoice['payments']['data'][0]['payment']['payment_intent'] ?? null;
                $order->fill(array_filter([
                    'stripe_subscription_id' => $subscriptionId,
                    'stripe_invoice_id' => $order->stripe_invoice_id ?? $invoice['id'],
                    'stripe_payment_intent_id' => $order->stripe_payment_intent_id ?? (is_string($paymentIntent) ? $paymentIntent : null),
                ]));
                if ($order->status === OrderStatus::Pending) {
                    $order->status = OrderStatus::Paid;
                    $order->paid_at = now();
                }
                $order->save();

                if ($entitlement->order_id === null) {
                    $entitlement->update(['order_id' => $order->id]);
                }
            }
        });

        $this->provisioner->openCurrentGrants($household);

        return true;
    }

    private function invoiceFailed(StripeEvent $event): bool
    {
        $invoice = $event->object();
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        if (! is_string($subscriptionId)) {
            return false;
        }

        // First payment of a new subscription failed: the order stays visible as failed. Renewals: Cashier marks the
        // subscription past due; no new grants are created because no invoice was paid.
        $updated = Order::query()
            ->where('stripe_subscription_id', $subscriptionId)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Failed->value, 'failure_reason' => 'Platba faktúry zlyhala.', 'updated_at' => now()]);

        return $updated > 0;
    }

    private function chargeRefunded(StripeEvent $event): bool
    {
        $charge = $event->object();
        $paymentIntent = $charge['payment_intent'] ?? null;
        $order = is_string($paymentIntent) ? Order::query()->where('stripe_payment_intent_id', $paymentIntent)->first() : null;
        if ($order === null) {
            return false;
        }

        $refunds = $charge['refunds']['data'] ?? null;
        if (is_array($refunds)) {
            foreach ($refunds as $refund) {
                $this->refunds->reconcileStripeRefund($order, (string) $refund['id'], (int) $refund['amount'], (string) ($refund['currency'] ?? $order->currency));
            }
        } else {
            $this->refunds->reconcileRefundedTotal($order, (int) ($charge['amount_refunded'] ?? 0));
        }

        $this->refunds->syncOrderStatus($order->fresh());

        return true;
    }

    private function disputeCreated(StripeEvent $event): bool
    {
        $dispute = $event->object();
        $paymentIntent = $dispute['payment_intent'] ?? null;
        $order = is_string($paymentIntent) ? Order::query()->where('stripe_payment_intent_id', $paymentIntent)->first() : null;
        if ($order === null) {
            return false;
        }

        $this->refunds->openDispute($order, (string) $dispute['id'], (int) ($dispute['amount'] ?? $order->amount_cents), (string) ($dispute['reason'] ?? 'dispute'));

        return true;
    }

    private function fulfilAddon(Order $order): void
    {
        $snapshot = $order->product_snapshot;
        if (($snapshot['type'] ?? '') !== 'addon') {
            return;
        }

        if ($order->status === OrderStatus::Pending) {
            $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
        }

        $this->ledger->grant(
            $order->household,
            UsageKind::from($snapshot['unit_kind']),
            UsageGrantSource::Addon,
            (int) $snapshot['unit_count'],
            'order:'.$order->id,
            note: (string) $snapshot['name'],
            meta: ['addon_version_id' => $snapshot['addon_version_id'] ?? null],
            links: ['order_id' => $order->id],
        );
    }

    /** @param  array<string, mixed>  $session */
    private function orderForSession(array $session): ?Order
    {
        $order = isset($session['id']) ? Order::query()->where('stripe_checkout_session_id', $session['id'])->first() : null;
        if ($order === null && isset($session['metadata']['order_id'])) {
            $order = Order::query()->find((int) $session['metadata']['order_id']);
        }

        return $order;
    }

    /**
     * The first invoice line that sells one of our plans, with its paid period.
     *
     * @param  array<string, mixed>  $invoice
     * @return array{0: PlanVersion, 1: CarbonImmutable, 2: CarbonImmutable}|null
     */
    private function planLine(array $invoice): ?array
    {
        foreach ($invoice['lines']['data'] ?? [] as $line) {
            $priceId = $line['price']['id'] ?? $line['pricing']['price_details']['price'] ?? null;
            $plan = $this->catalog->planForStripePrice(is_string($priceId) ? $priceId : null);
            if ($plan === null) {
                continue;
            }
            $period = $line['period'] ?? null;
            if (! isset($period['start'], $period['end'])) {
                continue;
            }

            return [$plan, CarbonImmutable::createFromTimestampUTC((int) $period['start']), CarbonImmutable::createFromTimestampUTC((int) $period['end'])];
        }

        return null;
    }
}
