<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class RecipePolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, Recipe $recipe): bool
    {
        return $this->isMember($user, $recipe->household_id);
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->canEdit($user, $recipe->household_id);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->canEdit($user, $recipe->household_id);
    }
}
