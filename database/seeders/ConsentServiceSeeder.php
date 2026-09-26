<?php

namespace Database\Seeders;

use App\Enums\ConsentCategory;
use App\Models\ConsentService;
use Illuminate\Database\Seeder;

/**
 * Inventory of the technologies this application actually uses (verified against the code, not generic text)
 * plus one *disabled* analytics template. No analytics provider is chosen yet – enabling one is an operator
 * decision in /admin → Služby a cookies. Idempotent by key.
 */
class ConsentServiceSeeder extends Seeder
{
    public function run(): void
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'moje-recepty.sk';
        $sessionCookie = (string) config('session.cookie');

        foreach ([
            [
                'key' => 'session',
                'name' => 'Relácia a prihlásenie',
                'provider' => config('app.name'),
                'category' => ConsentCategory::Necessary,
                'purpose' => 'Udržanie prihlásenia a formulárov medzi požiadavkami.',
                'retention' => (int) config('session.lifetime').' minút od poslednej aktivity',
                'location' => 'server aplikácie (EÚ)',
                'storage' => [
                    ['name' => $sessionCookie, 'kind' => 'cookie', 'domain' => $domain, 'duration' => (int) config('session.lifetime').' min', 'purpose' => 'identifikátor relácie'],
                    ['name' => 'XSRF-TOKEN', 'kind' => 'cookie', 'domain' => $domain, 'duration' => (int) config('session.lifetime').' min', 'purpose' => 'ochrana formulárov pred CSRF'],
                    ['name' => 'remember_web_*', 'kind' => 'cookie', 'domain' => $domain, 'duration' => '5 rokov', 'purpose' => 'zapamätané prihlásenie (iba ak ho zvolíte)'],
                ],
                'enabled' => true,
            ],
            [
                'key' => 'consent',
                'name' => 'Voľba cookies',
                'provider' => config('app.name'),
                'category' => ConsentCategory::Necessary,
                'purpose' => 'Zapamätanie vašej voľby voliteľných služieb a pseudonymný identifikátor voľby.',
                'retention' => (int) config('recipes.consent.lifetime_days', 180).' dní',
                'location' => 'prehliadač, server aplikácie (EÚ)',
                'storage' => [
                    ['name' => 'mr_consent', 'kind' => 'cookie', 'domain' => $domain, 'duration' => (int) config('recipes.consent.lifetime_days', 180).' dní', 'purpose' => 'verzia účelov, zvolené kategórie, pseudonymné ID'],
                ],
                'enabled' => true,
            ],
            [
                'key' => 'appearance',
                'name' => 'Vzhľad aplikácie',
                'provider' => config('app.name'),
                'category' => ConsentCategory::Necessary,
                'purpose' => 'Zapamätanie svetlého / tmavého režimu.',
                'retention' => 'do zmeny alebo vymazania úložiska prehliadača',
                'location' => 'iba prehliadač',
                'storage' => [
                    ['name' => 'flux.appearance', 'kind' => 'localStorage', 'domain' => $domain, 'duration' => 'trvalé', 'purpose' => 'zvolený vzhľad'],
                ],
                'enabled' => true,
            ],
            [
                'key' => 'stripe',
                'name' => 'Stripe Checkout',
                'provider' => 'Stripe Payments Europe, Ltd.',
                'category' => ConsentCategory::Necessary,
                'purpose' => 'Platba na stránke Stripe po kliknutí na objednávku; na našich stránkach sa žiadny Stripe skript nenačítava.',
                'retention' => 'podľa Stripe',
                'location' => 'Stripe (EÚ / USA podľa zmluvy so Stripe)',
                'storage' => [],
                'enabled' => true,
            ],
            [
                'key' => 'ga4',
                'name' => 'Google Analytics 4',
                'provider' => 'Google Ireland Ltd.',
                'category' => ConsentCategory::Analytics,
                'purpose' => 'Meranie používania aplikácie (návštevy, všeobecné udalosti bez obsahu receptov).',
                'retention' => '2 mesiace detailných udalostí (návrh)',
                'location' => 'Google (prenos mimo EHP – overiť pred zapnutím)',
                'storage' => [
                    ['name' => '_ga', 'kind' => 'cookie', 'domain' => $domain, 'duration' => '2 roky', 'purpose' => 'rozlíšenie návštevníkov'],
                    ['name' => '_ga_*', 'kind' => 'cookie', 'domain' => $domain, 'duration' => '2 roky', 'purpose' => 'stav relácie merania'],
                ],
                'loader' => ['type' => 'ga4', 'measurement_id' => ''],
                'enabled' => false,
            ],
        ] as $service) {
            ConsentService::query()->firstOrCreate(['key' => $service['key']], $service);
        }
    }
}
