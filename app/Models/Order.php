<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a household bought, at the catalogue version and price of that moment. Never re-priced.
 *
 * @property int $id
 * @property int $household_id
 * @property int $billing_account_id
 * @property int|null $created_by
 * @property OrderKind $kind
 * @property int|null $plan_version_id
 * @property int|null $addon_version_id
 * @property array<string, mixed> $product_snapshot
 * @property int $amount_cents
 * @property int $tax_cents
 * @property string $currency
 * @property OrderStatus $status
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_invoice_id
 * @property string|null $stripe_subscription_id
 * @property string|null $failure_reason
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable([
    'household_id', 'billing_account_id', 'created_by', 'kind', 'plan_version_id', 'addon_version_id', 'product_snapshot',
    'amount_cents', 'tax_cents', 'currency', 'status', 'stripe_checkout_session_id', 'stripe_payment_intent_id',
    'stripe_invoice_id', 'stripe_subscription_id', 'failure_reason', 'paid_at',
])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => OrderKind::class,
            'product_snapshot' => 'array',
            'amount_cents' => 'integer',
            'tax_cents' => 'integer',
            'status' => OrderStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<BillingAccount, $this> */
    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return BelongsTo<AddonVersion, $this> */
    public function addonVersion(): BelongsTo
    {
        return $this->belongsTo(AddonVersion::class);
    }

    /** @return HasMany<PaidEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PaidEntitlement::class);
    }

    /** @return HasMany<UsageGrant, $this> */
    public function usageGrants(): HasMany
    {
        return $this->hasMany(UsageGrant::class);
    }

    /** @return HasMany<RefundCase, $this> */
    public function refundCases(): HasMany
    {
        return $this->hasMany(RefundCase::class);
    }

    public function productName(): string
    {
        return (string) ($this->product_snapshot['name'] ?? $this->kind->value);
    }
}
