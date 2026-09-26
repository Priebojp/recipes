<?php

namespace App\Services\Billing;

use App\Models\Household;
use App\Models\PaidEntitlement;
use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription;

/**
 * What the household is entitled to right now, derived from paid periods (never from a success URL).
 */
class PlanStatus
{
    /** The paid period covering the moment, if any. */
    public function current(Household $household, ?CarbonInterface $at = null): ?PaidEntitlement
    {
        $at ??= now();

        return PaidEntitlement::query()
            ->where('household_id', $household->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderByDesc('ends_at')
            ->first();
    }

    /** Last paid period that started (active or ended), for "paid through" and the renewal-failure tolerance. */
    public function latest(Household $household): ?PaidEntitlement
    {
        return PaidEntitlement::query()
            ->where('household_id', $household->id)
            ->whereNull('revoked_at')
            ->orderByDesc('ends_at')
            ->first();
    }

    /**
     * Plus features: paid period, or the short tolerance after a failed renewal while Stripe retries.
     * The tolerance never creates AI grants.
     */
    public function isPlus(Household $household, ?CarbonInterface $at = null): bool
    {
        $at ??= now();
        if ($this->current($household, $at) !== null) {
            return true;
        }

        return $this->inRenewalGrace($household, $at);
    }

    public function inRenewalGrace(Household $household, ?CarbonInterface $at = null): bool
    {
        $at ??= now();
        $latest = $this->latest($household);
        if ($latest === null || $latest->ends_at > $at) {
            return false;
        }

        $graceDays = (int) config('recipes.billing.renewal_grace_days', 3);
        if ($latest->ends_at->addDays($graceDays) <= $at) {
            return false;
        }

        $subscription = $this->subscription($household);

        return $subscription !== null && ($subscription->pastDue() || $subscription->stripe_status === 'unpaid');
    }

    public function paidThrough(Household $household): ?CarbonInterface
    {
        return $this->latest($household)?->ends_at;
    }

    /** The Cashier subscription record synced from Stripe (null before the first checkout). */
    public function subscription(Household $household): ?Subscription
    {
        return $household->billingAccount?->subscription('default');
    }

    /** A subscription that is still alive in Stripe (active, cancelled-but-running, past due or incomplete). */
    public function hasSubscription(Household $household): bool
    {
        $subscription = $this->subscription($household);

        return $subscription !== null && ! $subscription->ended() && $subscription->stripe_status !== 'incomplete_expired';
    }
}
