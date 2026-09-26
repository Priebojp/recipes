<?php

namespace App\Services\Launch;

use App\Enums\LaunchCheckStatus;
use App\Enums\LegalDocumentType;
use App\Enums\PlanInterval;
use App\Models\AddonVersion;
use App\Models\AiCostRate;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiCostCalculator;
use App\Services\Ai\AiSettings;
use App\Services\Billing\Catalog;
use App\Services\Billing\Gateway\StripeInspector;
use App\Services\Billing\StripeWebhookEvents;
use App\Services\Legal\CheckoutReadiness;
use App\Services\Legal\LegalDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Launch checklist (v2 stage 7): everything the application can verify about itself before payments are switched on,
 * plus the operator's manual confirmations. A "fail" blocks the go-live; a "warn" is expected outside production or is
 * a recommendation. The checks read configuration and aggregates only – never recipe content or secrets.
 */
class LaunchReadiness
{
    public const GROUP_ENVIRONMENT = 'Prostredie a prevádzka';

    public const GROUP_STRIPE = 'Stripe';

    public const GROUP_CATALOG = 'Katalóg a ceny';

    public const GROUP_LEGAL = 'Právne a prevádzkovateľ';

    public const GROUP_ADMIN = 'Administrácia';

    public const GROUP_AI = 'AI a náklady';

    public const GROUP_SIGNOFFS = 'Ručné potvrdenia';

    public const GROUP_SWITCH = 'Zapnutie platieb';

    public const MEASUREMENT_KEY = 'launch.ai_measurement';

    public const RECONCILE_KEY = 'ops.billing_reconcile_last_run_at';

    /** Text and image jobs the specification asks to measure before launch. */
    public const MEASUREMENT_MINIMUM = 30;

    public function __construct(
        private CheckoutReadiness $checkout,
        private LegalDocuments $documents,
        private Catalog $catalog,
        private AiAvailability $ai,
        private AiSettings $aiSettings,
        private AiCostCalculator $costs,
        private LaunchSignoffs $signoffs,
        private AppSettings $settings,
    ) {}

    /**
     * @param  StripeInspector|null  $stripe  when given, prices and the webhook endpoint are verified in the Stripe account
     * @return list<LaunchCheck>
     */
    public function checks(?StripeInspector $stripe = null): array
    {
        $checks = [
            ...$this->environment(),
            ...$this->stripe($stripe),
            ...$this->catalog($stripe),
            ...$this->legal(),
            ...$this->admin(),
            ...$this->ai(),
            ...$this->signoffs(),
        ];

        $checks[] = $this->switch($checks);

        return $checks;
    }

    /**
     * @param  list<LaunchCheck>  $checks
     * @return array{ok: int, warn: int, fail: int, skip: int, ready: bool}
     */
    public function summary(array $checks): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($checks as $check) {
            $counts[$check->status->value]++;
        }

