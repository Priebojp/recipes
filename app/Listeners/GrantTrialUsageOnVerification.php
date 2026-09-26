<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Usage\TrialGrants;
use Illuminate\Auth\Events\Verified;

/**
 * The trial is tied to a verified e-mail: granted once the owner confirms it (and lazily on the first AI request
 * for accounts verified before the ledger existed).
 */
class GrantTrialUsageOnVerification
{
    public function __construct(private TrialGrants $trials) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        foreach ($user->households()->where('owner_user_id', $user->id)->get() as $household) {
            $this->trials->ensureFor($household);
        }
    }
}
