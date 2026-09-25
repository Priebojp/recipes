<?php

namespace App\Policies;

use App\Models\AiJob;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class AiJobPolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, AiJob $aiJob): bool
    {
        return $this->isMember($user, $aiJob->household_id);
    }

    public function update(User $user, AiJob $aiJob): bool
    {
        return $this->canEdit($user, $aiJob->household_id);
    }

    public function delete(User $user, AiJob $aiJob): bool
    {
        return $this->canEdit($user, $aiJob->household_id);
    }
}
