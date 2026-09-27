<?php

namespace App\Services\Launch;

use App\Enums\LaunchCheckStatus;
use App\Enums\LegalDocumentType;
use App\Enums\PlanInterval;
use App\Enums\UsageKind;
use App\Models\AddonVersion;
use App\Models\AiCostRate;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiCostCalculator;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ImageProfile;
use App\Services\Billing\Catalog;
use App\Services\Billing\Gateway\StripeInspector;
use App\Services\Billing\StripeWebhookEvents;
use App\Services\Food\UsdaFoodDataCentral;
use App\Services\Legal\CheckoutReadiness;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
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

    /** Set by app:meal-analysis-cleanup (v2.1 stage 11); the checklist wants it running daily before photos are sold. */
    public const MEAL_CLEANUP_KEY = 'ops.meal_analysis_cleanup_last_run_at';

    /** Photo analyses the addendum asks to measure on the operator's own fixtures before a price promise. */
    public const MEAL_MEASUREMENT_MINIMUM = 10;

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

        $checks[] = new LaunchCheck('env.production', $g, __('Produkčné prostredie'),
            $this->isProduction() ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            'APP_ENV='.$env, $this->isProduction() ? null : __('Toto nie je produkcia; prísne kontroly sú tu iba upozornením.'));

        $checks[] = $this->productionOnly('env.debug', $g, __('Ladiaci režim vypnutý'), ! config('app.debug'),
            'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'), __('V produkcii musí byť APP_DEBUG=false (inak unikajú detaily chýb).'));

        $url = (string) config('app.url');
        $checks[] = $this->productionOnly('env.url', $g, __('Verejná adresa cez HTTPS'), str_starts_with($url, 'https://'),
            'APP_URL='.$url, __('Webhook aj Checkout návratové URL sa odvádzajú z APP_URL; v produkcii https://moje-recepty.sk.'));

        $queue = (string) config('queue.default');
        $checks[] = $this->productionOnly('env.queue', $g, __('Fronta beží mimo requestu'), $queue !== 'sync',
            'QUEUE_CONNECTION='.$queue, __('Stripe udalosti a e-maily sú fronta; spusti worker (php artisan queue:work) a monitoruj ho.'));

        $mailer = (string) config('mail.default');
        $checks[] = $this->productionOnly('env.mail', $g, __('Odchádzajúca pošta nastavená'), ! in_array($mailer, ['log', 'array', 'null'], true),
            __('MAIL_MAILER=:mailer · odosielateľ :from', ['mailer' => $mailer, 'from' => (string) config('mail.from.address')]),
            __('Potvrdenia objednávok, odstúpenia a exporty odchádzajú e-mailom; nastav produkčný SMTP a odosielateľa v doméne.'));

        $lastRun = $this->settings->get(self::RECONCILE_KEY);
        $lastRunAt = is_string($lastRun) ? CarbonImmutable::parse($lastRun) : null;
        $checks[] = match (true) {
            $lastRunAt === null => new LaunchCheck('env.scheduler', $g, __('Scheduler (app:billing-reconcile)'), LaunchCheckStatus::Warn,
                __('zatiaľ nebežal'), __('Nastav cron `php artisan schedule:run` každú minútu; denná úloha otvára mesačné granty a opakuje zlyhané udalosti.')),
            $lastRunAt->lt(CarbonImmutable::now()->subHours(36)) => $this->productionOnly('env.scheduler', $g, __('Scheduler (app:billing-reconcile)'), false,
                __('posledný beh :at UTC', ['at' => $lastRunAt->toDateTimeString()]), __('Denná úloha nebežala viac než 36 h – skontroluj cron.')),
            default => new LaunchCheck('env.scheduler', $g, __('Scheduler (app:billing-reconcile)'), LaunchCheckStatus::Ok, __('posledný beh :at UTC', ['at' => $lastRunAt->toDateTimeString()])),
        };

        $cleanup = $this->settings->get(self::MEAL_CLEANUP_KEY);
        $cleanupAt = is_string($cleanup) ? CarbonImmutable::parse($cleanup) : null;
        $sellsMeals = $this->sellsMealAnalyses();
        $checks[] = match (true) {
            $cleanupAt === null => new LaunchCheck('env.meal_cleanup', $g, __('Retencia fotiek (app:meal-analysis-cleanup)'), $sellsMeals && $this->isProduction() ? LaunchCheckStatus::Fail : LaunchCheckStatus::Warn,
                __('zatiaľ nebežal'), __('Denná úloha maže pracovné fotky po TTL a nedokončené návrhy; beží zo scheduleru (schedule:run). Bez nej sľub retencie v informáciách o súkromí neplatí.')),
            $cleanupAt->lt(CarbonImmutable::now()->subHours(36)) => $this->productionOnly('env.meal_cleanup', $g, __('Retencia fotiek (app:meal-analysis-cleanup)'), false,
                __('posledný beh :at UTC', ['at' => $cleanupAt->toDateTimeString()]), __('Cleanup nebežal viac než 36 h – skontroluj cron.')),
            default => new LaunchCheck('env.meal_cleanup', $g, __('Retencia fotiek (app:meal-analysis-cleanup)'), LaunchCheckStatus::Ok, __('posledný beh :at UTC', ['at' => $cleanupAt->toDateTimeString()])),
        };

        $failed = $this->safeCount(fn () => (int) DB::table('failed_jobs')->count());
        $checks[] = new LaunchCheck('env.failed_jobs', $g, __('Zlyhané úlohy fronty'), $failed === 0 ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            __(':count v failed_jobs', ['count' => $failed]), $failed === 0 ? null : __('Pozri `php artisan queue:failed`; pred launchom vyčisti alebo zopakuj.'));

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
            $checks[] = new LaunchCheck('stripe.keys', $g, __('Stripe kľúče'), LaunchCheckStatus::Fail, __('STRIPE_KEY / STRIPE_SECRET chýba'), __('Doplň kľúče z Stripe dashboardu (Developers → API keys).'));
        } elseif ($secretMode === null || $publicMode === null || $secretMode !== $publicMode) {
            $checks[] = new LaunchCheck('stripe.keys', $g, __('Stripe kľúče'), LaunchCheckStatus::Fail, __('kľúče nie sú z rovnakého režimu (test/live)'), __('STRIPE_KEY a STRIPE_SECRET musia byť oba test alebo oba live.'));
        } elseif ($secretMode === 'live' && ! $this->isProduction()) {
            $checks[] = new LaunchCheck('stripe.keys', $g, __('Stripe kľúče'), LaunchCheckStatus::Fail, __('živé kľúče mimo produkcie'), __('Mimo produkcie používaj iba sandbox (sk_test_…); živý kľúč tu znamená skutočné platby.'));
        } else {
            $checks[] = $this->productionOnly('stripe.keys', $g, __('Stripe kľúče'), $secretMode === 'live', __('režim :mode', ['mode' => $secretMode]),
                __('Produkcia potrebuje živé kľúče (sk_live_/pk_live_); testovací kľúč nepredá nič.'));
        }

        $webhookSecret = (string) config('cashier.webhook.secret');
        $checks[] = new LaunchCheck('stripe.webhook_secret', $g, __('Webhook signing secret'), $webhookSecret !== '' ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            $webhookSecret !== '' ? __('STRIPE_WEBHOOK_SECRET nastavený') : __('STRIPE_WEBHOOK_SECRET chýba'),
            $webhookSecret !== '' ? null : __('Bez podpisu sa každá udalosť odmietne (403) a žiadna platba neaktivuje Plus.'));

        if ($stripe === null) {
            $checks[] = new LaunchCheck('stripe.webhook_endpoint', $g, __('Webhook endpoint v Stripe'), LaunchCheckStatus::Skip, __('neoverené proti Stripe'), __('Spusti `php artisan app:launch-check --stripe` alebo „Overiť v Stripe“.'));
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
            return new LaunchCheck('stripe.webhook_endpoint', $g, __('Webhook endpoint v Stripe'), LaunchCheckStatus::Fail, 'Stripe API: '.mb_substr($e->getMessage(), 0, 160), __('Skontroluj STRIPE_SECRET a sieťové spojenie.'));
        }

        $matching = array_values(array_filter($endpoints, fn (array $e) => rtrim($e['url'], '/') === rtrim($url, '/')));
        if ($matching === []) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, __('Webhook endpoint v Stripe'), LaunchCheckStatus::Fail,
                __('žiadny endpoint pre :url (:count iných)', ['url' => $url, 'count' => count($endpoints)]), __('Vytvor ho: `php artisan cashier:webhook` (registruje presný zoznam udalostí) a ulož jeho secret do STRIPE_WEBHOOK_SECRET.'));
        }

        $enabled = array_values(array_filter($matching, fn (array $e) => $e['status'] === 'enabled'));
        if ($enabled === []) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, __('Webhook endpoint v Stripe'), LaunchCheckStatus::Fail, __('endpoint existuje, ale je vypnutý'), __('Zapni ho v Stripe dashboarde (Developers → Webhooks).'));
        }

        $missing = StripeWebhookEvents::required();
        foreach ($enabled as $endpoint) {
            $missing = array_values(array_intersect($missing, StripeWebhookEvents::missingFrom($endpoint['enabled_events'])));
        }

        if ($missing !== []) {
            return new LaunchCheck('stripe.webhook_endpoint', $g, __('Webhook endpoint v Stripe'), LaunchCheckStatus::Fail,
                __('chýbajú udalosti: :events', ['events' => implode(', ', $missing)]), __('Doplň udalosti na endpointe alebo ho vytvor znova cez `php artisan cashier:webhook`.'));
        }

        $live = (bool) $enabled[0]['livemode'];
        $modeMatches = $live === ($this->keyMode((string) config('cashier.secret'), 'sk_') === 'live');

        return new LaunchCheck('stripe.webhook_endpoint', $g, __('Webhook endpoint v Stripe'), $modeMatches ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            __(':id · :mode · :count udalostí', ['id' => $enabled[0]['id'], 'mode' => $live ? 'live' : 'test', 'count' => count($enabled[0]['enabled_events'])]),
            $modeMatches ? null : __('Endpoint je v inom režime než kľúče.'));
    }

    /** @return list<LaunchCheck> */
    private function catalog(?StripeInspector $stripe): array
    {
        $g = self::GROUP_CATALOG;
        $checks = [];
        $plans = $this->catalog->plans();
        $addons = $this->catalog->addons();

        if ($plans->isEmpty()) {
            $checks[] = new LaunchCheck('catalog.active', $g, __('Aktívny katalóg'), LaunchCheckStatus::Fail, __('žiadny aktívny plán'), __('Spusti `php artisan db:seed --class=CatalogSeeder` alebo aktivuj verziu v /admin/catalog.'));
        } else {
            $unsellable = collect([...$plans->all(), ...$addons->all()])->filter(fn ($v) => ! $v->stripe_price_id)->map(fn ($v) => $v->code)->values()->all();
            $checks[] = new LaunchCheck('catalog.active', $g, __('Aktívny katalóg'), $unsellable === [] ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
                $unsellable !== [] ? __(':plans plány, :addons balíky · bez Stripe price ID: :codes', ['plans' => $plans->count(), 'addons' => $addons->count(), 'codes' => implode(', ', $unsellable)]) : __(':plans plány, :addons balíky', ['plans' => $plans->count(), 'addons' => $addons->count()]),
                $unsellable === [] ? null : __('Ponuka bez Stripe price ID je nepredajná; doplň ID novou verziou katalógu alebo CatalogSeederom.'));
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
            $mismatch !== [] => new LaunchCheck('catalog.env_match', $g, __('Katalóg ↔ STRIPE_PRICE_*'), LaunchCheckStatus::Fail, __('iné ID než .env: :codes', ['codes' => implode(', ', $mismatch)]),
                __('Katalóg predáva ID uložené v DB. Po zmene .env vytvor novú verziu s novým ID (alebo seeder na prázdne ID); inak sa predáva staré ID.')),
            $unsetEnv !== [] => new LaunchCheck('catalog.env_match', $g, __('Katalóg ↔ STRIPE_PRICE_*'), LaunchCheckStatus::Warn, __('v .env chýba: :codes', ['codes' => implode(', ', $unsetEnv)]), __('Nie je chyba, ak sú ID iba v katalógu; udrž .env a katalóg v zhode pre ďalšie nasadenia.')),
            default => new LaunchCheck('catalog.env_match', $g, __('Katalóg ↔ STRIPE_PRICE_*'), LaunchCheckStatus::Ok, __('ID v katalógu a v .env sa zhodujú')),
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
            $label = __('Stripe cena: :name', ['name' => $version->name]);
            $expected = Catalog::formatCents($version->final_price_cents, $version->currency);
            if (! $version->stripe_price_id) {
                continue;
            }
            if ($stripe === null) {
                $checks[] = new LaunchCheck($key, $g, $label, LaunchCheckStatus::Skip, $expected.' · '.$version->stripe_price_id, __('Overenie proti Stripe: `--stripe` / „Overiť v Stripe“.'));

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
                $problems[] = __('cena :id v Stripe neexistuje (iný režim alebo účet?)', ['id' => $version->stripe_price_id]);
            } else {
                if (! $price['active']) {
                    $problems[] = __('cena je v Stripe neaktívna');
                }
                if ($price['unit_amount'] !== $version->final_price_cents) {
                    $problems[] = __('Stripe účtuje :stripe, katalóg :catalog', ['stripe' => $price['unit_amount'] !== null ? Catalog::formatCents($price['unit_amount'], $price['currency']) : '?', 'catalog' => $expected]);
                }
                if (strtolower($price['currency']) !== strtolower($version->currency)) {
                    $problems[] = __('mena :stripe ≠ :catalog', ['stripe' => $price['currency'], 'catalog' => $version->currency]);
                }
                if ($price['interval'] !== $expectedInterval) {
                    $problems[] = __('interval :stripe ≠ :catalog', ['stripe' => $price['interval'] ?? __('jednorazovo'), 'catalog' => $expectedInterval ?? __('jednorazovo')]);
                }
                if ($price['livemode'] !== $live) {
                    $problems[] = __('cena je :price, kľúče :keys', ['price' => $price['livemode'] ? 'live' : 'test', 'keys' => $live ? 'live' : 'test']);
                }
            }

            $checks[] = new LaunchCheck($key, $g, $label, $problems === [] ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
                $problems === [] ? __(':price · :id sedí', ['price' => $expected, 'id' => $version->stripe_price_id]) : implode('; ', $problems),
                $problems === [] ? null : __('Cena v Stripe musí presne zodpovedať snapshotu katalógu; oprav v Stripe alebo vytvor novú verziu katalógu.'));
        }

        return $checks;
    }

    /** @return list<LaunchCheck> */
    private function legal(): array
    {
        $g = self::GROUP_LEGAL;
        $checks = [];

        $blockers = $this->checkout->legalBlockers();
        $checks[] = new LaunchCheck('legal.checkout', $g, __('Prevádzkovateľ a publikované dokumenty'), $blockers === [] ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            $blockers === [] ? __('identita vyplnená, VOP / súkromie / odstúpenie publikované') : implode(' ', $blockers),
            $blockers === [] ? null : __('Dopĺňa sa v /admin/legal; bez toho je platený checkout zablokovaný (akceptačný test 20).'));

        // v2.1 addendum chapter 11: the public name and descriptor customers will see on statements, kept with the identity.
        $operator = app(OperatorIdentity::class);
        $publicName = $operator->get('public_business_name');
        $descriptor = $operator->get('statement_descriptor');
        $descriptorOk = $descriptor !== '' && mb_strlen($descriptor) >= 5 && mb_strlen($descriptor) <= 22;
        $checks[] = new LaunchCheck('legal.stripe_identity', $g, __('Verejné meno a statement descriptor (Stripe)'),
            $publicName !== '' && $descriptorOk ? LaunchCheckStatus::Ok : ($this->isProduction() ? LaunchCheckStatus::Fail : LaunchCheckStatus::Warn),
            $publicName === '' && $descriptor === '' ? __('nevyplnené') : __('verejné meno: :name · descriptor: :descriptor', ['name' => $publicName !== '' ? $publicName : '–', 'descriptor' => $descriptor !== '' ? $descriptor.($descriptorOk ? '' : ' ('.__('5–22 znakov').')') : '–']),
            $publicName !== '' && $descriptorOk ? null : __('Doplň v /admin/legal (identita): verejné meno „Moje recepty“ a descriptor MOJE-RECEPTY.SK, overený v Stripe; potvrdenie účtu je ručná položka.'));

        $cookies = $this->documents->current(LegalDocumentType::Cookies);
        $checks[] = new LaunchCheck('legal.cookies', $g, __('Stránka o cookies'), $cookies !== null && ! $cookies->hasPlaceholders() ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $cookies === null ? __('nie je publikovaná') : ($cookies->hasPlaceholders() ? __('v:version obsahuje nevyplnené údaje', ['version' => $cookies->version]) : 'v'.$cookies->version),
            $cookies !== null && ! $cookies->hasPlaceholders() ? null : __('Lišta odkazuje na /cookies; publikuj verziu bez placeholderov.'));

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
            $admins->isEmpty() => new LaunchCheck('admin.account', $g, __('Administrátor platformy'), LaunchCheckStatus::Fail, __('žiadny účet s rolou'), 'php artisan app:grant-platform-admin <email>'),
            $withMfa === 0 => new LaunchCheck('admin.account', $g, __('Administrátor platformy'), LaunchCheckStatus::Fail, __(':count bez potvrdeného 2FA', ['count' => $admins->count()]), __('Administrátor si musí zapnúť dvojfaktorové overenie v Nastavenia → Zabezpečenie.')),
            default => new LaunchCheck('admin.account', $g, __('Administrátor platformy'), LaunchCheckStatus::Ok, __(':with s 2FA z :total', ['with' => $withMfa, 'total' => $admins->count()])),
        };

        $checks[] = $this->productionOnly('admin.two_factor', $g, __('MFA pre /admin vyžadované'), (bool) config('admin.require_two_factor'),
            'ADMIN_REQUIRE_TWO_FACTOR='.(config('admin.require_two_factor') ? 'true' : 'false'), __('Zadanie vyžaduje MFA pre administráciu.'));

        $bootstrap = (string) config('admin.bootstrap_password');
        $checks[] = new LaunchCheck('admin.bootstrap_password', $g, __('Počiatočné heslo administrátora'), $bootstrap === '' ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $bootstrap === '' ? __('ADMIN_INITIAL_PASSWORD nie je nastavené') : __('ADMIN_INITIAL_PASSWORD je v prostredí'), $bootstrap === '' ? null : __('Po prvom prihlásení heslo zmeň a premennú odstráň.'));

        return $checks;
    }

    /** @return list<LaunchCheck> */
    private function ai(): array
    {
        $g = self::GROUP_AI;
        $checks = [];

        $textOk = $this->ai->textConfigured();
        $imageOk = $this->ai->imageConfigured();
        $checks[] = new LaunchCheck('ai.keys', $g, __('AI poskytovateľ nakonfigurovaný'), $textOk && $imageOk ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            __('text: :text · obrázky: :image', ['text' => $this->aiSettings->textProvider().($textOk ? ' ✓' : ' '.__('bez kľúča')), 'image' => $this->aiSettings->imageProvider().($imageOk ? ' ✓' : ' '.__('bez kľúča'))]),
            $textOk && $imageOk ? null : __('Plus sľubuje AI; bez kľúča (OPENAI_API_KEY) sa predplatné nesmie predávať.'));

        $checks[] = new LaunchCheck('ai.enabled', $g, __('AI zapnuté (kill switch)'), $this->aiSettings->enabled() ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $this->aiSettings->enabled() ? __('zapnuté') : __('vypnuté'), $this->aiSettings->enabled() ? null : __('Pred zapnutím platieb AI zapni v /admin/ai/settings.'));

        $textModel = $this->aiSettings->textModel();
        $imageModel = $this->aiSettings->imageModel();
        $textRate = $textModel ? $this->costs->rateFor($this->aiSettings->textProvider(), $textModel, AiCostRate::MODALITY_TEXT, null, null, now()) : null;
        $imageRate = $imageModel ? $this->costs->rateFor($this->aiSettings->imageProvider(), $imageModel, AiCostRate::MODALITY_IMAGE, $this->aiSettings->imageQuality(), $this->aiSettings->imagePixelSize(), now()) : null;
        $checks[] = new LaunchCheck('ai.models', $g, __('Modely a cenník AI'), $textRate !== null && $imageRate !== null ? LaunchCheckStatus::Ok : LaunchCheckStatus::Fail,
            __('text: :text · obrázky: :image', ['text' => ($textModel ?? __('predvolený')).($textRate ? ' · '.__('sadzba ✓') : ' · '.__('bez sadzby')), 'image' => ($imageModel ?? __('predvolený')).' '.$this->aiSettings->defaultImageProfile()->value.' ('.$this->aiSettings->imageQuality().' '.$this->aiSettings->imagePixelSize().')'.($imageRate ? ' · '.__('sadzba ✓') : ' · '.__('bez sadzby'))]),
            $textRate !== null && $imageRate !== null ? null : __('Nastav RECIPES_AI_TEXT_MODEL / RECIPES_AI_IMAGE_MODEL (gpt-6-luna, gpt-image-2) a nahraj cenník (AiCostRateSeeder), inak sú náklady neocenené.'));

        $checks[] = $this->profileRatesCheck($imageModel);
        $checks[] = $this->measurementCheck();
        $checks[] = $this->mealMeasurementCheck();
        $checks[] = $this->usdaCheck();

        $budget = $this->aiSettings->monthlyBudgetMicroUsd();
        $checks[] = new LaunchCheck('ai.budget', $g, __('Mesačný AI rozpočet (alarm)'), $budget !== null ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $budget !== null ? __('nastavený') : __('nenastavený'), $budget !== null ? null : __('Alarm na prehľade; nastav RECIPES_AI_MONTHLY_BUDGET_USD alebo v AI nastaveniach.'));

        return $checks;
    }

    private function measurementCheck(): LaunchCheck
    {
        $g = self::GROUP_AI;
        $m = $this->measurement();
        if ($m === null) {
            return new LaunchCheck('ai.measurement', $g, __('Meranie 30 + 30 AI úloh'), LaunchCheckStatus::Fail, __('zatiaľ nemerané'), __('php artisan app:ai-measure <domácnosť> --yes na reálnom kľúči; výsledok sa uloží sem.'));
        }

        $text = (int) ($m['kinds']['text']['succeeded'] ?? 0);
        $image = (int) ($m['kinds']['image']['succeeded'] ?? 0);
        $enough = $text >= self::MEASUREMENT_MINIMUM && $image >= self::MEASUREMENT_MINIMUM;
        $current = ($m['kinds']['text']['model'] ?? null) === $this->aiSettings->textModel() && ($m['kinds']['image']['model'] ?? null) === $this->aiSettings->imageModel();

        return new LaunchCheck('ai.measurement', $g, __('Meranie 30 + 30 AI úloh'), $enough && $current ? LaunchCheckStatus::Ok : LaunchCheckStatus::Warn,
            $current ? __(':text textov, :image obrázkov · :at', ['text' => $text, 'image' => $image, 'at' => $m['at'] ?? '?']) : __(':text textov, :image obrázkov · :at · iný model než aktuálne nastavenie', ['text' => $text, 'image' => $image, 'at' => $m['at'] ?? '?']),
            $enough && $current ? null : __('Zopakuj meranie s aktuálnym modelom a aspoň :minimum úlohami každého druhu.', ['minimum' => self::MEASUREMENT_MINIMUM]));
    }

    /**
     * v2.1 stage 13: a cost rate for every selectable image profile (Economy = low, Standard = medium) of the current
     * image model. A profile the catalogue sells without a rate blocks; an unsold one only warns.
     */
    private function profileRatesCheck(?string $imageModel): LaunchCheck
    {
        $g = self::GROUP_AI;
        $missing = [];
        foreach (ImageProfile::selectable() as $profile) {
            $rate = $imageModel ? $this->costs->rateFor($this->aiSettings->imageProvider(), $imageModel, AiCostRate::MODALITY_IMAGE, $profile->quality(), $profile->pixelSize(), now()) : null;
            if ($rate === null) {
                $missing[] = $profile;
            }
        }
        $soldMissing = array_filter($missing, fn (ImageProfile $p) => $this->sellsImageKind($p->usageKind()));
        $status = match (true) {
            $missing === [] => LaunchCheckStatus::Ok,
            $soldMissing !== [] => LaunchCheckStatus::Fail,
            default => LaunchCheckStatus::Warn,
        };

        return new LaunchCheck('ai.profile_rates', $g, __('Sadzby pre profily obrázkov'), $status,
            $missing === [] ? __('Economy (low) aj Standard (medium) majú sadzbu') : __('bez sadzby: :profiles', ['profiles' => implode(', ', array_map(fn (ImageProfile $p) => $p->label().' ('.$p->quality().')', $missing))]),
            $missing === [] ? null : __('Nahraj cenník (AiCostRateSeeder) alebo doplň sadzbu v /admin/ai/rates; ponuka s profilom bez sadzby by mala neocenené náklady.'));
    }

    /**
     * v2.1 stage 13: the measurement of photo analyses on the operator's own fixtures. Required once the catalogue
     * sells analyses (a plan with included analyses or an active pack); otherwise a reminder.
     */
    private function mealMeasurementCheck(): LaunchCheck
    {
        $g = self::GROUP_AI;
        $m = $this->measurement();
        $meals = $m['kinds']['meal_analysis'] ?? null;
        $sells = $this->sellsMealAnalyses();
        $label = __('Meranie analýz jedla');

        if ($meals === null) {
            return new LaunchCheck('ai.meal_measurement', $g, $label, $sells ? LaunchCheckStatus::Fail : LaunchCheckStatus::Warn, __('zatiaľ nemerané'),
                __('php artisan app:ai-measure <domácnosť> --text=0 --images=0 --meal-analyses=:n --yes na vlastných fotkách v tests/fixtures/meals/; bez merania sa analýzy nesmú ponúkať.', ['n' => self::MEAL_MEASUREMENT_MINIMUM]));
        }

        $delivered = (int) ($meals['delivered'] ?? $meals['succeeded'] ?? 0);
        $current = ($meals['model'] ?? null) === $this->aiSettings->textModel();
        $enough = $delivered >= self::MEAL_MEASUREMENT_MINIMUM;
        $detail = __(':delivered rozpoznaných z :count · medián :median · p95 :p95 · :at', [
            'delivered' => $delivered,
            'count' => (int) ($meals['analyses'] ?? $meals['jobs'] ?? 0),
            'median' => isset($meals['median_cost_micro']) ? number_format($meals['median_cost_micro'] / 1_000_000, 4, ',', ' ').' USD' : '–',
            'p95' => isset($meals['p95_cost_micro']) ? number_format($meals['p95_cost_micro'] / 1_000_000, 4, ',', ' ').' USD' : '–',
            'at' => $m['at'] ?? '?',
        ]).($current ? '' : ' · '.__('iný model než aktuálne nastavenie'));

        return new LaunchCheck('ai.meal_measurement', $g, $label, $enough && $current ? LaunchCheckStatus::Ok : ($sells ? LaunchCheckStatus::Fail : LaunchCheckStatus::Warn), $detail,
            $enough && $current ? null : __('Zopakuj meranie s aktuálnym modelom a aspoň :minimum rozpoznanými analýzami.', ['minimum' => self::MEAL_MEASUREMENT_MINIMUM]));
    }

    /** v2.1 stage 13: the food database key. The dictionary works without it; searching new foods does not. */
    private function usdaCheck(): LaunchCheck
    {
        $g = self::GROUP_AI;
        $configured = app(UsdaFoodDataCentral::class)->isConfigured();
        $sells = $this->sellsMealAnalyses();

        return new LaunchCheck('food.usda', $g, __('USDA FoodData Central kľúč'), $configured ? LaunchCheckStatus::Ok : ($sells && $this->isProduction() ? LaunchCheckStatus::Fail : LaunchCheckStatus::Warn),
            $configured ? __('USDA_FDC_API_KEY nastavený') : __('USDA_FDC_API_KEY chýba'),
            $configured ? null : __('Bez kľúča sa nové potraviny nehľadajú (slovník funguje); pred predajom analýz kľúč nastav a spusti app:food-sync.'));
    }

    /** Whether the active catalogue includes photo analyses (a plan with included uses or a pack). */
    public function sellsMealAnalyses(): bool
    {
        return $this->catalog->plans()->contains(fn (PlanVersion $p) => $p->meal_analysis_uses_per_period > 0)
            || $this->catalog->addons()->contains(fn (AddonVersion $a) => $a->unit_kind === UsageKind::MealAnalysis);
    }

    /** Whether the active catalogue grants or sells the given kind of image use. */
    public function sellsImageKind(UsageKind $kind): bool
    {
        return $this->catalog->plans()->contains(fn (PlanVersion $p) => $p->image_uses_per_period > 0 && $p->imageProfile()->usageKind() === $kind)
            || $this->catalog->addons()->contains(fn (AddonVersion $a) => $a->unit_kind === $kind);
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
            // The image comparison is optional for the v2 offer, but not once Economy images are on sale (stage 13).
            $optional = LaunchSignoffs::isOptional($key) && ! ($key === 'image_profile' && $this->sellsImageKind(UsageKind::ImageEconomy));
            $checks[] = new LaunchCheck('signoff.'.$key, self::GROUP_SIGNOFFS, __($item['label']),
                $confirmation !== null ? LaunchCheckStatus::Ok : ($optional ? LaunchCheckStatus::Warn : LaunchCheckStatus::Fail),
                $confirmation !== null ? __('potvrdené :date: :note', ['date' => CarbonImmutable::parse($confirmation['at'])->format('j. n. Y'), 'note' => $confirmation['note']]) : __('nepotvrdené'),
                $confirmation !== null ? null : __($item['hint']));
        }

        return $checks;
    }

    /** @param list<LaunchCheck> $checks */
    private function switch(array $checks): LaunchCheck
    {
        $on = $this->checkout->switchedOn();
        $fails = count(array_filter($checks, fn (LaunchCheck $c) => $c->isFail()));

        return match (true) {
            $on && $fails > 0 => new LaunchCheck('launch.switch', self::GROUP_SWITCH, __('Prepínač platieb'), LaunchCheckStatus::Fail, __('platby sú ZAPNUTÉ, hoci checklist má :count blokujúcich položiek', ['count' => $fails]),
                __('Vypni RECIPES_CHECKOUT_ENABLED alebo dorieš položky vyššie; predávať s nesplneným checklistom sa nesmie.')),
            $on => new LaunchCheck('launch.switch', self::GROUP_SWITCH, __('Prepínač platieb'), LaunchCheckStatus::Ok, __('platby sú zapnuté a checklist je bez blokujúcich položiek')),
            $fails > 0 => new LaunchCheck('launch.switch', self::GROUP_SWITCH, __('Prepínač platieb'), LaunchCheckStatus::Warn, __('platby vypnuté · :count blokujúcich položiek', ['count' => $fails]), __('Zapnutie (RECIPES_CHECKOUT_ENABLED=true) až po vyriešení všetkých blokujúcich položiek, samostatným nasadením.')),
            default => new LaunchCheck('launch.switch', self::GROUP_SWITCH, __('Prepínač platieb'), LaunchCheckStatus::Warn, __('checklist splnený, platby ešte vypnuté'), __('Zapni RECIPES_CHECKOUT_ENABLED=true samostatným, kontrolovaným nasadením a over prvý nákup v živom režime.')),
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
