<?php

namespace App\Http\Controllers;

use App\Models\HouseholdInvitation;
use App\Models\HouseholdMembership;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Single-use, expiring invitations into a household.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = HouseholdInvitation::query()->where('token', $token)->with('household')->firstOrFail();

        if (! $invitation->isUsable()) {
            return redirect()->route('cook.index')->with('status', 'Pozvánka už nie je platná.');
        }

        return view('invitations.show', ['invitation' => $invitation]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = HouseholdInvitation::query()->where('token', $token)->lockForUpdate()->firstOrFail();
        abort_unless($invitation->isUsable(), 410);

        $user = $request->user();

        DB::transaction(function () use ($invitation, $user, $request) {
            HouseholdMembership::query()->firstOrCreate(
                ['household_id' => $invitation->household_id, 'user_id' => $user->id],
                ['role' => $invitation->role],
            );

            // Link the pre-selected profile only when the inviter chose one; never merge by name automatically.
            if ($invitation->person_id && $request->boolean('link_person', true)) {
                Person::query()->where('household_id', $invitation->household_id)->whereKey($invitation->person_id)->whereNull('user_id')->update(['user_id' => $user->id]);
            } elseif (! Person::query()->where('household_id', $invitation->household_id)->where('user_id', $user->id)->exists()) {
                Person::create(['household_id' => $invitation->household_id, 'user_id' => $user->id, 'name' => $user->name]);
            }

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
        });

        session(['current_household_id' => $invitation->household_id]);

        return redirect()->route('cook.index')->with('status', 'Vitaj v domácnosti '.$invitation->household->name.'.');
    }
}
