<?php

namespace App\Models;

use App\Enums\LegalAcceptanceAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence that a person accepted a specific document version in a specific act.
 *
 * @property int $id
 * @property int $legal_document_version_id
 * @property int|null $user_id
 * @property int|null $household_id
 * @property int|null $order_id
 * @property LegalAcceptanceAction $action
 * @property string $checksum
 * @property array<string, mixed>|null $acknowledgements
 * @property string|null $email
 * @property CarbonInterface $accepted_at
 */
#[Fillable(['legal_document_version_id', 'user_id', 'household_id', 'order_id', 'action', 'checksum', 'acknowledgements', 'email', 'accepted_at'])]
class LegalAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'action' => LegalAcceptanceAction::class,
            'acknowledgements' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LegalDocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
