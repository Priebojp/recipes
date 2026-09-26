<?php

namespace App\Policies;

use App\Models\ShoppingList;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class ShoppingListPolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, ShoppingList $list): bool
    {
        return $this->isMember($user, $list->household_id);
    }

    /** Ticking items off and adding own lines: every member shops. */
    public function update(User $user, ShoppingList $list): bool
    {
        return $this->isMember($user, $list->household_id);
    }
}
