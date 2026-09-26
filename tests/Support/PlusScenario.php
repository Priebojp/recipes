<?php

namespace Tests\Support;

use App\Models\Household;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use Database\Seeders\CatalogSeeder;

/**
 * Gives a test household an active Plus period the same way the admin compensation does: a paid entitlement
 * without an order. Plus features derive from paid periods only, so nothing else is needed.
 */
class PlusScenario
{
    public static function activate(Household $household, int $days = 30): PaidEntitlement
    {
        if (PlanVersion::query()->count() === 0) {
            test()->seed(CatalogSeeder::class);
        }

        return PaidEntitlement::create([
            'household_id' => $household->id,
            'order_id' => null,
            'plan_version_id' => PlanVersion::query()->where('code', 'plus_monthly')->firstOrFail()->id,
            'source_key' => 'test:plus:'.$household->id.':'.uniqid(),
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays($days),
        ]);
    }

    /** End every Plus period of the household now (as after a lapsed subscription). */
    public static function expire(Household $household): void
    {
        PaidEntitlement::query()->where('household_id', $household->id)->update(['ends_at' => now()->subMinute()]);
    }
}
