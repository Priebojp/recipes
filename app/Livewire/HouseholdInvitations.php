<?php

namespace App\Livewire;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMembership;
use App\Models\Person;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Owner tools: members, roles and invitation links.
 *
 * @property-read Household $household
 * @property-read bool $canManage
 * @property-read Collection<int, HouseholdMembership> $members
 * @property-read Collection<int, HouseholdInvitation> $invitations
 * @property-read Collection<int, Person> $unlinkedPeople
 */
class HouseholdInvitations extends Component
{
    public string $role = 'member';

    public ?int $personId = null;

    public ?string $createdLink = null;

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('manage', $this->household);
    }

    /** @return Collection<int, HouseholdMembership> */
    #[Computed]
    public function members(): Collection
    {
        return HouseholdMembership::query()->where('household_id', $this->household->id)->with('user')->get();
    }

    /** @return Collection<int, HouseholdInvitation> */
    #[Computed]
    public function invitations(): Collection
    {
        return HouseholdInvitation::query()->where('household_id', $this->household->id)->whereNull('accepted_at')->where('expires_at', '>', now())->with('person')->latest()->get();
    }

    /** @return Collection<int, Person> */
    #[Computed]
    public function unlinkedPeople(): Collection
    {
        return Person::query()->where('household_id', $this->household->id)->active()->whereNull('user_id')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('manage', $this->household);
        $this->validate(['role' => ['in:editor,member'], 'personId' => ['nullable', 'integer']]);

        $invitation = HouseholdInvitation::create([
            'household_id' => $this->household->id,
            'created_by' => auth()->id(),
            'person_id' => $this->personId ? $this->unlinkedPeople->firstWhere('id', $this->personId)?->id : null,
            'role' => MembershipRole::from($this->role),
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);

        $this->createdLink = route('invite.show', $invitation->token);
        $this->personId = null;
        unset($this->invitations);
    }

    public function revoke(int $invitationId): void
    {
        $this->authorize('manage', $this->household);
        HouseholdInvitation::query()->where('household_id', $this->household->id)->whereKey($invitationId)->delete();
        unset($this->invitations);
    }

    public function setRole(int $membershipId, string $role): void
    {
        $this->authorize('manage', $this->household);
        $membership = HouseholdMembership::query()->where('household_id', $this->household->id)->findOrFail($membershipId);
        if ($membership->user_id === $this->household->owner_user_id) {
            return;
        }
        $membership->update(['role' => MembershipRole::from($role)]);
        unset($this->members);
    }

    public function remove(int $membershipId): void
    {
        $this->authorize('manage', $this->household);
        $membership = HouseholdMembership::query()->where('household_id', $this->household->id)->findOrFail($membershipId);
        if ($membership->user_id === $this->household->owner_user_id) {
            return;
        }
        Person::query()->where('household_id', $this->household->id)->where('user_id', $membership->user_id)->update(['user_id' => null]);
        $membership->delete();
        unset($this->members);
    }

    public function render(): View
    {
        return view('livewire.household-invitations');
    }
}
