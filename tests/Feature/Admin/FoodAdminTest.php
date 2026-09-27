<?php

use App\Enums\FoodMappingStatus;
use App\Models\AdminAudit;
use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;
use App\Models\IngredientFoodMapping;
use App\Models\Recipe;
use App\Services\Food\FoodDataSource;
use App\Services\Food\FoodSearchHit;
use Livewire\Livewire;
use Tests\Support\FakeFoodDataSource;

function adminFoodSource(bool $configured = true): FakeFoodDataSource
{
    $fake = new FakeFoodDataSource(
        [FakeFoodDataSource::record('170000', 'Onions, raw', ['energy_kcal' => 40.0, 'protein_g' => 1.1, 'carbohydrate_g' => 9.34, 'fat_g' => 0.1], [['unit' => 'ks', 'grams' => 110.0, 'description' => '1 medium']])],
        ['onions raw' => [new FoodSearchHit('170000', 'Onions, raw', 'Vegetables and Vegetable Products')]],
    );
    $fake->configured = $configured;
    app()->instance(FoodDataSource::class, $fake);

    return $fake;
}

it('refuses household owners who are not platform administrators', function () {
    household();

    $this->get(route('admin.food'))->assertForbidden();
});

it('shows the key status without the secret, the unmapped ingredient names and the empty-catalogue hint', function () {
    adminFoodSource(configured: false);
    $recipe = Recipe::factory()->create();
    $line = $recipe->ingredients()->create(['position' => 0, 'name' => 'xylitolový sirup', 'numeric_amount' => 20, 'unit' => 'g']);
    IngredientFoodMapping::query()->create(['ingredient_line_id' => $line->id, 'status' => FoodMappingStatus::Unresolved, 'unresolved_reason' => 'V slovníku nie je zhoda pre „xylitolový sirup“.']);
    actingAsPlatformAdmin();

    $this->get(route('admin.food'))
        ->assertOk()
        ->assertSee('chýba')
        ->assertSee('USDA_FDC_API_KEY')
        ->assertDontSee('test-usda-key')
        ->assertSee('xylitolový sirup')
        ->assertSee('FoodAliasSeeder')
        ->assertDontSee($recipe->title);
});

it('imports a food from a provider search, lets the curator add aliases and conversions, and audits every catalogue change', function () {
    adminFoodSource();
    $admin = actingAsPlatformAdmin();

    $component = Livewire::test('pages::admin.food')
        ->set('query', 'onions raw')
        ->call('search')
        ->assertSet('searchError', null)
        ->assertSee('Onions, raw')
        ->call('import', '170000');

    $record = FoodSourceRecord::query()->where('external_id', '170000')->firstOrFail();
    expect($record->energy_kcal)->toBe('40.00')
        ->and($record->is_curated)->toBeFalse()
        ->and($record->conversions()->where('unit', 'ks')->value('grams'))->toBe('110.00')
        ->and(AdminAudit::query()->where('action', 'food.record.imported')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $component->assertSet('selectedId', $record->id)
        ->set('name_sk', 'Cibuľa')
        ->set('preparation_state', 'raw')
        ->set('is_curated', true)
        ->call('saveRecord')->assertHasNoErrors()
        ->set('aliasName', 'cibuľa')
        ->set('aliasLocale', 'sk')
        ->set('aliasState', 'raw')
        ->call('addAlias')->assertHasNoErrors()
        ->set('aliasName', 'Cibuľa')
        ->call('addAlias')->assertHasErrors(['aliasName']) // same normalised alias
        ->set('conversionUnit', 'ks')
        ->set('conversionGrams', '95,5')
        ->set('conversionSource', FoodUnitConversion::SOURCE_MANUAL)
        ->set('conversionNote', 'stredná cibuľa')
        ->call('addConversion')->assertHasNoErrors()
        ->set('conversionUnit', 'PL')
        ->set('conversionGrams', 'veľa')
        ->call('addConversion')->assertHasErrors(['conversionGrams']);

    $record->refresh();
    expect($record->name_sk)->toBe('Cibuľa')
        ->and($record->is_curated)->toBeTrue()
        ->and($record->aliases()->pluck('normalized')->all())->toBe(['cibuľa'])
        ->and($record->conversions()->where('unit', 'ks')->first())->toMatchArray(['grams' => '95.50', 'source' => 'manual', 'confirmed_by' => $admin->id])
        ->and(AdminAudit::query()->pluck('action')->all())->toContain('food.record.updated', 'food.alias.created', 'food.conversion.updated')
        ->and(AdminAudit::query()->where('action', 'food.conversion.updated')->first()->changes)->toMatchArray(['before' => ['unit' => 'ks', 'grams' => '110.00', 'source' => 'usda_portion', 'note' => '1 medium']]);

    $alias = $record->aliases()->firstOrFail();
    $conversion = $record->conversions()->firstOrFail();
    $component->call('removeAlias', $alias->id)->call('removeConversion', $conversion->id);

    expect($record->aliases()->count())->toBe(0)
        ->and($record->conversions()->count())->toBe(0)
        ->and(AdminAudit::query()->pluck('action')->all())->toContain('food.alias.deleted', 'food.conversion.deleted');
});

it('reports search as unavailable instead of failing when the provider cannot be asked', function () {
    adminFoodSource(configured: false);
    actingAsPlatformAdmin();

    Livewire::test('pages::admin.food')
        ->set('query', 'onions raw')
        ->call('search')
        ->assertSet('searchResults', [])
        ->assertSee('USDA_FDC_API_KEY');
});
