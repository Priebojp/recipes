<?php

namespace App\Services\Billing;

use App\Enums\PlanInterval;
use App\Models\AddonVersion;
use App\Models\PlanVersion;
use Illuminate\Support\Collection;

/**
 * Server-side, versioned offer catalogue. The client never sends a price – only an offer code from here.
 */
class Catalog
{
    /** @return Collection<int, PlanVersion> active plans, monthly first */
    public function plans(): Collection
    {
        return PlanVersion::query()->active()->get()->sortBy(fn (PlanVersion $p) => $p->interval === PlanInterval::Month ? 0 : 1)->values();
    }

    /** @return Collection<int, AddonVersion> */
    public function addons(): Collection
    {
        return AddonVersion::query()->active()->orderBy('id')->get();
    }

    public function plan(string $code): ?PlanVersion
    {
        return PlanVersion::query()->active()->where('code', $code)->orderByDesc('version')->first();
    }

    public function addon(string $code): ?AddonVersion
    {
        return AddonVersion::query()->active()->where('code', $code)->orderByDesc('version')->first();
    }

    /** Any version (also retired) sold under the given Stripe price – paid invoices always resolve. */
    public function planForStripePrice(?string $priceId): ?PlanVersion
    {
        return $priceId ? PlanVersion::query()->where('stripe_price_id', $priceId)->orderByDesc('version')->first() : null;
    }

    public function addonForStripePrice(?string $priceId): ?AddonVersion
    {
        return $priceId ? AddonVersion::query()->where('stripe_price_id', $priceId)->orderByDesc('version')->first() : null;
    }

    /** "2,49 €" from cents. */
    public static function formatCents(int $cents, string $currency = 'EUR'): string
    {
        $symbol = strtoupper($currency) === 'EUR' ? '€' : strtoupper($currency);

        return number_format($cents / 100, 2, ',', ' ').' '.$symbol;
    }
}
