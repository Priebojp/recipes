<?php

namespace App\Services\Consent;

use App\Enums\ConsentCategory;
use App\Enums\LegalDocumentType;
use App\Models\ConsentReceipt;
use App\Models\ConsentService;
use App\Models\User;
use App\Services\Legal\LegalDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Consent state of a visitor (specification chapter 12). The server decides what the client may load: optional
 * services are rendered only for categories the visitor granted under the *current* policy version. A new
 * service or purpose changes the version, so an old decision is not inherited. No decision = nothing optional.
 */
class ConsentPolicy
{
    public const COOKIE = 'mr_consent';

    /** Route name prefixes where optional analytics is never loaded (admin, legal, payment and privacy forms). */
    public const SUPPRESSED_ROUTES = [
        'admin.', 'legal.', 'checkout.review', 'checkout.plan', 'checkout.addon', 'consent.', 'privacy.', 'billing.', 'subscription.',
        'password.', 'two-factor.', 'register', 'login', 'security.', 'cashier.', 'profile.',
    ];

    /** @var Collection<int, ConsentService>|null */
    private ?Collection $services = null;

    public function __construct(private LegalDocuments $documents) {}

    /** @return Collection<int, ConsentService> */
    public function services(): Collection
    {
        return $this->services ??= ConsentService::query()->orderBy('category')->orderBy('name')->get();
    }

    /** @return Collection<int, ConsentService> */
    public function enabledServices(): Collection
    {
        return $this->services()->where('enabled', true)->values();
    }

    /** @return Collection<int, ConsentService> enabled services the visitor may switch on or off */
    public function optionalServices(): Collection
    {
        return $this->enabledServices()->filter(fn (ConsentService $s) => $s->isOptional())->values();
    }

    /**
     * Only categories that have at least one enabled service are offered – never an empty consent.
     *
     * @return list<ConsentCategory>
     */
    public function offeredCategories(): array
    {
        return array_values(array_filter(ConsentCategory::optional(), fn (ConsentCategory $c) => $this->optionalServices()->contains(fn (ConsentService $s) => $s->category === $c)));
    }

    public function bannerNeeded(Request $request): bool
    {
        return $this->offeredCategories() !== [] && $this->decision($request) === null && ! $this->suppressedFor($request);
    }

    /** Version of the set of purposes the visitor decides about. Changes when a service or its purpose changes. */
    public function version(): string
    {
        $parts = $this->optionalServices()->map(fn (ConsentService $s) => $s->key.':'.$s->category->value.':'.$s->consent_version)->sort()->values()->all();
        $cookiesDocument = $this->documents->current(LegalDocumentType::Cookies);
        $parts[] = 'doc:'.($cookiesDocument !== null ? $cookiesDocument->version : 0);

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * The visitor's decision under the current policy version, or null (no decision / outdated version).
     *
     * @return array{visitor: string, categories: array<string, bool>, at: string, version: string}|null
     */
    public function decision(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['v'] ?? null) !== $this->version() || ! is_array($data['c'] ?? null)) {
            return null;
        }

        $categories = [];
        foreach (ConsentCategory::optional() as $category) {
            $categories[$category->value] = (bool) ($data['c'][$category->value] ?? false);
        }

        return [
            'visitor' => (string) ($data['id'] ?? ''),
            'categories' => $categories,
            'at' => (string) ($data['at'] ?? ''),
            'version' => (string) $data['v'],
        ];
    }

    public function granted(Request $request, ConsentCategory $category): bool
    {
        if (! $category->isOptional()) {
            return true;
        }

        return (bool) ($this->decision($request)['categories'][$category->value] ?? false);
    }

    /** Pseudonymous visitor id carried in the cookie (kept across decisions of the same browser). */
    public function visitorId(Request $request): string
    {
        $raw = $request->cookie(self::COOKIE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $id = is_array($data) ? (string) ($data['id'] ?? '') : '';

        return Str::isUuid($id) ? $id : (string) Str::uuid();
    }

    /**
     * Store a decision: a receipt row and the cookie the client keeps. "withdraw" rejects every optional category.
     *
     * @param  array<string, bool>  $categories
     * @return array{cookie: Cookie, receipt: ConsentReceipt}
     */
    public function record(Request $request, string $action, array $categories, ?User $user = null): array
    {
        $offered = array_map(fn (ConsentCategory $c) => $c->value, $this->offeredCategories());
        $clean = [];
        foreach (ConsentCategory::optional() as $category) {
            $value = match ($action) {
                'accept_all' => true,
                'reject_all', 'withdraw' => false,
                default => (bool) ($categories[$category->value] ?? false),
            };
            // A category nobody offers can never be granted – a marketing flag without a service stays false.
            $clean[$category->value] = $value && in_array($category->value, $offered, true);
        }

        $visitor = $this->visitorId($request);
        $receipt = ConsentReceipt::create([
            'visitor_id' => $visitor,
            'user_id' => $user?->id,
            'policy_version' => $this->version(),
            'cookies_document_version_id' => $this->documents->current(LegalDocumentType::Cookies)?->id,
            'action' => in_array($action, ['accept_all', 'reject_all', 'custom', 'withdraw'], true) ? $action : 'custom',
            'categories' => $clean,
            'created_at' => now(),
        ]);

        $payload = json_encode(['v' => $this->version(), 'c' => $clean, 'id' => $visitor, 'at' => now()->toIso8601String()], JSON_UNESCAPED_SLASHES);
        $cookie = cookie(self::COOKIE, (string) $payload, (int) config('recipes.consent.lifetime_days', 180) * 24 * 60, null, null, null, true, false, 'Lax');

        return ['cookie' => $cookie, 'receipt' => $receipt];
    }

    /** Optional analytics never runs in the admin, on legal pages or on payment / privacy forms. */
    public function suppressedFor(Request $request): bool
    {
        $name = (string) ($request->route()?->getName() ?? '');
        if ($name === '') {
            return false;
        }
        foreach (self::SUPPRESSED_ROUTES as $prefix) {
            if ($name === rtrim($prefix, '.') || str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the page embeds for the client script. Loaders of optional services are included only for granted
     * categories, so a page rendered without consent carries no analytics code at all.
     *
     * @param  bool|null  $suppressed  override of the route-based suppression (the consent endpoint answers for the page)
     * @return array<string, mixed>
     */
    public function clientConfig(Request $request, ?bool $suppressed = null): array
    {
        $decision = $this->decision($request);
        $suppressed ??= $this->suppressedFor($request);

        $services = [];
        foreach ($this->optionalServices() as $service) {
            if ($suppressed || $decision === null || ! ($decision['categories'][$service->category->value] ?? false)) {
                continue;
            }
            $services[] = [
                'key' => $service->key,
                'category' => $service->category->value,
                'loader' => $service->loader ?? [],
                'storage' => $service->managedStorage(),
            ];
        }

        // Storage every optional service manages: removed by the client when consent is withdrawn.
        $managed = $this->optionalServices()->flatMap(fn (ConsentService $s) => $s->managedStorage())->values()->all();

        return [
            'version' => $this->version(),
            'decision' => $decision === null ? null : ['categories' => $decision['categories'], 'at' => $decision['at']],
            'offered' => array_map(fn (ConsentCategory $c) => ['key' => $c->value, 'label' => $c->label(), 'description' => $c->description()], $this->offeredCategories()),
            'suppressed' => $suppressed,
            'services' => $services,
            'managedStorage' => $managed,
            'endpoint' => route('consent.store'),
            'events' => AnalyticsEvents::EVENTS,
        ];
    }
}
