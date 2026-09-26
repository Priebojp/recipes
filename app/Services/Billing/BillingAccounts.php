<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
use App\Models\Household;
use App\Models\User;

class BillingAccounts
{
    /** The household's billing account, created on first use with the owner as payer. */
    public function forHousehold(Household $household, ?User $payer = null): BillingAccount
    {
        return BillingAccount::query()->firstOrCreate(
            ['household_id' => $household->id],
            ['payer_user_id' => $payer !== null ? $payer->id : $household->owner_user_id],
        );
    }
}
