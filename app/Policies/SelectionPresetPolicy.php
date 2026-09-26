<?php

namespace App\Policies;

use App\Models\SelectionPreset;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class SelectionPresetPolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, SelectionPreset $preset): bool
    {
        return $this->isMember($user, $preset->household_id);
    }

    public function update(User $user, SelectionPreset $preset): bool
    {
        return $this->canEdit($user, $preset->household_id);
    }

    public function delete(User $user, SelectionPreset $preset): bool
    {
        return $this->canEdit($user, $preset->household_id);
    }
}
