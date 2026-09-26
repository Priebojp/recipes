<?php

use App\Enums\LegalDocumentType;
use App\Models\AdminAudit;
use App\Models\LegalDocumentVersion;
use App\Services\Legal\LegalDocuments;
use Database\Seeders\LegalDocumentSeeder;
use Livewire\Livewire;
use Tests\Support\LegalScenario;

it('refuses publishing placeholders, fills the operator identity, publishes with an approval note and archives the predecessor', function () {
    $this->seed(LegalDocumentSeeder::class);
    household();
    $this->get(route('admin.legal'))->assertForbidden();

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.legal'))->assertOk()->assertSee('Platený checkout je zablokovaný')->assertSee('Chýba identita prevádzkovateľa')->assertSee('VOP: nie je publikovaná');

    Livewire::test('pages::admin.legal')
        ->set('operator', [...LegalScenario::operator(), 'support_email' => 'nie-email'])
        ->set('operator_reason', 'Zakladateľské údaje')
        ->call('saveOperator')->assertHasErrors(['operator.support_email'])
        ->set('operator', LegalScenario::operator())
        ->call('saveOperator')->assertHasNoErrors();
    expect(AdminAudit::query()->where('action', 'legal.operator.updated')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $draft = app(LegalDocuments::class)->draft(LegalDocumentType::Terms);
    $page = Livewire::test('pages::admin.legal-document', ['version' => $draft])
        ->set('approval_note', 'Skontroloval právnik 26. 9. 2026')
        ->call('publish')
        ->assertHasErrors(['approval_note']);
    expect($draft->fresh()->isDraft())->toBeTrue();

    $page->call('fillOperator');
    expect($page->get('content'))->toContain('Testovací prevádzkovateľ s. r. o.')->not->toContain('[EMAIL]');
    $page->set('content', preg_replace(LegalDocumentVersion::PLACEHOLDER_PATTERN, 'doplnené', $page->get('content')))
        ->set('effective_at', '2026-10-01')
        ->call('publish')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.legal'));

    $published = $draft->fresh();
    expect($published->isPublished())->toBeTrue()->and($published->approved_by)->toBe($admin->id)->and($published->effective_at->format('Y-m-d'))->toBe('2026-10-01')
        ->and(AdminAudit::query()->where('action', 'legal.document.published')->where('actor_id', $admin->id)->exists())->toBeTrue();

    // A second version: created from the page, published, and the first one is archived – both stay readable.
    Livewire::test('pages::admin.legal')->call('newDraft', 'terms')->assertRedirect();
    $v2 = app(LegalDocuments::class)->draft(LegalDocumentType::Terms);
    expect($v2->version)->toBe(2)->and($v2->content)->toBe($published->content);
    Livewire::test('pages::admin.legal')->call('newDraft', 'terms'); // refused while a draft exists
    expect(LegalDocumentVersion::query()->where('type', LegalDocumentType::Terms)->count())->toBe(2);

    Livewire::test('pages::admin.legal-document', ['version' => $v2])->set('approval_note', 'v2 ok')->call('publish')->assertHasNoErrors();
    expect($published->fresh()->state->value)->toBe('archived')->and($v2->fresh()->isPublished())->toBeTrue();
    $this->get(route('legal.show', ['slug' => 'vop', 'version' => 1]))->assertOk()->assertSee('archivovaná verzia');

    // A published version is immutable.
    expect(fn () => app(LegalDocuments::class)->updateDraft($v2->fresh(), ['content' => 'x']))->toThrow(InvalidArgumentException::class);
});
