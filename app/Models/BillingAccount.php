<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * The Stripe customer of a household (1:1). The household owner manages it; members never see billing details.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $payer_user_id
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property CarbonInterface|null $trial_ends_at
 * @property string|null $billing_name
 * @property string|null $billing_email
 * @property array<string, string>|null $billing_address
 */
#[Fillable(['household_id', 'payer_user_id', 'billing_name', 'billing_email', 'billing_address'])]
class BillingAccount extends Model
{
    use Billable;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'billing_address' => 'array',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function stripeName(): ?string
    {
        return $this->billing_name ?: $this->household?->name;
    }

    public function stripeEmail(): ?string
    {
        return $this->billing_email ?: $this->payer?->email;
    }

    /** @return array<string, string> */
    public function stripeAddress(): array
    {
        return $this->billing_address ?? [];
    }

    /** @return array<string, string> */
    public function stripeMetadata(): array
    {
        return ['household_id' => (string) $this->household_id];
    }

    public function preferredCurrency(): string
    {
        return (string) config('cashier.currency', 'eur');
    }
}
