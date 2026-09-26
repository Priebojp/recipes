<?php

use App\Enums\MembershipRole;
use App\Livewire\HouseholdInvitations;
use App\Models\HouseholdMembership;
use App\Models\Recipe;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('shows only published, non-archived recipes on the public home page', function () {
    $public = Recipe::factory()->published()->create(['title' => 'Verejný guláš']);
    Recipe::factory()->create(['title' => 'Súkromná polievka']);
    Recipe::factory()->published()->archived()->create(['title' => 'Archivovaný koláč']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Verejný guláš')
        ->assertDontSee('Súkromná polievka')
        ->assertDontSee('Archivovaný koláč');

    $this->get(route('home', ['q' => 'guláš']))->assertOk()->assertSee('Verejný guláš');
    $this->get(route('home', ['q' => 'xyz']))->assertOk()->assertSee('Nič sa nenašlo');
});

it('renders a public recipe page for guests and hides private ones', function () {
    $public = Recipe::factory()->published()->create(['title' => 'Verejný guláš']);
    $private = Recipe::factory()->create(['title' => 'Súkromná polievka']);

    $this->get(route('public.recipe', $public))->assertOk()->assertSee('Verejný guláš');
    $this->get(route('public.recipe', $private))->assertNotFound();
});

it('lets an editor publish and unpublish a recipe from the detail page', function () {
    ['household' => $household] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);

    Livewire::test('pages::recipes.show', ['recipe' => $recipe])
        ->call('publish')
        ->assertSee('Zrušiť zverejnenie');
    expect($recipe->fresh()->isPublic())->toBeTrue();

    Livewire::test('pages::recipes.show', ['recipe' => $recipe])
        ->call('unpublish');
    expect($recipe->fresh()->isPublic())->toBeFalse();
});

it('toggles publishing with the switch in the edit page', function () {
    ['household' => $household] = household();
    $recipe = Recipe::factory()->create(['household_id' => $household->id]);

    Livewire::test('pages::recipes.edit', ['recipe' => $recipe])
        ->assertSet('isPublic', false)
        ->set('isPublic', true);

    expect($recipe->fresh()->isPublic())->toBeTrue();
});

it('serves images of a public recipe without login but keeps private ones hidden', function () {
    Storage::fake('local');
    config(['media-library.disk_name' => 'local']);

    $public = Recipe::factory()->published()->create();
    $private = Recipe::factory()->create();
    app(ImageUploadService::class)->addCover($public, UploadedFile::fake()->image('a.jpg', 800, 600));
    app(ImageUploadService::class)->addCover($private, UploadedFile::fake()->image('b.jpg', 800, 600));

    $this->get(route('media.show', [$public->fresh()->cover, 'thumb']))->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
    $this->get(route('media.show', [$private->fresh()->cover, 'thumb']))->assertNotFound();
});

it('asks for confirmation before removing a household member', function () {
    ['household' => $household] = household();
    $other = User::factory()->create();
    $membership = HouseholdMembership::create(['household_id' => $household->id, 'user_id' => $other->id, 'role' => MembershipRole::Member]);

    Livewire::test(HouseholdInvitations::class)
        ->call('askRemove', $membership->id)
        ->assertSet('removingId', $membership->id)
        ->call('remove');

    expect(HouseholdMembership::query()->whereKey($membership->id)->exists())->toBeFalse();
});
