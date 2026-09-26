<?php

use App\Enums\LegalAcceptanceAction;
use App\Models\LegalAcceptance;
use App\Models\User;
use Tests\Support\LegalScenario;

it('requires a separate acceptance of the published terms and nothing else (no GDPR or marketing consent)', function () {
    LegalScenario::ready();

    $this->get(route('register'))->assertOk()->assertSee('obchodné podmienky')->assertSee('verzia 1')->assertDontSee('marketing');

    $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Peter', 'email' => 'peter@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors('terms');
    expect(User::query()->where('email', 'peter@example.com')->exists())->toBeFalse();

    $this->post(route('register.store'), [
        'name' => 'Peter', 'email' => 'peter@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'terms' => '1',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'peter@example.com')->firstOrFail();
    $acceptance = LegalAcceptance::query()->where('user_id', $user->id)->sole();
    expect($acceptance->action)->toBe(LegalAcceptanceAction::Registration)
        ->and($acceptance->documentVersion->version)->toBe(1)
        ->and($acceptance->checksum)->toBe(LegalScenario::currentTerms()->checksum)
        ->and($acceptance->household_id)->toBe($user->currentHousehold()->id);
});

it('registers without a terms checkbox while no terms are published', function () {
    $this->get(route('register'))->assertOk()->assertDontSee('data-test="register-terms"', false);

    $this->post(route('register.store'), [
        'name' => 'Eva', 'email' => 'eva@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(LegalAcceptance::query()->count())->toBe(0);
});
