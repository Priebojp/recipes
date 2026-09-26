<?php

namespace App\Models;

use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of AI uses. The counters cache the ledger: available = quantity − reserved − consumed − revoked.
 *
 * @property int $id
 * @property int $household_id
 * @property UsageKind $kind
 * @property UsageGrantSource $source
 * @property string $source_key
 * @property int|null $order_id
 * @property int|null $paid_entitlement_id
 * @property int $quantity
 * @property int $reserved_quantity
 * @property int $consumed_quantity
 * @property int $revoked_quantity
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property string|null $note
 * @property array<string, mixed>|null $meta
 * @property int|null $created_by
 */
#[Fillable([
    'household_id', 'kind', 'source', 'source_key', 'order_id', 'paid_entitlement_id', 'quantity', 'reserved_quantity',
    'consumed_quantity', 'revoked_quantity', 'valid_from', 'expires_at', 'revoked_at', 'note', 'meta', 'created_by',
])]
class UsageGrant extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => UsageKind::class,
            'source' => UsageGrantSource::class,
            'quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'consumed_quantity' => 'integer',
            'revoked_quantity' => 'integer',
            'valid_from' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<UsageReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(UsageReservation::class);
    }

    /** @return HasMany<UsageLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(UsageLedgerEntry::class);
    }

    /** Uses that can still be reserved (ignores validity window). */
    public function available(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity - $this->consumed_quantity - $this->revoked_quantity);
    }

    /** Uses this grant still counts towards the visible total ("x / 30"). */
    public function effectiveQuantity(): int
    {
        return max(0, $this->quantity - $this->revoked_quantity);
    }

    public function isValidAt(CarbonInterface $at): bool
    {
        return $this->valid_from <= $at && ($this->expires_at === null || $this->expires_at > $at);
    }

    /**
     * Grants that may be drawn from at the given moment.
     *
     * @param  Builder<UsageGrant>  $query
     */
    public function scopeValidAt(Builder $query, CarbonInterface $at): void
    {
        $query->where('valid_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at));
    }

    /**
     * Consumption order: soonest expiry first (trial / monthly), then the oldest purchased grant.
     *
     * @param  Builder<UsageGrant>  $query
     */
    public function scopeInConsumptionOrder(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('valid_from')
            ->orderBy('id');
    }
}
