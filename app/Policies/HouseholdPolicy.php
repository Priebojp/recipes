<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\User;

class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $this->roleOf($user, $household) !== null;
    }

    public function edit(User $user, Household $household): bool
    {
        return $this->roleOf($user, $household)?->canEditContent() ?? false;
    }

    public function manage(User $user, Household $household): bool
    {
        return $this->roleOf($user, $household) === MembershipRole::Owner;
    }

    public static function roleOf(User $user, Household $household): ?MembershipRole
    {
        return HouseholdMembership::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->first()?->role;
    }
}
