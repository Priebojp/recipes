<?php

namespace App\Policies;

use App\Models\MealConsumption;
use App\Models\User;

/**
 * The diary is personal: only the person who wrote an entry reads, corrects or deletes it. Membership in the
 * household – even owning it – grants nothing, and the administrator never sees entries at all.
 */
class MealConsumptionPolicy
{
    public function view(User $user, MealConsumption $entry): bool
    {
        return (int) $entry->user_id === (int) $user->id;
    }

    public function update(User $user, MealConsumption $entry): bool
    {
        return $this->view($user, $entry);
    }

    public function delete(User $user, MealConsumption $entry): bool
    {
        return $this->view($user, $entry);
    }
}
