<?php

namespace App\Services\Admin;

use App\Models\Household;
use App\Models\User;

/**
 * Blocking a household for abuse: stops new AI jobs and purchases, keeps every recipe and paid entitlement.
 * Both directions are audited with a reason.
 */
class HouseholdModeration
{
    public function __construct(private AdminAuditor $audit) {}

    public function block(Household $household, string $reason, ?User $by = null): Household
    {
        if ($household->isBlocked()) {
            return $household;
        }

        $household->forceFill(['blocked_at' => now(), 'blocked_reason' => mb_substr($reason, 0, 500)])->save();
        $this->audit->record('household.blocked', $household, ['blocked_at' => null], ['blocked_at' => $household->blocked_at->toIso8601String()], $reason, $by);

        return $household;
    }

    public function unblock(Household $household, string $reason, ?User $by = null): Household
    {
        if (! $household->isBlocked()) {
            return $household;
        }

        $before = ['blocked_at' => $household->blocked_at->toIso8601String(), 'blocked_reason' => $household->blocked_reason];
        $household->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();
        $this->audit->record('household.unblocked', $household, $before, ['blocked_at' => null], $reason, $by);

        return $household;
    }
}
