<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paid Plus period [starts_at, ends_at). Cancelling renewal never shortens it; a refund revokes it.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $order_id
 * @property int $plan_version_id
 * @property string $source_key
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_invoice_id
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoke_reason
 */
#[Fillable(['household_id', 'order_id', 'plan_version_id', 'source_key', 'stripe_subscription_id', 'stripe_invoice_id', 'starts_at', 'ends_at', 'revoked_at', 'revoke_reason'])]
class PaidEntitlement extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return HasMany<UsageGrant, $this> */
    public function usageGrants(): HasMany
    {
        return $this->hasMany(UsageGrant::class);
    }

    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->revoked_at === null && $this->starts_at <= $at && $this->ends_at > $at;
    }
}
