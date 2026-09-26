<?php

use App\Models\AdminAudit;
use App\Models\ConsentService;
use App\Services\Consent\ConsentPolicy;
use Database\Seeders\ConsentServiceSeeder;
use Livewire\Livewire;

it('enables an analytics provider only with a loader and bumps the consent version so visitors decide again', function () {
    $this->seed(ConsentServiceSeeder::class);
    $before = app(ConsentPolicy::class)->version();
    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.services'))->assertOk()->assertSee('Google Analytics 4')->assertSee('vypnutá');

    $ga = ConsentService::query()->where('key', 'ga4')->firstOrFail();
    Livewire::test('pages::admin.services')
        ->call('edit', $ga->id)
        ->set('enabled', true)
        ->set('reason', 'Vybraný poskytovateľ analytiky')
        ->call('save')->assertHasErrors(['loader']) // measurement id missing
        ->set('loader', '{"type":"ga4","measurement_id":"G-ABC"}')
        ->set('storage', '_ga | cookie | moje-recepty.sk | 2 roky | rozlíšenie návštevníkov')
        ->call('save')->assertHasNoErrors();

    $ga->refresh();
    expect($ga->enabled)->toBeTrue()->and($ga->consent_version)->toBe(2)->and($ga->loader['measurement_id'])->toBe('G-ABC')->and($ga->storage)->toHaveCount(1)
        ->and(app(ConsentPolicy::class)->version())->not->toBe($before)
        ->and(AdminAudit::query()->where('action', 'consent.service.updated')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $this->get(route('home'))->assertOk()->assertSee('data-test="consent-banner"', false);

    Livewire::test('pages::admin.services')
        ->call('startCreate')
        ->set('key', 'mailer')->set('name', 'Odosielanie e-mailov')->set('provider', 'Poskytovateľ SMTP')->set('category', 'necessary')
        ->set('purpose', 'Overenie e-mailu, potvrdenia objednávok.')->set('enabled', true)->set('reason', 'Inventár')
        ->call('save')->assertHasNoErrors();
    expect(ConsentService::query()->where('key', 'mailer')->exists())->toBeTrue();
});
