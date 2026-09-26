<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

function platformAdmin(bool $withTwoFactor = true): User
{
    $user = $withTwoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();
    $user->forceFill(['is_platform_admin' => true, 'platform_admin_granted_at' => now()])->save();

    return $user;
}

it('refuses household owners who are not platform administrators', function () {
    household();

    $this->get(route('admin.index'))->assertForbidden();
    $this->get(route('admin.ai'))->assertForbidden();
    $this->get(route('admin.ai.settings'))->assertForbidden();
});

it('redirects an administrator without confirmed two-factor authentication to the security settings', function () {
    $this->actingAs(platformAdmin(withTwoFactor: false));

    $this->get(route('admin.index'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('admin_two_factor_required', true);
});

it('opens every admin page for an administrator with two-factor authentication', function () {
    $this->actingAs(platformAdmin());

    $this->get(route('admin.index'))->assertOk()->assertSee('Prehľad');
    $this->get(route('admin.ai'))->assertOk()->assertSee('AI použitie a náklady');
    $this->get(route('admin.households'))->assertOk()->assertSee('Domácnosti');
    $this->get(route('admin.audit'))->assertOk()->assertSee('Audit');

    // Settings pages re-confirm the password first.
    $this->get(route('admin.ai.settings'))->assertRedirect(route('password.confirm'));
    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('admin.ai.settings'))->assertOk()->assertSee('Váha reasoningu');
    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('admin.ai.rates'))->assertOk()->assertSee('Cenník AI');
});

it('does not demand two-factor authentication when the requirement is switched off', function () {
    config()->set('admin.require_two_factor', false);
    $this->actingAs(platformAdmin(withTwoFactor: false));

    $this->get(route('admin.index'))->assertOk();
});

it('shows the admin link only to administrators', function () {
    household();
    $this->get(route('cook.index'))->assertOk()->assertDontSee('data-test="admin-link"', false);

    $this->actingAs(platformAdmin());
    $this->get(route('cook.index'))->assertOk()->assertSee('data-test="admin-link"', false);
});

it('cannot be granted by mass assignment', function () {
    try {
        User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'is_platform_admin' => true]);
    } catch (MassAssignmentException) {
        // strict mode: also acceptable
    }

    expect(User::query()->where('is_platform_admin', true)->count())->toBe(0);
});
