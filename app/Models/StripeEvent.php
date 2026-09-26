<?php

namespace App\Models;

use App\Enums\StripeEventState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Webhook inbox row. Stored before the webhook is acknowledged, processed once, retried when processing failed.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $object_id
 * @property bool $livemode
 * @property StripeEventState $state
 * @property int $attempts
 * @property string|null $last_error
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $event_created_at
 * @property CarbonInterface|null $processed_at
 */
#[Fillable(['event_id', 'type', 'object_id', 'livemode', 'state', 'attempts', 'last_error', 'payload', 'event_created_at', 'processed_at'])]
class StripeEvent extends Model
{
    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'state' => StripeEventState::class,
            'attempts' => 'integer',
            'payload' => 'array',
            'event_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The Stripe object the event is about (data.object).
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        return $this->payload;
    }
}
