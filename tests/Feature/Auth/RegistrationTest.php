<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('cook.index', absolute: false));

    $this->assertAuthenticated();
});

test('registration creates a household with a linked diner profile', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'name' => 'Peter',
        'email' => 'peter@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'peter@example.com')->firstOrFail();
    $household = $user->currentHousehold();

    expect($household)->not->toBeNull()
        ->and($household->people()->where('user_id', $user->id)->where('name', 'Peter')->exists())->toBeTrue()
        ->and($household->default_person_ids)->toHaveCount(1);
});
