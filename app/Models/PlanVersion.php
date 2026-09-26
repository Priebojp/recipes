<?php

namespace App\Models;

use App\Enums\CatalogState;
use App\Enums\PlanInterval;
use App\Enums\UsageKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a subscription offer. A price change is a new version; purchased orders keep the old one.
 *
 * @property int $id
 * @property string $code
 * @property string $product_code
 * @property int $version
 * @property string $name
 * @property PlanInterval $interval
 * @property int $final_price_cents
 * @property string $currency
 * @property string|null $stripe_price_id
 * @property int $text_uses_per_period
 * @property int $image_uses_per_period
 * @property list<string>|null $features
 * @property CatalogState $state
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $retired_at
 */
#[Fillable([
    'code', 'product_code', 'version', 'name', 'interval', 'final_price_cents', 'currency', 'stripe_price_id',
    'text_uses_per_period', 'image_uses_per_period', 'features', 'state', 'effective_from', 'retired_at',
])]
class PlanVersion extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'interval' => PlanInterval::class,
            'final_price_cents' => 'integer',
            'text_uses_per_period' => 'integer',
            'image_uses_per_period' => 'integer',
            'features' => 'array',
            'state' => CatalogState::class,
            'effective_from' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** @param  Builder<PlanVersion>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('state', CatalogState::Active);
    }

    /** Uses included per monthly period, keyed by usage kind value. */
    /** @return array<string, int> */
    public function usesPerPeriod(): array
    {
        return [
            UsageKind::Text->value => $this->text_uses_per_period,
            UsageKind::ImageStandard->value => $this->image_uses_per_period,
        ];
    }

    /** "24 € ročne" for the yearly plan corresponds to this monthly amount (display only). */
    public function monthlyEquivalentCents(): int
    {
        return $this->interval === PlanInterval::Year ? intdiv($this->final_price_cents, 12) : $this->final_price_cents;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'type' => 'plan',
            'plan_version_id' => $this->id,
            'code' => $this->code,
            'version' => $this->version,
            'name' => $this->name,
            'interval' => $this->interval->value,
            'final_price_cents' => $this->final_price_cents,
            'currency' => $this->currency,
            'stripe_price_id' => $this->stripe_price_id,
            'uses_per_period' => $this->usesPerPeriod(),
            'features' => $this->features ?? [],
        ];
    }
}
