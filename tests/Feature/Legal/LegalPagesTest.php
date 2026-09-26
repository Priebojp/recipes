<?php

use App\Enums\LegalDocumentType;
use App\Models\User;
use App\Services\Legal\LegalDocuments;
use Database\Seeders\ConsentServiceSeeder;
use Database\Seeders\LegalDocumentSeeder;
use Tests\Support\LegalScenario;

it('shows "being prepared" to the public and the placeholder draft only to an administrator', function () {
    $this->seed(LegalDocumentSeeder::class);

    $this->get(route('legal.show', ['slug' => 'vop']))->assertOk()->assertSee('Dokument sa pripravuje')->assertDontSee('OBCHODNÉ MENO');
    $this->get(route('home'))->assertOk()->assertSee('Obchodné podmienky')->assertSee('Odstúpenie od zmluvy');
    $this->get(route('legal.show', ['slug' => 'vop', 'version' => 1]))->assertNotFound();
    $this->get(route('legal.show', ['slug' => 'neexistuje']))->assertNotFound();

    actingAsPlatformAdmin();
    $this->get(route('legal.show', ['slug' => 'vop']))->assertOk()->assertSee('Pracovný návrh')->assertSee('[OBCHODNÉ MENO, SÍDLO, IČO, REGISTER]');
});

it('renders the published documents with version, archive and the operator identity', function () {
    LegalScenario::ready();

    $this->get(route('legal.show', ['slug' => 'vop']))->assertOk()
        ->assertSee('Verzia 1')->assertSee('Program Plus je určený pre jednu domácnosť')->assertSee('Testovací prevádzkovateľ s. r. o.')->assertDontSee('[EMAIL]');
    $this->get(route('legal.show', ['slug' => 'ochrana-osobnych-udajov']))->assertOk()->assertSee('privacy@example.test');
    $this->get(route('legal.show', ['slug' => 'odstupenie-od-zmluvy']))->assertOk()->assertSee('Formulár odstúpenia od zmluvy');
    $this->get(route('legal.archive', ['slug' => 'vop']))->assertOk()->assertSee('v1');
    $this->get(route('legal.show', ['slug' => 'vop', 'version' => 1]))->assertOk();
    $this->get(route('legal.show', ['slug' => 'vop', 'version' => 9]))->assertNotFound();
    $this->get(route('legal.contact'))->assertOk()->assertSee('reklamacie@example.test')->assertSee('Slovenská obchodná inšpekcia');

    // A new draft is invisible to the public until published; the previous version stays in the archive afterwards.
    $documents = app(LegalDocuments::class);
    $draft = $documents->newDraft(LegalDocumentType::Terms, summary: 'Nové ceny');
    $this->get(route('legal.show', ['slug' => 'vop', 'version' => 2]))->assertNotFound();
    $documents->publish($draft, 'ok', User::factory()->create());
    $this->get(route('legal.show', ['slug' => 'vop']))->assertOk()->assertSee('Verzia 2');
    $this->get(route('legal.show', ['slug' => 'vop', 'version' => 1]))->assertOk()->assertSee('archivovaná verzia');
});

it('generates the cookies inventory from the service register and asks for no empty consent', function () {
    LegalScenario::ready();
    $this->seed(ConsentServiceSeeder::class);

    $this->get(route('legal.show', ['slug' => 'cookies']))->assertOk()
        ->assertSee('XSRF-TOKEN')->assertSee('mr_consent')->assertSee('flux.appearance')
        ->assertDontSee('Google Analytics 4') // disabled services are not "deployed" and never listed
        ->assertSee('nepoužívame žiadne voliteľné služby')
        ->assertDontSee('data-test="consent-banner"', false);
});
