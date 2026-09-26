<?php

namespace App\Models;

use App\Enums\CatalogState;
use App\Enums\UsageKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a one-off usage pack (no renewal, no calendar expiry while the service runs).
 *
 * @property int $id
 * @property string $code
 * @property int $version
 * @property string $name
 * @property UsageKind $unit_kind
 * @property int $unit_count
 * @property int $final_price_cents
 * @property string $currency
 * @property string|null $stripe_price_id
 * @property CatalogState $state
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $retired_at
 */
#[Fillable(['code', 'version', 'name', 'unit_kind', 'unit_count', 'final_price_cents', 'currency', 'stripe_price_id', 'state', 'effective_from', 'retired_at'])]
class AddonVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'unit_kind' => UsageKind::class,
            'unit_count' => 'integer',
            'final_price_cents' => 'integer',
            'state' => CatalogState::class,
            'effective_from' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** @param  Builder<AddonVersion>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('state', CatalogState::Active);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'type' => 'addon',
            'addon_version_id' => $this->id,
            'code' => $this->code,
            'version' => $this->version,
            'name' => $this->name,
            'unit_kind' => $this->unit_kind->value,
            'unit_count' => $this->unit_count,
            'final_price_cents' => $this->final_price_cents,
            'currency' => $this->currency,
            'stripe_price_id' => $this->stripe_price_id,
        ];
    }
}
