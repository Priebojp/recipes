<?php

namespace App\Services\Usage;

use App\Enums\PlanInterval;
use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Models\Household;
use App\Models\PaidEntitlement;
use App\Models\UsageGrant;
use App\Services\Billing\MonthlyGrantSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Opens the grants a household is entitled to right now: the one-time trial and the current monthly grant of each
 * paid period. Idempotent (source key = household + kind + interval start) and never creates past intervals,
 * so a scheduler outage cannot pile up usable old grants.
 */
class UsageProvisioner
{
    public function __construct(private TrialGrants $trials, private UsageLedger $ledger) {}

    /** Called on every AI request and by the daily reconciliation. */
    public function ensureFor(Household $household): void
    {
        $this->trials->ensureFor($household);
        $this->openCurrentGrants($household);
    }

    /**
     * @return list<UsageGrant> newly created grants
     */
    public function openCurrentGrants(Household $household, ?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $created = [];

        $entitlements = PaidEntitlement::query()
            ->where('household_id', $household->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->with('planVersion')
            ->get();

        foreach ($entitlements as $entitlement) {
            $interval = $this->currentInterval($entitlement, $at);
            if ($interval === null) {
                continue;
            }

            foreach ($entitlement->planVersion->usesPerPeriod() as $kind => $quantity) {
                if ($quantity < 1) {
                    continue;
                }
                $kind = UsageKind::from($kind);
                $key = self::sourceKey($household, $kind, $interval['start']);

                if (UsageGrant::query()->where('source_key', $key)->exists()) {
                    continue;
                }

                $created[] = $this->ledger->grant(
                    $household,
                    $kind,
                    UsageGrantSource::Subscription,
                    $quantity,
                    $key,
                    validFrom: $interval['start'],
                    expiresAt: $interval['end'],
                    note: $entitlement->planVersion->name,
                    meta: ['interval_index' => $interval['index']],
                    links: ['order_id' => $entitlement->order_id, 'paid_entitlement_id' => $entitlement->id],
                );
            }
        }

        return $created;
    }

    /**
     * Monthly plan: the paid Stripe period itself. Yearly plan: the calendar-month interval containing "now",
     * cut to the paid year.
     *
     * @return array{index: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function currentInterval(PaidEntitlement $entitlement, CarbonInterface $at): ?array
    {
        $starts = CarbonImmutable::instance($entitlement->starts_at);
        $ends = CarbonImmutable::instance($entitlement->ends_at);

        if ($entitlement->planVersion->interval === PlanInterval::Month) {
            return ['index' => 0, 'start' => $starts, 'end' => $ends];
        }

        $interval = MonthlyGrantSchedule::intervalAt($starts, (string) config('recipes.billing.timezone'), $at);
        if ($interval === null) {
            return null;
        }

        return ['index' => $interval['index'], 'start' => $interval['start'], 'end' => $interval['end']->min($ends)];
    }

    public static function sourceKey(Household $household, UsageKind $kind, CarbonInterface $intervalStart): string
    {
        return "sub:{$household->id}:{$kind->value}:".CarbonImmutable::instance($intervalStart)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
