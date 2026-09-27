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
 *
 * v2.1 (stage 13): the seeder never creates version 2 of the plans – Economy images and included photo analyses are
 * the administrator's decision in /admin/catalog after the comparison and the measurement. The proposed pack of
 * 100 photo analyses is seeded as a *draft* (nothing is sold until activated); an Economy pack has no price until the
 * test, so it is not seeded at all.
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
                'image_profile_code' => 'image_standard_v1',
                'meal_analysis_uses_per_period' => 0,
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

        // v2.1 proposal (addendum chapter 8): 100 photo analyses for 1,99 € – a draft until the operator confirms the price.
        $row = AddonVersion::query()->firstOrCreate(['code' => 'meal_analyses_100', 'version' => 1], [
            'name' => '100 analýz jedla z fotky',
            'unit_kind' => UsageKind::MealAnalysis,
            'unit_count' => 100,
            'final_price_cents' => 199,
            'currency' => 'EUR',
            'stripe_price_id' => $prices['meal_analyses_100'] ?? null,
            'state' => CatalogState::Draft,
        ]);
        if (! $row->stripe_price_id && ! empty($prices['meal_analyses_100'])) {
            $row->update(['stripe_price_id' => $prices['meal_analyses_100']]);
        }
    }
}
