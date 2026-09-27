<?php

namespace App\Services\Billing;

use App\Enums\CatalogState;
use App\Enums\UsageKind;
use App\Models\AddonVersion;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Ai\ImageProfile;
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
     * @param  array{name?: string, final_price_cents?: int, stripe_price_id?: ?string, text_uses_per_period?: int, image_uses_per_period?: int, image_profile_code?: string, meal_analysis_uses_per_period?: int, features?: list<string>}  $changes
     */
    public function newPlanVersion(PlanVersion $base, array $changes, string $reason, ?User $by = null): PlanVersion
    {
        $profile = isset($changes['image_profile_code']) ? ImageProfile::tryFrom((string) $changes['image_profile_code']) : $base->imageProfile();
        if ($profile === null || ! in_array($profile, ImageProfile::selectable(), true)) {
            throw new InvalidArgumentException(__('Neznámy alebo nepredajný profil obrázkov.'));
        }

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
            'image_profile_code' => $profile->value,
            'meal_analysis_uses_per_period' => $changes['meal_analysis_uses_per_period'] ?? $base->meal_analysis_uses_per_period,
            'features' => $changes['features'] ?? $base->features,
            'state' => CatalogState::Draft,
        ]);

        $fields = ['version', 'final_price_cents', 'stripe_price_id', 'text_uses_per_period', 'image_uses_per_period', 'image_profile_code', 'meal_analysis_uses_per_period'];
        $this->audit->record('catalog.plan.version_created', $version, $base->only($fields), $version->only($fields), $reason, $by);

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

    /**
     * A pack under a new code (v2.1 stage 13: photo analyses, Economy images): version 1 as a draft, sold only after
     * activation. The code is fixed once created; a later change is a new version.
     */
    public function createAddon(string $code, string $name, UsageKind $kind, int $unitCount, int $finalPriceCents, ?string $stripePriceId, string $reason, ?User $by = null): AddonVersion
    {
        $code = strtolower(trim($code));
        if (! preg_match('/^[a-z][a-z0-9_]{2,49}$/', $code)) {
            throw new InvalidArgumentException(__('Kód balíka: malé písmená, číslice a podčiarkovník, napr. meal_analyses_100.'));
        }
        if (AddonVersion::query()->where('code', $code)->exists()) {
            throw new InvalidArgumentException(__('Balík :code už existuje – vytvor jeho novú verziu.', ['code' => $code]));
        }
        if ($unitCount < 1 || $finalPriceCents < 1) {
            throw new InvalidArgumentException(__('Balík potrebuje aspoň jednu jednotku a konečnú cenu.'));
        }

        $version = AddonVersion::create([
            'code' => $code,
            'version' => 1,
            'name' => $name,
            'unit_kind' => $kind,
            'unit_count' => $unitCount,
            'final_price_cents' => $finalPriceCents,
            'currency' => 'EUR',
            'stripe_price_id' => $stripePriceId ?: null,
            'state' => CatalogState::Draft,
        ]);

        $this->audit->record('catalog.addon.created', $version, [], $version->only(['code', 'version', 'unit_kind', 'unit_count', 'final_price_cents', 'stripe_price_id']), $reason, $by);

        return $version;
    }

    /** Draft → active; the previously active version of the same code is retired in the same transaction. */
    public function activate(PlanVersion|AddonVersion $version, string $reason, ?User $by = null): void
    {
        if ($version->state !== CatalogState::Draft) {
            throw new InvalidArgumentException(__('Aktivovať možno iba návrh.'));
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
