<?php

use App\Enums\MembershipRole;
use App\Enums\Preference;
use App\Livewire\HouseholdInvitations;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMembership;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use App\Services\ExportService;
use App\Services\ImageUploadService;
use App\Services\MealPlanningService;
use App\Services\PreferenceService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('exports recipes, raw texts, preferences, plans, history and images with stable references', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id, 'title' => 'Halušky', 'raw_text' => 'pôvodný zápis']);
    $media = app(ImageUploadService::class)->addCover($recipe, UploadedFile::fake()->image('c.jpg', 200, 150));
    app(PreferenceService::class)->set($h['person'], $recipe, Preference::Favorite);
    $plan = app(MealPlanningService::class)->create($h['household'], $recipe, ['mode' => 'someday', 'person_ids' => [$h['person']->id]], $h['user']);
    app(MealPlanningService::class)->markCooked($plan, ['cooked_on' => now()->toDateString()], $h['user']);

    $files = [];
    $data = app(ExportService::class)->data($h['household'], $files);

    expect($data['schema_version'])->toBe(2)
        ->and($data['selection_presets'])->toBe([])
        ->and($data['shopping_lists'])->toBe([])
        ->and($data['recipes'][0]['raw_text'])->toBe('pôvodný zápis')
        ->and($data['recipes'][0]['cover_media_id'])->toBe($media->id)
        ->and($data['recipes'][0]['covers'][0]['file'])->toBe('media/'.$media->id.'-'.$media->file_name)
        ->and($data['recipes'][0]['preferences'][0]['person_id'])->toBe($h['person']->id)
        ->and($data['meal_plans'][0]['status'])->toBe('cooked')
        ->and($data['cooking_events'][0]['recipe_id'])->toBe($recipe->id)
        ->and($files)->toHaveKey('media/'.$media->id.'-'.$media->file_name);

    $zip = app(ExportService::class)->build($h['household']);
    $archive = new ZipArchive;
    $archive->open($zip);
    expect($archive->locateName('export.json'))->not->toBeFalse()
        ->and($archive->locateName('media/'.$media->id.'-'.$media->file_name))->not->toBeFalse();
    $archive->close();
    unlink($zip);

    $this->get(route('export'))->assertOk()->assertDownload();
});

it('creates single-use expiring invitations that link the chosen profile without merging by name', function () {
    $h = household();
    $eva = Person::factory()->create(['household_id' => $h['household']->id, 'name' => 'Eva']);

    Livewire::test(HouseholdInvitations::class)
        ->set('role', 'editor')
        ->set('personId', $eva->id)
        ->call('create')
        ->assertSet('createdLink', fn ($link) => str_contains($link, '/invite/'));

    $invitation = HouseholdInvitation::firstOrFail();
    expect($invitation->role)->toBe(MembershipRole::Editor)->and($invitation->expires_at->isFuture())->toBeTrue();

    $newcomer = User::factory()->create(['name' => 'Eva']);
    $this->actingAs($newcomer);
    $this->get(route('invite.show', $invitation->token))->assertOk()->assertSee('Eva');
    $this->post(route('invite.accept', $invitation->token))->assertRedirect(route('cook.index'));

    expect(HouseholdMembership::where('household_id', $h['household']->id)->where('user_id', $newcomer->id)->value('role'))->toBe(MembershipRole::Editor)
        ->and($eva->fresh()->user_id)->toBe($newcomer->id)
        ->and(Person::where('household_id', $h['household']->id)->count())->toBe(2);

    // The invited household is now the active one for the newcomer.
    $this->get(route('family.index'))->assertOk()->assertSee('Eva');

    // Second use of the same link is refused.
    $another = User::factory()->create();
    $this->actingAs($another);
    $this->post(route('invite.accept', $invitation->token))->assertStatus(410);
    expect(HouseholdMembership::where('household_id', $h['household']->id)->where('user_id', $another->id)->exists())->toBeFalse();
});

it('lets a member only manage preferences while an editor edits content', function () {
    $h = household();
    $recipe = Recipe::factory()->create(['household_id' => $h['household']->id]);

    $member = User::factory()->create();
    HouseholdMembership::create(['household_id' => $h['household']->id, 'user_id' => $member->id, 'role' => MembershipRole::Member]);
    $this->actingAs($member);

    $this->get(route('recipes.show', $recipe))->assertOk();
    $this->get(route('recipes.edit', $recipe))->assertForbidden();
    expect($member->can('update', $recipe))->toBeFalse()->and($member->can('view', $recipe))->toBeTrue();
});
