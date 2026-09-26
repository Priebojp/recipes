<?php

namespace App\Services\Billing;

use App\Enums\CatalogState;
use App\Models\AddonVersion;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Versioned catalogue changes (specification chapter 8): a change is a new draft version; activating it retires
 * the previous active version of the same code. Sold orders keep their snapshot, entitlements their plan version.
 */
class CatalogManager
{
    public function __construct(private AdminAuditor $audit) {}

    /**
     * @param  array{name?: string, final_price_cents?: int, stripe_price_id?: ?string, text_uses_per_period?: int, image_uses_per_period?: int, features?: list<string>}  $changes
     */
    public function newPlanVersion(PlanVersion $base, array $changes, string $reason, ?User $by = null): PlanVersion
    {
        $next = (int) PlanVersion::query()->where('code', $base->code)->max('version') + 1;

        $version = PlanVersion::create([
            'code' => $base->code,
            'product_code' => $base->product_code,
            'version' => $next,
            'name' => $changes['name'] ?? $base->name,
            'interval' => $base->interval,
            'final_price_cents' => $changes['final_price_cents'] ?? $base->final_price_cents,
            'currency' => $base->currency,
            'stripe_price_id' => array_key_exists('stripe_price_id', $changes) ? ($changes['stripe_price_id'] ?: null) : $base->stripe_price_id,
            'text_uses_per_period' => $changes['text_uses_per_period'] ?? $base->text_uses_per_period,
            'image_uses_per_period' => $changes['image_uses_per_period'] ?? $base->image_uses_per_period,
            'features' => $changes['features'] ?? $base->features,
            'state' => CatalogState::Draft,
        ]);

        $this->audit->record('catalog.plan.version_created', $version, $base->only(['version', 'final_price_cents', 'stripe_price_id', 'text_uses_per_period', 'image_uses_per_period']), $version->only(['version', 'final_price_cents', 'stripe_price_id', 'text_uses_per_period', 'image_uses_per_period']), $reason, $by);

        return $version;
    }

    /**
     * @param  array{name?: string, final_price_cents?: int, stripe_price_id?: ?string, unit_count?: int}  $changes
     */
    public function newAddonVersion(AddonVersion $base, array $changes, string $reason, ?User $by = null): AddonVersion
    {
        $next = (int) AddonVersion::query()->where('code', $base->code)->max('version') + 1;

        $version = AddonVersion::create([
            'code' => $base->code,
            'version' => $next,
            'name' => $changes['name'] ?? $base->name,
            'unit_kind' => $base->unit_kind,
            'unit_count' => $changes['unit_count'] ?? $base->unit_count,
            'final_price_cents' => $changes['final_price_cents'] ?? $base->final_price_cents,
            'currency' => $base->currency,
            'stripe_price_id' => array_key_exists('stripe_price_id', $changes) ? ($changes['stripe_price_id'] ?: null) : $base->stripe_price_id,
            'state' => CatalogState::Draft,
        ]);

        $this->audit->record('catalog.addon.version_created', $version, $base->only(['version', 'final_price_cents', 'stripe_price_id', 'unit_count']), $version->only(['version', 'final_price_cents', 'stripe_price_id', 'unit_count']), $reason, $by);

        return $version;
    }

    /** Draft → active; the previously active version of the same code is retired in the same transaction. */
    public function activate(PlanVersion|AddonVersion $version, string $reason, ?User $by = null): void
    {
        if ($version->state !== CatalogState::Draft) {
            throw new InvalidArgumentException('Aktivovať možno iba návrh.');
        }

        DB::transaction(function () use ($version, $reason, $by) {
            $retired = $version->newQuery()
                ->where('code', $version->code)
                ->where('state', CatalogState::Active)
                ->whereKeyNot($version->getKey())
                ->get();

            foreach ($retired as $old) {
                $old->update(['state' => CatalogState::Retired, 'retired_at' => now()]);
            }

            $version->update(['state' => CatalogState::Active, 'effective_from' => now(), 'retired_at' => null]);

            $this->audit->record(
                ($version instanceof PlanVersion ? 'catalog.plan' : 'catalog.addon').'.activated',
                $version,
                ['retired_versions' => $retired->pluck('version')->all()],
                ['version' => $version->version, 'final_price_cents' => $version->final_price_cents, 'stripe_price_id' => $version->stripe_price_id],
                $reason,
                $by,
            );
        });
    }

    /** Stop selling a version. Nothing bought under it changes. */
    public function retire(PlanVersion|AddonVersion $version, string $reason, ?User $by = null): void
    {
        if ($version->state === CatalogState::Retired) {
            return;
        }

        $version->update(['state' => CatalogState::Retired, 'retired_at' => now()]);
        $this->audit->record(($version instanceof PlanVersion ? 'catalog.plan' : 'catalog.addon').'.retired', $version, [], ['version' => $version->version], $reason, $by);
    }
}
