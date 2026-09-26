<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's online withdrawal from the contract. Receipt is confirmed by e-mail at once; the refund follows the
 * refund workflow (RefundService) and is linked here.
 *
 * @property int $id
 * @property string $reference
 * @property string $email
 * @property string|null $order_reference
 * @property int|null $order_id
 * @property int|null $user_id
 * @property string|null $message
 * @property WithdrawalStatus $status
 * @property int|null $refund_case_id
 * @property string|null $decision_note
 * @property int|null $handled_by
 * @property CarbonInterface $received_at
 * @property CarbonInterface|null $receipt_sent_at
 * @property CarbonInterface|null $decided_at
 */
#[Fillable(['reference', 'email', 'order_reference', 'order_id', 'user_id', 'message', 'status', 'refund_case_id', 'decision_note', 'handled_by', 'received_at', 'receipt_sent_at', 'decided_at'])]
class WithdrawalRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'received_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RefundCase, $this> */
    public function refundCase(): BelongsTo
    {
        return $this->belongsTo(RefundCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
