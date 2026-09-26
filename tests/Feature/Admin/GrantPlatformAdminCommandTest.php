<?php

use App\Models\AdminAudit;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Support\Facades\Hash;

it('creates the support account and grants the role idempotently', function () {
    $this->artisan('app:grant-platform-admin', [
        'email' => 'Support@moje-recepty.sk',
        '--create' => true,
        '--name' => 'Podpora',
        '--password' => 'Zmenit-Na-Produkcii-123!',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'support@moje-recepty.sk')->firstOrFail();
    expect($user->isPlatformAdmin())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Podpora')
        ->and(Hash::check('Zmenit-Na-Produkcii-123!', $user->password))->toBeTrue();

    $this->artisan('app:grant-platform-admin', ['email' => 'support@moje-recepty.sk'])
        ->expectsOutputToContain('už je administrátor')
        ->assertSuccessful();

    expect(User::query()->where('is_platform_admin', true)->count())->toBe(1)
        ->and(AdminAudit::query()->where('action', 'admin.role.granted')->count())->toBe(1);
});

it('generates a password when none is given and prints it once', function () {
    $this->artisan('app:grant-platform-admin', ['email' => 'owner@example.com', '--create' => true])
        ->expectsOutputToContain('Vygenerované heslo')
        ->assertSuccessful();

    expect(User::query()->where('email', 'owner@example.com')->value('is_platform_admin'))->toBeTruthy();
});

it('fails for a missing account without --create and for an invalid e-mail', function () {
    $this->artisan('app:grant-platform-admin', ['email' => 'nobody@example.com'])->assertFailed();
    $this->artisan('app:grant-platform-admin', ['email' => 'not-an-email'])->assertExitCode(2);
    expect(User::query()->count())->toBe(0);
});

it('grants the role to an existing verified user without touching the password', function () {
    $user = User::factory()->create(['email' => 'peter@example.com']);
    $hash = $user->password;

    $this->artisan('app:grant-platform-admin', ['email' => 'peter@example.com'])->assertSuccessful();

    expect($user->fresh()->isPlatformAdmin())->toBeTrue()->and($user->fresh()->password)->toBe($hash);
});

it('refuses to revoke the last administrator but revokes when another exists', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $this->artisan('app:grant-platform-admin', ['email' => $first->email])->assertSuccessful();

    $this->artisan('app:grant-platform-admin', ['email' => $first->email, '--revoke' => true])->assertFailed();
    expect($first->fresh()->isPlatformAdmin())->toBeTrue();

    $this->artisan('app:grant-platform-admin', ['email' => $second->email])->assertSuccessful();
    $token = $first->fresh()->remember_token;
    $this->artisan('app:grant-platform-admin', ['email' => $first->email, '--revoke' => true])->assertSuccessful();

    expect($first->fresh()->isPlatformAdmin())->toBeFalse()
        ->and($first->fresh()->remember_token)->not->toBe($token)
        ->and(AdminAudit::query()->where('action', 'admin.role.revoked')->count())->toBe(1);
});

it('seeds the support administrator outside production and stays idempotent', function () {
    $this->seed(PlatformAdminSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $user = User::query()->where('email', 'support@moje-recepty.sk')->firstOrFail();
    expect($user->isPlatformAdmin())->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and(User::query()->where('is_platform_admin', true)->count())->toBe(1);
});
