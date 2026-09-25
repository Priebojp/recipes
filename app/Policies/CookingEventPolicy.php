<?php

namespace App\Policies;

use App\Models\CookingEvent;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class CookingEventPolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, CookingEvent $cookingEvent): bool
    {
        return $this->isMember($user, $cookingEvent->household_id);
    }

    public function update(User $user, CookingEvent $cookingEvent): bool
    {
        return $this->canEdit($user, $cookingEvent->household_id);
    }

    public function delete(User $user, CookingEvent $cookingEvent): bool
    {
        return $this->canEdit($user, $cookingEvent->household_id);
    }
}
