<?php

use App\Enums\MembershipRole;
use App\Enums\PrivacyRequestKind;
use App\Enums\PrivacyRequestStatus;
use App\Mail\AccountErasedMail;
use App\Models\AdminAudit;
use App\Models\Household;
use App\Models\HouseholdMembership;
use App\Models\Order;
use App\Models\Person;
use App\Models\PrivacyRequest;
use App\Models\Recipe;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\BillingScenario;

it('erases the right household: content and account go, accounting records stay anonymised, other households are untouched', function () {
    Mail::fake();
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);
    Recipe::factory()->count(2)->create(['household_id' => $h['household']->id]);
    Person::factory()->create(['household_id' => $h['household']->id, 'name' => 'Dieťa']);

    $other = User::factory()->create();
    $otherHousehold = CurrentHousehold::createFor($other);
    Recipe::factory()->create(['household_id' => $otherHousehold->id, 'title' => 'Cudzí recept']);

    $this->actingAs($h['user']);
    $email = $h['user']->email;
    $this->get(route('privacy.edit'))->assertOk()->assertSee('Vymazanie účtu')->assertSee('Nevyužité dokúpené použitia: 100')->assertSee('Účtovné doklady');
    $this->get(route('privacy.export'))->assertOk()->assertHeader('content-disposition')->assertJsonPath('account.email', $h['user']->email);

    Livewire::test('pages::settings.privacy')
        ->set('password', 'wrong')->call('erase')->assertHasErrors(['password'])
        ->set('password', 'password')->set('erasureMessage', 'Už nevarím.')->call('erase')->assertHasNoErrors()->assertRedirect('/');

    $user = User::query()->find($h['user']->id);
    $household = Household::query()->find($h['household']->id);
    expect($user)->not->toBeNull()->and($user->email)->toBe('erased-'.$user->id.'@erased.invalid')->and($user->name)->toBe('Zrušený účet')
        ->and($household->isErased())->toBeTrue()->and($household->name)->toBe('Zrušená domácnosť #'.$household->id)
        ->and(Recipe::query()->where('household_id', $household->id)->count())->toBe(0)
        ->and(Person::query()->where('household_id', $household->id)->count())->toBe(0)
        ->and(HouseholdMembership::query()->where('household_id', $household->id)->count())->toBe(0)
        ->and(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and(Recipe::query()->where('household_id', $otherHousehold->id)->count())->toBe(1);

    $request = PrivacyRequest::query()->sole();
    expect($request->kind)->toBe(PrivacyRequestKind::Erasure)->and($request->status)->toBe(PrivacyRequestStatus::Completed)
        ->and($request->completion_evidence)->toContain('2 receptov')->and($request->message)->toBe('Už nevarím.')
        ->and(AdminAudit::query()->where('action', 'privacy.account.erased')->exists())->toBeTrue();
    Mail::assertQueued(AccountErasedMail::class, fn (AccountErasedMail $mail) => $mail->hasTo($email));

    $this->post(route('login.store'), ['email' => $email, 'password' => 'password'])->assertSessionHasErrors();
});

it('deletes everything when there are no financial records and lets a member leave without touching the household', function () {
    Mail::fake();
    ['user' => $owner, 'household' => $household] = household();
    Recipe::factory()->create(['household_id' => $household->id]);
    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $member->id, 'role' => MembershipRole::Member]);
    $memberPerson = Person::factory()->create(['household_id' => $household->id, 'user_id' => $member->id, 'name' => 'Jano']);

    $this->actingAs($member);
    Livewire::test('pages::settings.privacy')->set('password', 'password')->call('erase')->assertHasNoErrors();
    expect(User::query()->find($member->id))->toBeNull()
        ->and(Recipe::query()->where('household_id', $household->id)->count())->toBe(1)
        ->and($memberPerson->fresh()->name)->toBe('Bývalý člen')->and($memberPerson->fresh()->user_id)->toBeNull();

    $this->actingAs($owner);
    Livewire::test('pages::settings.privacy')->set('password', 'password')->call('erase')->assertHasNoErrors();
    expect(User::query()->find($owner->id))->toBeNull()->and(Household::query()->find($household->id))->toBeNull();
});

it('hands the household over to a chosen member instead of erasing it', function () {
    Mail::fake();
    ['user' => $owner, 'household' => $household] = household();
    Recipe::factory()->create(['household_id' => $household->id]);
    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $member->id, 'role' => MembershipRole::Editor]);

    Livewire::test('pages::settings.privacy')
        ->set('householdChoice', [$household->id => (string) $member->id])
        ->set('password', 'password')->call('erase')->assertHasNoErrors();

    expect(User::query()->find($owner->id))->toBeNull()
        ->and($household->fresh()->owner_user_id)->toBe($member->id)
        ->and(HouseholdMembership::query()->where('household_id', $household->id)->where('user_id', $member->id)->sole()->role)->toBe(MembershipRole::Owner)
        ->and(Recipe::query()->where('household_id', $household->id)->count())->toBe(1);
});

it('re-applies completed erasures after a backup restore brought content back', function () {
    Mail::fake();
    $h = BillingScenario::start();
    BillingScenario::payAddon(BillingScenario::startAddon('text_100'));
    Livewire::test('pages::settings.privacy')->set('password', 'password')->call('erase')->assertHasNoErrors();
    $household = Household::query()->find($h['household']->id);

    $this->artisan('app:privacy-reapply-erasures')->expectsOutputToContain('nič sa neobnovilo')->assertSuccessful();

    // "Restore": recipes and a name reappear.
    Recipe::factory()->create(['household_id' => $household->id]);
    $household->forceFill(['name' => 'Obnovená domácnosť', 'erased_at' => null])->save();

    $this->artisan('app:privacy-reapply-erasures')->expectsOutputToContain('Znovu vymazaný obsah domácností: '.$household->id)->assertSuccessful();
    expect(Recipe::query()->where('household_id', $household->id)->count())->toBe(0)
        ->and($household->fresh()->isErased())->toBeTrue()
        ->and(AdminAudit::query()->where('action', 'privacy.erasure.reapplied')->exists())->toBeTrue();
});
