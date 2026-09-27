<?php

namespace App\Policies;

use App\Models\MealAnalysis;
use App\Models\User;

/**
 * A photo analysis is personal: only the person who uploaded the photo reads or changes it. Household membership
 * grants nothing here, and the platform administrator sees only the job's metadata in /admin/ai.
 */
class MealAnalysisPolicy
{
    public function view(User $user, MealAnalysis $analysis): bool
    {
        return (int) $analysis->user_id === (int) $user->id;
    }

    public function update(User $user, MealAnalysis $analysis): bool
    {
        return $this->view($user, $analysis);
    }

    public function delete(User $user, MealAnalysis $analysis): bool
    {
        return $this->view($user, $analysis);
    }
}
