<?php

use App\Enums\MembershipRole;
use App\Models\HouseholdMembership;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\SelectionPreset;
use App\Models\User;
use App\Services\Plus\SelectionPresets;
use App\Support\CurrentHousehold;
use Livewire\Livewire;
use Tests\Support\PlusScenario;

it('saves, applies, overwrites and deletes presets from the selection page for a Plus household', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $guest = Person::factory()->guest()->create(['household_id' => $household->id, 'name' => 'Babka']);
    Recipe::factory()->create(['household_id' => $household->id]);

    $component = Livewire::test('pages::cook.select')
        ->set('personIds', [$a->id, $guest->id])
        ->set('mealType', 'dinner')
        ->set('maxMinutes', 30)
        ->set('onlyFavoritesOfAll', true)
        ->set('presetName', '  Návšteva  ')
        ->call('savePreset')
        ->assertHasNoErrors()
        ->assertSee('Šablóna „Návšteva“ je uložená');

    $preset = SelectionPreset::query()->firstOrFail();
    expect($preset->name)->toBe('Návšteva')
        ->and($preset->person_ids)->toBe([$a->id, $guest->id])
        ->and($preset->meal_type->value)->toBe('dinner')
        ->and($preset->filters['max_minutes'])->toBe(30)
        ->and($preset->filters['only_favorites_of_all'])->toBeTrue()
        ->and($preset->created_by)->toBe($user->id);

    // Same name replaces the preset instead of failing on the unique index.
    app(SelectionPresets::class)->save($household, 'Návšteva', ['person_ids' => [$a->id], 'meal_type' => 'any', 'filters' => []], $user);
    expect(SelectionPreset::count())->toBe(1)->and($preset->fresh()->person_ids)->toBe([$a->id]);

    $guest->update(['archived_at' => now()]);
    app(SelectionPresets::class)->save($household, 'Rodina', ['person_ids' => [$a->id, $guest->id], 'filters' => ['allow_disliked' => true]], $user);
    $family = SelectionPreset::query()->where('name', 'Rodina')->firstOrFail();

    // Applying restores diners (minus archived ones), meal type and filters; nothing is saved by that alone.
    $component
        ->set('personIds', [])
        ->set('mealType', 'any')
        ->set('maxMinutes', null)
        ->set('allowDisliked', false)
        ->call('applyPreset', $family->id)
        ->assertSet('personIds', [$a->id])
        ->assertSet('allowDisliked', true)
        ->assertSee('Šablóna „Rodina“ je použitá')
        ->call('deletePreset', $family->id);

    expect(SelectionPreset::query()->pluck('name')->all())->toBe(['Návšteva']);
});

it('lets a Free household apply presets it already has but not save new ones, and refuses members', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $preset = app(SelectionPresets::class)->save($household, 'Rodina', ['person_ids' => [$a->id], 'meal_type' => 'lunch', 'filters' => []], $user);
    PlusScenario::expire($household);

    $this->get(route('cook.select'))->assertOk()->assertSee('Rodina')->assertSee('Ukladanie šablón je súčasťou Plus');

    Livewire::test('pages::cook.select')
        ->call('applyPreset', $preset->id)
        ->assertSet('mealType', 'lunch')
        ->set('presetName', 'Nová')
        ->call('savePreset')
        ->assertForbidden();

    expect(SelectionPreset::count())->toBe(1);

    // A plain member (read-only role) can use presets but neither save nor delete them, even with Plus.
    PlusScenario::activate($household);
    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $member->id, 'role' => MembershipRole::Member]);
    $this->actingAs($member);
    app(CurrentHousehold::class)->set($household);

    Livewire::test('pages::cook.select')
        ->call('applyPreset', $preset->id)
        ->assertSet('mealType', 'lunch')
        ->call('deletePreset', $preset->id)
        ->assertForbidden();
    expect(SelectionPreset::count())->toBe(1);
});

it('validates the name and enforces the visible limit', function () {
    ['household' => $household, 'person' => $a, 'user' => $user] = household();
    PlusScenario::activate($household);
    $presets = app(SelectionPresets::class);

    expect(fn () => $presets->save($household, '   ', ['person_ids' => [$a->id]], $user))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $presets->save($household, 'Bez ľudí', ['person_ids' => []], $user))->toThrow(InvalidArgumentException::class);

    for ($i = 1; $i <= SelectionPresets::LIMIT; $i++) {
        $presets->save($household, 'Šablóna '.$i, ['person_ids' => [$a->id]], $user);
    }
    expect(fn () => $presets->save($household, 'Ešte jedna', ['person_ids' => [$a->id]], $user))->toThrow(InvalidArgumentException::class, 'najviac');
    $presets->save($household, 'Šablóna 1', ['person_ids' => [$a->id], 'meal_type' => 'dinner'], $user); // overwrite still works at the limit
    expect(SelectionPreset::count())->toBe(SelectionPresets::LIMIT);
});
