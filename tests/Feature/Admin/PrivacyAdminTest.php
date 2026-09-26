<?php

use App\Enums\PrivacyRequestKind;
use App\Enums\PrivacyRequestStatus;
use App\Models\AdminAudit;
use App\Services\Privacy\PrivacyRequests;
use Livewire\Livewire;

it('lists data-subject requests with deadlines and records every status change with evidence and an audit row', function () {
    ['user' => $user, 'household' => $household] = household();
    $request = app(PrivacyRequests::class)->open(PrivacyRequestKind::Rectification, $user, $household, message: 'Opravte moje meno.');
    expect($request->deadline_at->diffInDays($request->received_at))->toBe(-30.0);

    $this->get(route('admin.privacy'))->assertForbidden();
    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.privacy'))->assertOk()->assertSee('oprava údajov')->assertSee($user->email);

    Livewire::test('pages::admin.privacy')
        ->call('start', $request->id, 'completed')
        ->call('apply')->assertHasErrors(['note'])
        ->set('note', 'Meno opravené v profile, používateľ informovaný e-mailom.')
        ->call('apply')->assertHasNoErrors();

    $request->refresh();
    expect($request->status)->toBe(PrivacyRequestStatus::Completed)->and($request->completed_at)->not->toBeNull()->and($request->handled_by)->toBe($admin->id)
        ->and($request->completion_evidence)->toContain('Meno opravené')
        ->and(AdminAudit::query()->where('action', 'privacy.request.updated')->where('actor_id', $admin->id)->exists())->toBeTrue();
});
