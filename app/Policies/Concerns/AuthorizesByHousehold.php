<?php

namespace App\Policies\Concerns;

use App\Enums\MembershipRole;
use App\Models\HouseholdMembership;
use App\Models\User;

trait AuthorizesByHousehold
{
    protected function role(User $user, int $householdId): ?MembershipRole
    {
        return HouseholdMembership::query()
            ->where('household_id', $householdId)
            ->where('user_id', $user->id)
            ->first()?->role;
    }

    protected function isMember(User $user, int $householdId): bool
    {
        return $this->role($user, $householdId) !== null;
    }

    protected function canEdit(User $user, int $householdId): bool
    {
        return $this->role($user, $householdId)?->canEditContent() ?? false;
    }
}
