<?php

namespace App\Support;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-request holder of the household the authenticated user is working in.
 */
class CurrentHousehold
{
    private ?Household $household = null;

    public function set(Household $household): void
    {
        $this->household = $household;
    }

    public function get(): Household
    {
        if ($this->household === null) {
            throw new \RuntimeException('No household has been resolved for the current request.');
        }

        return $this->household;
    }

    public function has(): bool
    {
        return $this->household !== null;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function timezone(): string
    {
        return $this->has() ? $this->get()->timezone : config('recipes.default_timezone');
    }

    /**
     * Resolve (or lazily create) the household for a user.
     */
    public function resolveFor(User $user): Household
    {
        $household = null;

        if ($preferred = session('current_household_id')) {
            $household = $user->households()->whereKey($preferred)->first();
        }

        $household ??= $user->currentHousehold() ?? self::createFor($user);

        $this->set($household);

        return $household;
    }

    /**
     * Create a personal household for a user, including a diner profile linked to the account.
     */
    public static function createFor(User $user, ?string $name = null): Household
    {
        return DB::transaction(function () use ($user, $name) {
            $household = Household::create([
                'name' => $name ?? ($user->name.' – domácnosť'),
                'timezone' => config('recipes.default_timezone'),
                'owner_user_id' => $user->id,
            ]);

            HouseholdMembership::create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'role' => MembershipRole::Owner,
            ]);

            $person = Person::create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'name' => $user->name,
            ]);

            $household->update(['default_person_ids' => [$person->id]]);

            return $household;
        });
    }

    /**
     * The diner profile whose taste the heart button edits: chosen in session, else the account's own profile,
     * else the first active person.
     */
    public function activePerson(User $user): ?Person
    {
        $householdId = $this->id();
        $query = Person::query()->where('household_id', $householdId)->whereNull('archived_at');

        $chosen = session('active_person_id.'.$householdId);
        if ($chosen && ($person = (clone $query)->whereKey($chosen)->first())) {
            return $person;
        }

        return (clone $query)->where('user_id', $user->id)->first() ?? $query->orderBy('id')->first();
    }

    public function setActivePerson(Person $person): void
    {
        session(['active_person_id.'.$this->id() => $person->id]);
    }

    public function roleOf(User $user): ?MembershipRole
    {
        $membership = HouseholdMembership::query()
            ->where('household_id', $this->id())
            ->where('user_id', $user->id)
            ->first();

        return $membership?->role;
    }
}
