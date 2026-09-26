<?php

namespace App\Services\Plus;

use App\Enums\PlusFeature;
use App\Models\Household;
use App\Services\Billing\PlanStatus;

/**
 * Gate of the Plus features. Access derives from paid periods only (PlanStatus), never from a success URL.
 *
 * Data created while Plus was active stays readable afterwards (existing shopping lists, saved presets); only the
 * Plus actions themselves – proposing a week, generating a list, saving a preset – need an active plan.
 */
class PlusAccess
{
    public function __construct(private PlanStatus $plans) {}

    public function allows(Household $household, PlusFeature $feature): bool
    {
        return $this->plans->isPlus($household);
    }

    /** Abort the request with 403 when the feature is not available to the household. */
    public function assert(Household $household, PlusFeature $feature): void
    {
        abort_unless($this->allows($household, $feature), 403, $feature->label().' je súčasťou programu Plus.');
    }
}
