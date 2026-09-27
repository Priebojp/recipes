<?php

namespace App\Models;

use App\Enums\CatalogState;
use App\Enums\PlanInterval;
use App\Enums\UsageKind;
use App\Services\Ai\ImageProfile;
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
 * @property string $image_profile_code
 * @property int $meal_analysis_uses_per_period
 * @property list<string>|null $features
 * @property CatalogState $state
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $retired_at
 */
#[Fillable([
    'code', 'product_code', 'version', 'name', 'interval', 'final_price_cents', 'currency', 'stripe_price_id',
    'text_uses_per_period', 'image_uses_per_period', 'image_profile_code', 'meal_analysis_uses_per_period', 'features', 'state', 'effective_from', 'retired_at',
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
            'meal_analysis_uses_per_period' => 'integer',
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

    /**
     * The image profile the plan's image uses pay for (v2.1 stage 13). Versions sold before the column existed
     * carry the default and stay Standard – a grant never changes tier under a customer.
     */
    public function imageProfile(): ImageProfile
    {
        return ImageProfile::tryFrom((string) $this->image_profile_code) ?? ImageProfile::LEGACY;
    }

    /**
     * Uses included per monthly period, keyed by usage kind value. Image uses are of the profile's kind (Economy or
     * Standard, never both); photo analyses appear only when the version includes them.
     */
    /** @return array<string, int> */
    public function usesPerPeriod(): array
    {
        $uses = [
            UsageKind::Text->value => $this->text_uses_per_period,
            $this->imageProfile()->usageKind()->value => $this->image_uses_per_period,
        ];
        if ($this->meal_analysis_uses_per_period > 0) {
            $uses[UsageKind::MealAnalysis->value] = $this->meal_analysis_uses_per_period;
        }

        return $uses;
    }

    /**
     * Human lines for what a period includes, from a uses-per-period map (a live version or an order snapshot).
     *
     * @param  array<string, int>  $usesPerPeriod
     * @return list<string>
     */
    public static function describeUses(array $usesPerPeriod): array
    {
        $lines = [];
        foreach ($usesPerPeriod as $kind => $count) {
            $kind = UsageKind::tryFrom((string) $kind);
            if ($kind === null || (int) $count < 1) {
                continue;
            }
            $lines[] = __(':count × :kind za mesačné obdobie', ['count' => (int) $count, 'kind' => $kind->label()]);
        }

        return $lines;
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
            'image_profile_code' => $this->imageProfile()->value,
            'meal_analysis_uses_per_period' => $this->meal_analysis_uses_per_period,
            'features' => $this->features ?? [],
        ];
    }
}
