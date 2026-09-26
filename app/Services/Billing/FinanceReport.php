<?php

namespace App\Services\Billing;

use App\Enums\AiJobStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\StripeEventState;
use App\Enums\UsageReservationState;
use App\Models\AiJob;
use App\Models\Order;
use App\Models\PaidEntitlement;
use App\Models\RefundCase;
use App\Models\StripeEvent;
use App\Models\UsageReservation;
use App\Services\Ai\AiUsageReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Laravel\Cashier\Subscription;

/**
 * Financial figures for the administrator (specification chapter 8). Revenue, cash, refunds and AI cost are kept
 * apart; the contribution after variable costs is an estimate and is never presented as profit. Money in EUR cents,
 * AI cost in micro-USD; the two meet only through an explicitly configured rate.
 */
class FinanceReport
{
    public function __construct(private AiUsageReport $ai) {}

    public function timezone(): string
    {
        return (string) config('recipes.billing.timezone', config('recipes.default_timezone'));
    }

    /**
     * Recurring picture at one moment: who pays, MRR normalised per month (yearly ÷ 12), subscription states.
     *
     * @return array{paying_households: int, mrr_cents: int, monthly: int, yearly: int, compensation: int, subscriptions: array<string, int>, canceling: int}
     */
    public function recurring(?CarbonInterface $at = null): array
    {
        $at ??= now();

        /** @var Collection<int, PaidEntitlement> $current */
        $current = PaidEntitlement::query()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->with('planVersion')
            ->orderByDesc('ends_at')
            ->get()
            ->unique('household_id')
            ->values();

        $paid = $current->filter(fn (PaidEntitlement $e) => $e->order_id !== null);

        $subscriptions = Subscription::query()
            ->selectRaw('stripe_status, count(*) as n')
            ->groupBy('stripe_status')
            ->pluck('n', 'stripe_status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'paying_households' => $paid->count(),
            'mrr_cents' => (int) $paid->sum(fn (PaidEntitlement $e) => $e->planVersion->monthlyEquivalentCents()),
            'monthly' => $paid->filter(fn (PaidEntitlement $e) => $e->planVersion->interval->value === 'month')->count(),
            'yearly' => $paid->filter(fn (PaidEntitlement $e) => $e->planVersion->interval->value === 'year')->count(),
            'compensation' => $current->count() - $paid->count(),
            'subscriptions' => $subscriptions,
            'canceling' => Subscription::query()->whereNotNull('ends_at')->where('ends_at', '>', $at)->count(),
        ];
    }

    /**
     * Money in a period: cash collected (orders paid), refunds, time-apportioned revenue and the AI cost estimate.
     *
     * @return array{cash_cents: int, orders_paid: int, addons_cents: int, subscriptions_cents: int, refunds_cents: int, refunds_count: int, revenue_cents: int, ai_cost_micro: int, ai_jobs: int, usd_eur_rate: float|null, ai_cost_cents: int|null, contribution_cents: int|null}
     */
    public function period(CarbonInterface $from, CarbonInterface $to): array
    {
        $paidOrders = Order::query()
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Refunded, OrderStatus::PartiallyRefunded])
            ->whereBetween('paid_at', [$from, $to])
            ->get(['id', 'kind', 'amount_cents']);

        $refunds = RefundCase::query()
            ->whereIn('status', array_filter(RefundStatus::cases(), fn (RefundStatus $s) => $s->countsAsRefunded()))
            ->whereBetween('processed_at', [$from, $to])
            ->get(['id', 'amount_cents']);

        $ai = $this->ai->summary($from, $to);
        $rate = config('recipes.billing.usd_eur_rate');
        $rate = is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;

        $cash = (int) $paidOrders->sum('amount_cents');
        $refunded = (int) $refunds->sum('amount_cents');
        $aiCostCents = $rate !== null ? (int) round($ai['cost_micro'] * $rate / 10_000) : null;

        return [
            'cash_cents' => $cash,
            'orders_paid' => $paidOrders->count(),
            'addons_cents' => (int) $paidOrders->where('kind', OrderKind::Addon)->sum('amount_cents'),
            'subscriptions_cents' => (int) $paidOrders->where('kind', OrderKind::Subscription)->sum('amount_cents'),
            'refunds_cents' => $refunded,
            'refunds_count' => $refunds->count(),
            'revenue_cents' => $this->recognisedRevenue($from, $to),
            'ai_cost_micro' => (int) $ai['cost_micro'],
            'ai_jobs' => (int) $ai['jobs'],
            'usd_eur_rate' => $rate,
            'ai_cost_cents' => $aiCostCents,
            'contribution_cents' => $aiCostCents !== null ? $cash - $refunded - $aiCostCents : null,
        ];
    }

    /**
     * Things that need a person: failed webhooks, refunds to review, open disputes, AI jobs awaiting a decision,
     * reservations held for too long.
     *
     * @return array{webhooks_failed: int, webhooks_received: int, refunds_to_review: int, disputes: int, ai_reconciling: int, stale_reservations: int, pending_orders: int}
     */
    public function attention(): array
    {
        return [
            'webhooks_failed' => StripeEvent::query()->where('state', StripeEventState::Failed)->count(),
            'webhooks_received' => StripeEvent::query()->where('state', StripeEventState::Received)->where('created_at', '<', now()->subHour())->count(),
            'refunds_to_review' => RefundCase::query()->where('status', RefundStatus::NeedsReview)->count(),
            'disputes' => RefundCase::query()->where('status', RefundStatus::Disputed)->count(),
            'ai_reconciling' => AiJob::query()->where('status', AiJobStatus::Reconciling)->count(),
            'stale_reservations' => UsageReservation::query()->where('state', UsageReservationState::Reserved)->where('reserved_at', '<', now()->subDay())->count(),
            'pending_orders' => Order::query()->where('status', OrderStatus::Pending)->where('created_at', '<', now()->subHour())->count(),
        ];
    }

    /**
     * Subscription revenue apportioned to the days of the period (a yearly payment is not one month's revenue),
     * plus add-on packs at the day they were paid. Revoked periods count only up to the revocation.
     */
    public function recognisedRevenue(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        $addons = (int) Order::query()
            ->where('kind', OrderKind::Addon)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Refunded, OrderStatus::PartiallyRefunded])
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_cents');

        $entitlements = PaidEntitlement::query()
            ->whereNotNull('order_id')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with('order:id,amount_cents')
            ->get();

        $subscriptions = 0.0;
        foreach ($entitlements as $entitlement) {
            $amount = (int) ($entitlement->order?->amount_cents ?? 0);
            $start = CarbonImmutable::instance($entitlement->starts_at);
            $end = CarbonImmutable::instance($entitlement->ends_at);
            $effectiveEnd = $entitlement->revoked_at !== null ? $end->min(CarbonImmutable::instance($entitlement->revoked_at)) : $end;
            $total = max(1, $end->getTimestamp() - $start->getTimestamp());
            $overlap = max(0, min($effectiveEnd->getTimestamp(), $to->getTimestamp()) - max($start->getTimestamp(), $from->getTimestamp()));
            $subscriptions += $amount * $overlap / $total;
        }

        return $addons + (int) round($subscriptions);
    }
}
