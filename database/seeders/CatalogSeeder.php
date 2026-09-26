<?php

namespace Database\Seeders;

use App\Enums\CatalogState;
use App\Enums\PlanInterval;
use App\Enums\UsageKind;
use App\Models\AddonVersion;
use App\Models\PlanVersion;
use Illuminate\Database\Seeder;

/**
 * Version 1 of the proposed public price list (specification chapter 2). Prices are the operator's to confirm before
 * live payments; a change is a new version, never an edit of a sold one. Stripe price IDs come from the environment.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $prices = (array) config('recipes.billing.stripe_prices', []);
        $features = ['Návrh týždenného jedálnička', 'Nákupný zoznam z plánu', 'Uložené skupiny a filtre výberu'];

        foreach ([
            ['code' => 'plus_monthly', 'name' => 'Plus mesačne', 'interval' => PlanInterval::Month, 'price' => 249],
            ['code' => 'plus_yearly', 'name' => 'Plus ročne', 'interval' => PlanInterval::Year, 'price' => 2400],
        ] as $plan) {
            $row = PlanVersion::query()->firstOrCreate(['code' => $plan['code'], 'version' => 1], [
                'product_code' => 'plus',
                'name' => $plan['name'],
                'interval' => $plan['interval'],
                'final_price_cents' => $plan['price'],
                'currency' => 'EUR',
                'stripe_price_id' => $prices[$plan['code']] ?? null,
                'text_uses_per_period' => 30,
                'image_uses_per_period' => 5,
                'features' => $features,
                'state' => CatalogState::Active,
                'effective_from' => now(),
            ]);
            if (! $row->stripe_price_id && ! empty($prices[$plan['code']])) {
                $row->update(['stripe_price_id' => $prices[$plan['code']]]);
            }
        }

        foreach ([
            ['code' => 'images_20_standard', 'name' => '20 obrázkov Standard', 'kind' => UsageKind::ImageStandard, 'count' => 20, 'price' => 399],
            ['code' => 'text_100', 'name' => '100 textových operácií', 'kind' => UsageKind::Text, 'count' => 100, 'price' => 199],
        ] as $addon) {
            $row = AddonVersion::query()->firstOrCreate(['code' => $addon['code'], 'version' => 1], [
                'name' => $addon['name'],
                'unit_kind' => $addon['kind'],
                'unit_count' => $addon['count'],
                'final_price_cents' => $addon['price'],
                'currency' => 'EUR',
                'stripe_price_id' => $prices[$addon['code']] ?? null,
                'state' => CatalogState::Active,
                'effective_from' => now(),
            ]);
            if (! $row->stripe_price_id && ! empty($prices[$addon['code']])) {
                $row->update(['stripe_price_id' => $prices[$addon['code']]]);
            }
        }
    }
}
