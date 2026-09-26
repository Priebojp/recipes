<?php

namespace App\Services\Admin;

use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Models\Household;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use App\Models\UsageGrant;
use App\Models\User;
use App\Services\Usage\UsageLedger;
use App\Services\Usage\UsageProvisioner;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What an administrator may give a household without a Stripe payment (specification chapter 8): compensation uses
 * as a separate ledger grant, or a time-limited Plus period. Neither fakes a payment nor edits a balance in place.
 */
class Compensations
{
    public function __construct(private UsageLedger $ledger, private UsageProvisioner $provisioner, private AdminAuditor $audit) {}

    /**
     * A separate, audited grant; idempotent by key. Returns the existing grant when the key was used before.
     */
    public function grantUses(Household $household, UsageKind $kind, int $quantity, string $reason, ?CarbonInterface $expiresAt = null, ?string $key = null, ?User $by = null): UsageGrant
    {
        $reason = trim($reason);
        if ($quantity < 1 || $reason === '') {
            throw new InvalidArgumentException('Kompenzácia potrebuje počet ≥ 1 a dôvod.');
        }

        $sourceKey = 'compensation:'.($key ? Str::slug($key) : Str::uuid());
        $grant = $this->ledger->grant($household, $kind, UsageGrantSource::Compensation, $quantity, $sourceKey, expiresAt: $expiresAt, actor: $by, note: $reason);

        if ($grant->wasRecentlyCreated) {
            $this->audit->record('usage.compensation.granted', $grant, [], [
                'household_id' => $household->id,
                'kind' => $kind->value,
                'quantity' => $quantity,
                'expires_at' => $expiresAt?->toIso8601String(),
            ], $reason, $by);
        }

        return $grant;
    }

    /**
     * A Plus period without an order: Plus features and the plan's included uses for [from, to). It never marks
     * anything as paid – the entitlement has no order and no invoice.
     */
    public function grantPlus(Household $household, PlanVersion $plan, CarbonInterface $from, CarbonInterface $to, string $reason, ?User $by = null): PaidEntitlement
    {
        $reason = trim($reason);
        if ($to <= $from || $reason === '') {
            throw new InvalidArgumentException('Plus grant potrebuje obdobie s koncom po začiatku a dôvod.');
        }

        $entitlement = PaidEntitlement::create([
            'household_id' => $household->id,
            'order_id' => null,
            'plan_version_id' => $plan->id,
            'source_key' => 'compensation:plus:'.Str::uuid(),
            'starts_at' => $from,
            'ends_at' => $to,
        ]);

        $this->audit->record('billing.plus.granted', $entitlement, [], [
            'household_id' => $household->id,
            'plan_version_id' => $plan->id,
            'starts_at' => $from->toIso8601String(),
            'ends_at' => $to->toIso8601String(),
        ], $reason, $by);

        $this->provisioner->openCurrentGrants($household);

        return $entitlement;
    }

    /** End a compensation Plus period early (never a paid one – that is a refund). */
    public function revokePlus(PaidEntitlement $entitlement, string $reason, ?User $by = null): PaidEntitlement
    {
        if ($entitlement->order_id !== null || ! str_starts_with($entitlement->source_key, 'compensation:')) {
            throw new InvalidArgumentException('Zaplatené obdobie sa odoberá refundáciou, nie tu.');
        }
        if ($entitlement->revoked_at !== null) {
            return $entitlement;
        }

        $entitlement->update(['revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 500)]);
        $this->audit->record('billing.plus.revoked', $entitlement, [], ['household_id' => $entitlement->household_id], $reason, $by);

        return $entitlement;
    }
}
