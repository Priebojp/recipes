<?php

use App\Models\ConsentReceipt;
use App\Models\ConsentService;
use App\Services\Consent\ConsentPolicy;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ConsentServiceSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegalScenario;

function enableAnalytics(): ConsentService
{
    test()->seed(ConsentServiceSeeder::class);
    $ga = ConsentService::query()->where('key', 'ga4')->firstOrFail();
    $ga->update(['enabled' => true, 'loader' => ['type' => 'ga4', 'measurement_id' => 'G-TEST123']]);

    return $ga;
}

/** Cookie value the last consent response set, ready to be sent back. */
function consentCookie($response): string
{
    return $response->getCookie(ConsentPolicy::COOKIE)->getValue();
}

it('loads no optional service before a decision, after rejection or after withdrawal; acceptance loads it and revocation removes it', function () {
    enableAnalytics();

    // First visit: banner, no loader, no request could go anywhere.
    $this->get(route('home'))->assertOk()->assertSee('data-test="consent-banner"', false)->assertSee('Prijať voliteľné')->assertSee('Odmietnuť voliteľné')
        ->assertDontSee('googletagmanager')->assertDontSee('G-TEST123')->assertSee('"services":[]', false);

    $rejected = $this->post(route('consent.store'), ['action' => 'reject_all'])->assertRedirect();
    $this->withCookie(ConsentPolicy::COOKIE, consentCookie($rejected))->get(route('home'))->assertOk()
        ->assertDontSee('data-test="consent-banner"', false)->assertDontSee('G-TEST123')->assertSee('"analytics":false', false);

    $accepted = $this->postJson(route('consent.store'), ['action' => 'accept_all'])->assertOk()->assertJsonPath('config.decision.categories.analytics', true);
    expect($accepted->json('config.services.0.loader.measurement_id'))->toBe('G-TEST123');
    $this->withCookie(ConsentPolicy::COOKIE, consentCookie($accepted))->get(route('home'))->assertOk()->assertSee('G-TEST123')->assertSee('"_ga"', false);

    $withdrawn = $this->withCookie(ConsentPolicy::COOKIE, consentCookie($accepted))->postJson(route('consent.store'), ['action' => 'withdraw'])->assertOk();
    expect($withdrawn->json('config.services'))->toBe([])
        ->and($withdrawn->json('config.managedStorage.0.name'))->toBe('_ga'); // the client deletes these
    $this->withCookie(ConsentPolicy::COOKIE, consentCookie($withdrawn))->get(route('home'))->assertOk()->assertDontSee('G-TEST123')->assertDontSee('data-test="consent-banner"', false);

    expect(ConsentReceipt::query()->pluck('action')->all())->toBe(['reject_all', 'accept_all', 'withdraw'])
        ->and(ConsentReceipt::query()->first()->visitor_id)->toBeUuid()
        ->and(Schema::hasColumn('consent_receipts', 'ip'))->toBeFalse();
});

it('keeps marketing off when only analytics is granted and asks again for a new purpose instead of inheriting the old consent', function () {
    $ga = enableAnalytics();

    $custom = $this->postJson(route('consent.store'), ['action' => 'custom', 'categories' => ['analytics' => true, 'marketing' => true]])->assertOk();
    expect($custom->json('config.decision.categories'))->toBe(['analytics' => true, 'marketing' => false]) // no marketing service exists → never granted
        ->and($custom->json('config.offered'))->toHaveCount(1)
        ->and(ConsentReceipt::query()->latest('id')->first()->categories)->toBe(['analytics' => true, 'marketing' => false]);

    // A new purpose: the register bumps the consent version, the stored decision no longer applies.
    $ga->update(['consent_version' => $ga->consent_version + 1]);
    $this->withCookie(ConsentPolicy::COOKIE, consentCookie($custom))->get(route('home'))->assertOk()
        ->assertSee('data-test="consent-banner"', false)->assertDontSee('G-TEST123')->assertSee('"decision":null', false);
});

it('never loads analytics in the admin, on legal pages or on payment forms even with consent, and asks nothing when no optional service exists', function () {
    enableAnalytics();
    LegalScenario::ready();
    $this->seed(CatalogSeeder::class);
    $accepted = $this->postJson(route('consent.store'), ['action' => 'accept_all'])->assertOk();
    $cookie = consentCookie($accepted);

    $this->withCookie(ConsentPolicy::COOKIE, $cookie)->get(route('legal.show', ['slug' => 'vop']))->assertOk()->assertSee('"suppressed":true', false)->assertDontSee('G-TEST123');
    actingAsPlatformAdmin();
    $this->withCookie(ConsentPolicy::COOKIE, $cookie)->get(route('admin.index'))->assertOk()->assertDontSee('G-TEST123')->assertDontSee('mr-consent-config');
    $this->withCookie(ConsentPolicy::COOKIE, $cookie)->get(route('checkout.review', ['plan' => 'plus_monthly']))->assertOk()->assertSee('"suppressed":true', false)->assertDontSee('G-TEST123');
    $this->withCookie(ConsentPolicy::COOKIE, $cookie)->get(route('cook.index'))->assertOk()->assertSee('G-TEST123');

    ConsentService::query()->where('key', 'ga4')->update(['enabled' => false]);
    $this->get(route('home'))->assertOk()->assertDontSee('data-test="consent-banner"', false)->assertSee('"offered":[]', false)->assertDontSee('data-consent-open', false);
});
