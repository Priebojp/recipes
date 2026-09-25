<?php

namespace App\Policies;

use App\Models\MealPlan;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class MealPlanPolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, MealPlan $mealPlan): bool
    {
        return $this->isMember($user, $mealPlan->household_id);
    }

    public function update(User $user, MealPlan $mealPlan): bool
    {
        return $this->canEdit($user, $mealPlan->household_id);
    }

    public function delete(User $user, MealPlan $mealPlan): bool
    {
        return $this->canEdit($user, $mealPlan->household_id);
    }
}
