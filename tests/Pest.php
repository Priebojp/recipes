<?php

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A logged-in user with their own household and diner profile.
 *
 * @return array{user: User, household: Household, person: Person}
 */
function household(): array
{
    $user = User::factory()->create();
    $household = CurrentHousehold::createFor($user);
    $person = $household->people()->firstOrFail();

    test()->actingAs($user);
    app(CurrentHousehold::class)->set($household);

    return ['user' => $user, 'household' => $household, 'person' => $person];
}

/**
 * A platform administrator with two-factor authentication, logged in with the password freshly confirmed
 * (the admin finance pages sit behind password.confirm).
 */
function actingAsPlatformAdmin(): User
{
    $admin = User::factory()->withTwoFactor()->create();
    $admin->forceFill(['is_platform_admin' => true, 'platform_admin_granted_at' => now()])->save();

    test()->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    return $admin;
}
