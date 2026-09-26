<?php

namespace App\Models;

use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund / withdrawal / dispute. Money moves in Stripe; uses and the paid period are revoked here, auditable.
 *
 * @property int $id
 * @property int $order_id
 * @property int $household_id
 * @property RefundKind $kind
 * @property int $amount_cents
 * @property string $currency
 * @property string $reason
 * @property array<string, int>|null $units_revoked
 * @property bool $revoke_entitlement
 * @property string|null $stripe_refund_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_dispute_id
 * @property string $idempotency_key
 * @property RefundStatus $status
 * @property int|null $requested_by
 * @property string|null $error
 * @property CarbonInterface|null $processed_at
 */
#[Fillable([
    'order_id', 'household_id', 'kind', 'amount_cents', 'currency', 'reason', 'units_revoked', 'revoke_entitlement',
    'stripe_refund_id', 'stripe_payment_intent_id', 'stripe_dispute_id', 'idempotency_key', 'status', 'requested_by', 'error', 'processed_at',
])]
class RefundCase extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => RefundKind::class,
            'amount_cents' => 'integer',
            'units_revoked' => 'array',
            'revoke_entitlement' => 'boolean',
            'status' => RefundStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
