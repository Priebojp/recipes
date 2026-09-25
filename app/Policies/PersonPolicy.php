<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Policies\Concerns\AuthorizesByHousehold;

class PersonPolicy
{
    use AuthorizesByHousehold;

    public function view(User $user, Person $person): bool
    {
        return $this->isMember($user, $person->household_id);
    }

    public function update(User $user, Person $person): bool
    {
        return $this->canEdit($user, $person->household_id);
    }

    public function delete(User $user, Person $person): bool
    {
        return $this->canEdit($user, $person->household_id);
    }
}