        return [...$counts, 'ready' => $counts['fail'] === 0];
    }

    /**
     * @param  list<LaunchCheck>  $checks
     * @return array<string, list<LaunchCheck>>
     */
    public function grouped(array $checks): array
    {
        $grouped = [];
        foreach ($checks as $check) {
            $grouped[$check->group][] = $check;
        }

        return $grouped;
    }

    public function isProduction(): bool
    {
        return app()->environment('production');
    }

    /** @return list<LaunchCheck> */
    private function environment(): array
    {
        $g = self::GROUP_ENVIRONMENT;
        $env = (string) app()->environment();
        $checks = [];

        $checks[] = new LaunchCheck('env.production', $g, 'Produkčné prostredie',
            $this->isProduction() ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            'APP_ENV='.$env, $this->isProduction() ? null : 'Toto nie je produkcia; prísne kontroly sú tu iba upozornením.');

        $checks[] = $this->productionOnly('env.debug', $g, 'Ladiaci režim vypnutý', ! config('app.debug'),
            'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'), 'V produkcii musí byť APP_DEBUG=false (inak unikajú detaily chýb).');

        $url = (string) config('app.url');
        $checks[] = $this->productionOnly('env.url', $g, 'Verejná adresa cez HTTPS', str_starts_with($url, 'https://'),
            'APP_URL='.$url, 'Webhook aj Checkout návratové URL sa odvádzajú z APP_URL; v produkcii https://moje-recepty.sk.');

        $queue = (string) config('queue.default');
        $checks[] = $this->productionOnly('env.queue', $g, 'Fronta beží mimo requestu', $queue !== 'sync',
            'QUEUE_CONNECTION='.$queue, 'Stripe udalosti a e-maily sú fronta; spusti worker (php artisan queue:work) a monitoruj ho.');

        $mailer = (string) config('mail.default');
        $checks[] = $this->productionOnly('env.mail', $g, 'Odchádzajúca pošta nastavená', ! in_array($mailer, ['log', 'array', 'null'], true),
            'MAIL_MAILER='.$mailer.' · odosielateľ '.((string) config('mail.from.address')),
            'Potvrdenia objednávok, odstúpenia a exporty odchádzajú e-mailom; nastav produkčný SMTP a odosielateľa v doméne.');

        $lastRun = $this->settings->get(self::RECONCILE_KEY);
        $lastRunAt = is_string($lastRun) ? CarbonImmutable::parse($lastRun) : null;
        $checks[] = match (true) {
            $lastRunAt === null => new LaunchCheck('env.scheduler', $g, 'Scheduler (app:billing-reconcile)', LaunchCheckStatus::Warn,
                'zatiaľ nebežal', 'Nastav cron `php artisan schedule:run` každú minútu; denná úloha otvára mesačné granty a opakuje zlyhané udalosti.'),
            $lastRunAt->lt(CarbonImmutable::now()->subHours(36)) => $this->productionOnly('env.scheduler', $g, 'Scheduler (app:billing-reconcile)', false,
                'posledný beh '.$lastRunAt->toDateTimeString().' UTC', 'Denná úloha nebežala viac než 36 h – skontroluj cron.'),
            default => new LaunchCheck('env.scheduler', $g, 'Scheduler (app:billing-reconcile)', LaunchCheckStatus::Ok, 'posledný beh '.$lastRunAt->toDateTimeString().' UTC'),
        };

        $failed = $this->safeCount(fn () => (int) DB::table('failed_jobs')->count());
        $checks[] = new LaunchCheck('env.failed_jobs', $g, 'Zlyhané úlohy fronty', $failed === 0 ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $failed.' v failed_jobs', $failed === 0 ? null : 'Pozri `php artisan queue:failed`; pred launchom vyčisti alebo zopakuj.');

        return $checks;
    }

    /** @return list<LaunchCheck> */
    private function stripe(?StripeInspector $stripe): array
    {
        $g = self::GROUP_STRIPE;
        $checks = [];

        $secret = (string) config('cashier.secret');
        $public = (string) config('cashier.key');
        $secretMode = $this->keyMode($secret, 'sk_');
        $publicMode = $this->keyMode($public, 'pk_');

        if ($secret === '' || $public === '') {
            $checks[] = new LaunchCheck('stripe.keys', $g, 'Stripe kľúče', LaunchCheckStatus::Fail, 'STRIPE_KEY / STRIPE_SECRET chýba', 'Doplň kľúče z Stripe dashboardu (Developers → API keys).');
        } elseif ($secretMode === null || $publicMode === null || $secretMode !== $publicMode) {
            $checks[] = new LaunchCheck('stripe.keys', $g, 'Stripe kľúče', LaunchCheckStatus::Fail, 'kľúče nie sú z rovnakého režimu (test/live)', 'STRIPE_KEY a STRIPE_SECRET musia byť oba test alebo oba live.');
        } elseif ($secretMode === 'live' && ! $this->isProduction()) {
            $checks[] = new LaunchCheck('stripe.keys', $g, 'Stripe kľúče', LaunchCheckStatus::Fail, 'živé kľúče mimo produkcie', 'Mimo produkcie používaj iba sandbox (sk_test_…); živý kľúč tu znamená skutočné platby.');
        } else {
            $checks[] = $this->productionOnly('stripe.keys', $g, 'Stripe kľúče', $secretMode === 'live', 'režim '.$secretMode,
                'Produkcia potrebuje živé kľúče (sk_live_/pk_live_); testovací kľúč nepredá nič.');
        }

        $webhookSecret = (string) config('cashier.webhook.secret');
        $checks[] = new LaunchCheck('stripe.webhook_secret', $g, 'Webhook signing secret', $webhookSecret !== '' ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            $webhookSecret !== '' ? 'STRIPE_WEBHOOK_SECRET nastavený' : 'STRIPE_WEBHOOK_SECRET chýba',
            $webhookSecret !== '' ? null : 'Bez podpisu sa každá udalosť odmietne (403) a žiadna platba neaktivuje Plus.');

        if ($stripe === null) {
            $checks[] = new LaunchCheck('stripe.webhook_endpoint', $g, 'Webhook endpoint v Stripe', LaunchCheckStatus::Skip, 'neoverené proti Stripe', 'Spusti `php artisan app:launch-check --stripe` alebo „Overiť v Stripe“.');
        } else {
            $checks[] = $this->webhookEndpoint($stripe);
        }

        return $checks;
    }

    private function webhookEndpoint(StripeInspector $stripe): LaunchCheck
    {
        $g = self::GROUP_STRIPE;
        $url = route('cashier.webhook');

        try {
            $endpoints = $stripe->webhookEndpoints();
        } catch (Throwable $e) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, 'Webhook endpoint v Stripe', LaunchCheckStatus::Fail, 'Stripe API: '.mb_substr($e->getMessage(), 0, 160), 'Skontroluj STRIPE_SECRET a sieťové spojenie.');
        }

        $matching = array_values(array_filter($endpoints, fn (array $e) => rtrim($e['url'], '/') === rtrim($url, '/')));
        if ($matching === []) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, 'Webhook endpoint v Stripe', LaunchCheckStatus::Fail,
                'žiadny endpoint pre '.$url.' ('.count($endpoints).' iných)', 'Vytvor ho: `php artisan cashier:webhook` (registruje presný zoznam udalostí) a ulož jeho secret do STRIPE_WEBHOOK_SECRET.');
        }

        $enabled = array_values(array_filter($matching, fn (array $e) => $e['status'] === 'enabled'));
        if ($enabled === []) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, 'Webhook endpoint v Stripe', LaunchCheckStatus::Fail, 'endpoint existuje, ale je vypnutý', 'Zapni ho v Stripe dashboarde (Developers → Webhooks).');
        }

        $missing = StripeWebhookEvents::required();
        foreach ($enabled as $endpoint) {
            $missing = array_values(array_intersect($missing, StripeWebhookEvents::missingFrom($endpoint['enabled_events'])));
        }

        if ($missing !== []) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, 'Webhook endpoint v Stripe', LaunchCheckStatus::Fail,
                'chýbajú udalosti: '.implode(', ', $missing), 'Doplň udalosti na endpointe alebo ho vytvor znova cez `php artisan cashier:webhook`.');
        }

        $live = (bool) $enabled[0]['livemode'];
        $modeMatches = $live === ($this->keyMode((string) config('cashier.secret'), 'sk_') === 'live');

        return new LaunchCheck('stripe.webhook_endpoint', $g, 'Webhook endpoint v Stripe', $modeMatches ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            $enabled[0]['id'].' · '.($live ? 'live' : 'test').' · '.count($enabled[0]['enabled_events']).' udalostí',
            $modeMatches ? null : 'Endpoint je v inom režime než kľúče.');
    }

    /** @return list<LaunchCheck> */
    private function catalog(?StripeInspector $stripe): array
    {
        $g = self::GROUP_CATALOG;
        $checks = [];
        $plans = $this->catalog->plans();
        $addons = $this->catalog->addons();

        if ($plans->isEmpty()) {
            $checks[] = new LaunchCheck('catalog.active', $g, 'Aktívny katalóg', LaunchCheckStatus::Fail, 'žiadny aktívny plán', 'Spusti `php artisan db:seed --class=CatalogSeeder` alebo aktivuj verziu v /admin/catalog.');
        } else {
            $unsellable = collect([...$plans->all(), ...$addons->all()])->filter(fn ($v) => ! $v->stripe_price_id)->map(fn ($v) => $v->code)->values()->all();
            $checks[] = new LaunchCheck('catalog.active', $g, 'Aktívny katalóg', $unsellable === [] ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
                $plans->count().' plány, '.$addons->count().' balíky'.($unsellable !== [] ? ' · bez Stripe price ID: '.implode(', ', $unsellable) : ''),
                $unsellable === [] ? null : 'Ponuka bez Stripe price ID je nepredajná; doplň ID novou verziou katalógu alebo CatalogSeederom.');
        }

        $envPrices = (array) config('recipes.billing.stripe_prices', []);
        $mismatch = [];
        $unsetEnv = [];
        // Base collection: PlanVersion and AddonVersion ids collide, so merge() would drop rows.
        foreach (collect([...$plans->all(), ...$addons->all()]) as $version) {
            $envId = $envPrices[$version->code] ?? null;
            if (! filled($envId)) {
                $unsetEnv[] = $version->code;
            } elseif ($version->stripe_price_id !== null && $version->stripe_price_id !== $envId) {
                $mismatch[] = $version->code;
            }
        }
        $checks[] = match (true) {
            $mismatch !== [] => new LaunchCheck('catalog.env_match', $g, 'Katalóg ↔ STRIPE_PRICE_*', LaunchCheckStatus::Fail, 'iné ID než .env: '.implode(', ', $mismatch),
                'Katalóg predáva ID uložené v DB. Po zmene .env vytvor novú verziu s novým ID (alebo seeder na prázdne ID); inak sa predáva staré ID.'),
            $unsetEnv !== [] => new LaunchCheck('catalog.env_match', $g, 'Katalóg ↔ STRIPE_PRICE_*', LaunchCheckStatus::Warn, 'v .env chýba: '.implode(', ', $unsetEnv), 'Nie je chyba, ak sú ID iba v katalógu; udrž .env a katalóg v zhode pre ďalšie nasadenia.'),
            default => new LaunchCheck('catalog.env_match', $g, 'Katalóg ↔ STRIPE_PRICE_*', LaunchCheckStatus::Ok, 'ID v katalógu a v .env sa zhodujú'),
        };

        return [...$checks, ...$this->catalogPricesInStripe($plans, $addons, $stripe)];
    }

    /**
     * @param  Collection<int, PlanVersion>  $plans
     * @param  Collection<int, AddonVersion>  $addons
     * @return list<LaunchCheck>
     */
    private function catalogPricesInStripe($plans, $addons, ?StripeInspector $stripe): array
    {
        $g = self::GROUP_CATALOG;
        $checks = [];
        $live = $this->keyMode((string) config('cashier.secret'), 'sk_') === 'live';

        // Base collection: PlanVersion and AddonVersion ids collide, so merge() would drop rows.
        foreach (collect([...$plans->all(), ...$addons->all()]) as $version) {
            $key = 'catalog.stripe.'.$version->code;
            $label = 'Stripe cena: '.$version->name;
            $expected = Catalog::formatCents($version->final_price_cents, $version->currency);
            if (! $version->stripe_price_id) {
                continue;
            }
            if ($stripe === null) {
                $checks[] = new LaunchCheck($key, $g, $label, LaunchCheckStatus::Skip, $expected.' · '.$version->stripe_price_id, 'Overenie proti Stripe: `--stripe` / „Overiť v Stripe“.');

                continue;
            }

            try {
                $price = $stripe->price($version->stripe_price_id);
            } catch (Throwable $e) {
                $checks[] = new LaunchCheck($key, $g, $label, LaunchCheckStatus::Fail, 'Stripe API: '.mb_substr($e->getMessage(), 0, 160));

                continue;
            }

            $expectedInterval = $version instanceof PlanVersion ? ($version->interval === PlanInterval::Year ? 'year' : 'month') : null;
            $problems = [];
            if ($price === null) {
                $problems[] = 'cena '.$version->stripe_price_id.' v Stripe neexistuje (iný režim alebo účet?)';
            } else {
                if (! $price['active']) {
                    $problems[] = 'cena je v Stripe neaktívna';
                }
                if ($price['unit_amount'] !== $version->final_price_cents) {
                    $problems[] = 'Stripe účtuje '.($price['unit_amount'] !== null ? Catalog::formatCents($price['unit_amount'], $price['currency']) : '?').', katalóg '.$expected;
                }
                if (strtolower($price['currency']) !== strtolower($version->currency)) {
                    $problems[] = 'mena '.$price['currency'].' ≠ '.$version->currency;
                }
                if ($price['interval'] !== $expectedInterval) {
                    $problems[] = 'interval '.($price['interval'] ?? 'jednorazovo').' ≠ '.($expectedInterval ?? 'jednorazovo');
                }
                if ($price['livemode'] !== $live) {
                    $problems[] = 'cena je '.($price['livemode'] ? 'live' : 'test').', kľúče '.($live ? 'live' : 'test');
                }
            }

            $checks[] = new LaunchCheck($key, $g, $label, $problems === [] ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
                $problems === [] ? $expected.' · '.$version->stripe_price_id.' sedí' : implode('; ', $problems),
                $problems === [] ? null : 'Cena v Stripe musí presne zodpovedať snapshotu katalógu; oprav v Stripe alebo vytvor novú verziu katalógu.');
        }

        return $checks;
    }

    /** @return list<LaunchCheck> */
    private function legal(): array
    {
        $g = self::GROUP_LEGAL;
        $checks = [];

        $blockers = $this->checkout->legalBlockers();
        $checks[] = new LaunchCheck('legal.checkout', $g, 'Prevádzkovateľ a publikované dokumenty', $blockers === [] ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            $blockers === [] ? 'identita vyplnená, VOP / súkromie / odstúpenie publikované' : implode(' ', $blockers),
            $blockers === [] ? null : 'Dopĺňa sa v /admin/legal; bez toho je platený checkout zablokovaný (akceptačný test 20).');

        $cookies = $this->documents->current(LegalDocumentType::Cookies);
        $checks[] = new LaunchCheck('legal.cookies', $g, 'Stránka o cookies', $cookies !== null && ! $cookies->hasPlaceholders() ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $cookies === null ? 'nie je publikovaná' : ($cookies->hasPlaceholders() ? 'v'.$cookies->version.' obsahuje nevyplnené údaje' : 'v'.$cookies->version),
            $cookies !== null && ! $cookies->hasPlaceholders() ? null : 'Lišta odkazuje na /cookies; publikuj verziu bez placeholderov.');

        return $checks;
    }

    /** @return list<LaunchCheck> */
    private function admin(): array
    {
        $g = self::GROUP_ADMIN;
        $checks = [];

        $admins = User::query()->where('is_platform_admin', true)->get(['id', 'two_factor_confirmed_at']);
        $withMfa = $admins->whereNotNull('two_factor_confirmed_at')->count();
        $checks[] = match (true) {
            $admins->isEmpty() => new LaunchCheck('admin.account', $g, 'Administrátor platformy', LaunchCheckStatus::Fail, 'žiadny účet s rolou', 'php artisan app:grant-platform-admin <email>'),
            $withMfa === 0 => new LaunchCheck('admin.account', $g, 'Administrátor platformy', LaunchCheckStatus::Fail, $admins->count().' bez potvrdeného 2FA', 'Administrátor si musí zapnúť dvojfaktorové overenie v Nastavenia → Zabezpečenie.'),
            default => new LaunchCheck('admin.account', $g, 'Administrátor platformy', LaunchCheckStatus::Ok, $withMfa.' s 2FA z '.$admins->count()),
        };

        $checks[] = $this->productionOnly('admin.two_factor', $g, 'MFA pre /admin vyžadované', (bool) config('admin.require_two_factor'),
            'ADMIN_REQUIRE_TWO_FACTOR='.(config('admin.require_two_factor') ? 'true' : 'false'), 'Zadanie vyžaduje MFA pre administráciu.');

        $bootstrap = (string) config('admin.bootstrap_password');
        $checks[] = new LaunchCheck('admin.bootstrap_password', $g, 'Počiatočné heslo administrátora', $bootstrap === '' ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $bootstrap === '' ? 'ADMIN_INITIAL_PASSWORD nie je nastavené' : 'ADMIN_INITIAL_PASSWORD je v prostredí', $bootstrap === '' ? null : 'Po prvom prihlásení heslo zmeň a premennú odstráň.');

        return $checks;
    }

    /** @return list<LaunchCheck> */
    private function ai(): array
    {
        $g = self::GROUP_AI;
        $checks = [];

        $textOk = $this->ai->textConfigured();
        $imageOk = $this->ai->imageConfigured();
        $checks[] = new LaunchCheck('ai.keys', $g, 'AI poskytovateľ nakonfigurovaný', $textOk && $imageOk ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            'text: '.$this->aiSettings->textProvider().($textOk ? ' ✓' : ' bez kľúča').' · obrázky: '.$this->aiSettings->imageProvider().($imageOk ? ' ✓' : ' bez kľúča'),
            $textOk && $imageOk ? null : 'Plus sľubuje AI; bez kľúča (OPENAI_API_KEY) sa predplatné nesmie predávať.');

        $checks[] = new LaunchCheck('ai.enabled', $g, 'AI zapnuté (kill switch)', $this->aiSettings->enabled() ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $this->aiSettings->enabled() ? 'zapnuté' : 'vypnuté', $this->aiSettings->enabled() ? null : 'Pred zapnutím platieb AI zapni v /admin/ai/settings.');

        $textModel = $this->aiSettings->textModel();
        $imageModel = $this->aiSettings->imageModel();
        $textRate = $textModel ? $this->costs->rateFor($this->aiSettings->textProvider(), $textModel, AiCostRate::MODALITY_TEXT, null, null, now()) : null;
        $imageRate = $imageModel ? $this->costs->rateFor($this->aiSettings->imageProvider(), $imageModel, AiCostRate::MODALITY_IMAGE, $this->aiSettings->imageQuality(), $this->aiSettings->imagePixelSize(), now()) : null;
        $checks[] = new LaunchCheck('ai.models', $g, 'Modely a cenník AI', $textRate !== null && $imageRate !== null ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            'text: '.($textModel ?? 'predvolený').($textRate ? ' · sadzba ✓' : ' · bez sadzby').' · obrázky: '.($imageModel ?? 'predvolený').' '.$this->aiSettings->imageQuality().' '.$this->aiSettings->imagePixelSize().($imageRate ? ' · sadzba ✓' : ' · bez sadzby'),
            $textRate !== null && $imageRate !== null ? null : 'Nastav RECIPES_AI_TEXT_MODEL / RECIPES_AI_IMAGE_MODEL (gpt-6-luna, gpt-image-2) a nahraj cenník (AiCostRateSeeder), inak sú náklady neocenené.');

        $checks[] = $this->measurementCheck();

        $budget = $this->aiSettings->monthlyBudgetMicroUsd();
        $checks[] = new LaunchCheck('ai.budget', $g, 'Mesačný AI rozpočet (alarm)', $budget !== null ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $budget !== null ? 'nastavený' : 'nenastavený', $budget !== null ? null : 'Alarm na prehľade; nastav RECIPES_AI_MONTHLY_BUDGET_USD alebo v AI nastaveniach.');

        return $checks;
    }

    private function measurementCheck(): LaunchCheck
    {
        $g = self::GROUP_AI;
        $m = $this->measurement();
        if ($m === null) {
            return new LaunchCheck('ai.measurement', $g, 'Meranie 30 + 30 AI úloh', LaunchCheckStatus::Fail, 'zatiaľ nemerané', 'php artisan app:ai-measure <domácnosť> --yes na reálnom kľúči; výsledok sa uloží sem.');
        }

        $text = (int) ($m['kinds']['text']['succeeded'] ?? 0);
        $image = (int) ($m['kinds']['image']['succeeded'] ?? 0);
        $enough = $text >= self::MEASUREMENT_MINIMUM && $image >= self::MEASUREMENT_MINIMUM;
        $current = ($m['kinds']['text']['model'] ?? null) === $this->aiSettings->textModel() && ($m['kinds']['image']['model'] ?? null) === $this->aiSettings->imageModel();

        return new LaunchCheck('ai.measurement', $g, 'Meranie 30 + 30 AI úloh', $enough && $current ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $text.' textov, '.$image.' obrázkov · '.($m['at'] ?? '?').($current ? '' : ' · iný model než aktuálne nastavenie'),
            $enough && $current ? null : 'Zopakuj meranie s aktuálnym modelom a aspoň '.self::MEASUREMENT_MINIMUM.' úlohami každého druhu.');
    }

    /** @return array<string, mixed>|null the last stored measurement summary */
    public function measurement(): ?array
    {
        $value = $this->settings->get(self::MEASUREMENT_KEY);

        return is_array($value) ? $value : null;
    }

    /** @return list<LaunchCheck> */
    private function signoffs(): array
    {
        $checks = [];
        foreach ($this->signoffs->all() as $key => $confirmation) {
            $item = LaunchSignoffs::ITEMS[$key];
            $checks[] = new LaunchCheck('signoff.'.$key, self::GROUP_SIGNOFFS, $item['label'],
                $confirmation !== null ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
                $confirmation !== null ? 'potvrdené '.CarbonImmutable::parse($confirmation['at'])->format('j. n. Y').': '.$confirmation['note'] : 'nepotvrdené',
                $confirmation !== null ? null : $item['hint']);
        }

        return $checks;
    }

    /** @param list<LaunchCheck> $checks */
    private function switch(array $checks): LaunchCheck
    {
        $on = $this->checkout->switchedOn();
        $fails = count(array_filter($checks, fn (LaunchCheck $c) => $c->isFail()));

        return match (true) {
            $on && $fails > 0 => new LaunchCheck('launch.switch', self::GROUP_SWITCH, 'Prepínač platieb', LaunchCheckStatus::Fail, 'platby sú ZAPNUTÉ, hoci checklist má '.$fails.' blokujúcich položiek',
                'Vypni RECIPES_CHECKOUT_ENABLED alebo dorieš položky vyššie; predávať s nesplneným checklistom sa nesmie.'),
            $on => new LaunchCheck('launch.switch', self::GROUP_SWITCH, 'Prepínač platieb', LaunchCheckStatus::Ok, 'platby sú zapnuté a checklist je bez blokujúcich položiek'),
            $fails > 0 => new LaunchCheck('launch.switch', self::GROUP_SWITCH, 'Prepínač platieb', LaunchCheckStatus::Warn, 'platby vypnuté · '.$fails.' blokujúcich položiek', 'Zapnutie (RECIPES_CHECKOUT_ENABLED=true) až po vyriešení všetkých blokujúcich položiek, samostatným nasadením.'),
            default => new LaunchCheck('launch.switch', self::GROUP_SWITCH, 'Prepínač platieb', LaunchCheckStatus::Warn, 'checklist splnený, platby ešte vypnuté', 'Zapni RECIPES_CHECKOUT_ENABLED=true samostatným, kontrolovaným nasadením a over prvý nákup v živom režime.'),
        };
    }

    /** A hard requirement in production, a heads-up elsewhere. */
    private function productionOnly(string $key, string $group, string $label, bool $ok, ?string $detail, ?string $hint): LaunchCheck
    {
        $status = $ok ? LaunchCheckStatus::Ok : ($this->isProduction() ? LaunchCheckStatus::Fail : LaunchCheckStatus::Warn);

        return new LaunchCheck($key, $group, $label, $status, $detail, $ok ? null : $hint);
    }

    /** "test" / "live" for a Stripe key with the given prefix (sk_ / pk_), null when unrecognised. */
    private function keyMode(string $key, string $prefix): ?string
    {
        return match (true) {
            str_starts_with($key, $prefix.'live_') => 'live',
            str_starts_with($key, $prefix.'test_') => 'test',
            default => null,
        };
    }

    private function safeCount(callable $count): int
    {
        try {
            return (int) $count();
        } catch (Throwable) {
            return 0;
        }
    }
}
