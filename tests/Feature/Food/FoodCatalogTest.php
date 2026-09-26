<?php

use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;
use App\Models\IngredientFoodMapping;
use App\Models\Recipe;
use App\Services\Admin\AppSettings;
use App\Services\Food\FoodCatalog;
use App\Services\Food\FoodDataSource;
use App\Services\Food\FoodSearchHit;
use App\Services\Food\FoodSourceUnavailableException;
use Database\Seeders\FoodAliasSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\FakeFoodDataSource;

function fakeFoodSource(array $records = [], array $searches = []): FakeFoodDataSource
{
    $fake = new FakeFoodDataSource($records, $searches);
    app()->instance(FoodDataSource::class, $fake);

    return $fake;
}

it('seeds the dictionary idempotently with raw and cooked rice as two records behind the same alias', function () {
    $this->seed(FoodAliasSeeder::class);
    $records = FoodSourceRecord::query()->count();
    $aliases = FoodAlias::query()->count();

    $this->seed(FoodAliasSeeder::class);

    $rice = FoodAlias::query()->where('normalized', 'ryža')->with('record')->get();

    expect(FoodSourceRecord::query()->count())->toBe($records)
        ->and(FoodAlias::query()->count())->toBe($aliases)
        ->and($records)->toBeGreaterThanOrEqual(100)
        ->and($rice)->toHaveCount(2)
        ->and($rice->pluck('preparation_state')->all())->toEqualCanonicalizing([FoodPreparationState::Raw, FoodPreparationState::Cooked])
        ->and($rice->pluck('record.external_id')->unique())->toHaveCount(2)
        ->and(FoodSourceRecord::query()->whereNotNull('fetched_at')->exists())->toBeFalse()
        ->and(FoodSourceRecord::query()->where('external_id', '170000')->first()->source_snapshot['seed']['expected_name'])->toBe('Onions, raw');
});

it('fills seeded records from the source with snapshot, licence and portions while keeping curated fields, manual conversions and existing mappings', function () {
    $this->seed(FoodAliasSeeder::class);
    $onion = FoodSourceRecord::query()->where('external_id', '170000')->firstOrFail();
    $onion->conversions()->create(['unit' => 'ks', 'grams' => 80, 'source' => FoodUnitConversion::SOURCE_MANUAL, 'confirmed_at' => now()]);
    $recipe = Recipe::factory()->create();
    $line = $recipe->ingredients()->create(['position' => 0, 'name' => 'cibuľa', 'numeric_amount' => 1, 'unit' => 'ks']);
    $mapping = IngredientFoodMapping::query()->create(['ingredient_line_id' => $line->id, 'food_source_record_id' => $onion->id, 'grams' => 80, 'grams_origin' => 'unit_conversion', 'status' => FoodMappingStatus::Confirmed]);

    fakeFoodSource([
        FakeFoodDataSource::record('170000', 'Onions, raw', ['energy_kcal' => 40.0, 'protein_g' => 1.1, 'carbohydrate_g' => 9.34, 'fat_g' => 0.1, 'fiber_g' => 1.7], [
            ['unit' => 'ks', 'grams' => 110.0, 'description' => '1 medium'],
            ['unit' => 'šálka', 'grams' => 160.0, 'description' => '1 cup, chopped'],
        ]),
    ]);

    $this->artisan('app:food-sync', ['--only' => ['170000']])->assertSuccessful();

    $onion->refresh();
    expect($onion->energy_kcal)->toBe('40.00')
        ->and($onion->fiber_g)->toBe('1.70')
        ->and($onion->energy_kj)->toBeNull()
        ->and($onion->license)->toBe('CC0-1.0')
        ->and($onion->fetched_at)->not->toBeNull()
        ->and($onion->source_snapshot['description'])->toBe('Onions, raw')
        ->and($onion->source_snapshot['seed']['expected_name'])->toBe('Onions, raw')
        ->and($onion->sync_warning)->toBeNull()
        ->and($onion->name_sk)->toBe('Cibuľa')
        ->and($onion->is_curated)->toBeTrue()
        ->and($onion->preparation_state)->toBe(FoodPreparationState::Raw)
        ->and($onion->conversions()->where('unit', 'ks')->value('grams'))->toBe('80.00') // manual row wins over the provider portion
        ->and($onion->conversions()->where('unit', 'šálka')->value('source'))->toBe(FoodUnitConversion::SOURCE_USDA_PORTION)
        ->and($mapping->fresh()->grams)->toBe('80.00')
        ->and($mapping->fresh()->status)->toBe(FoodMappingStatus::Confirmed)
        ->and(app(FoodCatalog::class)->lastSync()['checked'])->toBe(1);
});

it('flags a record whose description changed at the source or vanished, and a dry run writes nothing', function () {
    $this->seed(FoodAliasSeeder::class);
    $fake = fakeFoodSource([FakeFoodDataSource::record('170393', 'Carrots, baby, raw', ['energy_kcal' => 35.0])]);

    $this->artisan('app:food-sync', ['--only' => ['170393', '169230'], '--dry-run' => true])->assertSuccessful();
    expect(FoodSourceRecord::query()->whereNotNull('fetched_at')->exists())->toBeFalse()
        ->and(FoodSourceRecord::query()->whereNotNull('sync_warning')->exists())->toBeFalse()
        ->and(app(FoodCatalog::class)->lastSync())->toBeNull()
        ->and($fake->fetched)->toBe(['169230', '170393']);

    $this->artisan('app:food-sync', ['--only' => ['170393', '169230']])->assertSuccessful();

    $carrot = FoodSourceRecord::query()->where('external_id', '170393')->firstOrFail();
    $garlic = FoodSourceRecord::query()->where('external_id', '169230')->firstOrFail();
    expect($carrot->sync_warning)->toContain('Carrots, baby, raw')->toContain('Carrots, raw')
        ->and($carrot->energy_kcal)->toBe('35.00')
        ->and($garlic->sync_warning)->toContain('už neexistuje')
        ->and($garlic->fetched_at)->toBeNull()
        ->and(app(FoodCatalog::class)->lastSync())->toMatchArray(['checked' => 2, 'updated' => 0, 'warnings' => 1, 'missing' => 1]);
});

it('fails the sync command with a clear message when the source is not configured', function () {
    $fake = fakeFoodSource();
    $fake->configured = false;

    $this->artisan('app:food-sync')->expectsOutputToContain('USDA_FDC_API_KEY')->assertFailed();

    expect(app(FoodCatalog::class)->lastSync())->toBeNull();
});

it('imports a verified provider food and refuses references that exist neither locally nor at the provider', function () {
    fakeFoodSource(
        [FakeFoodDataSource::record('171413', 'Oil, olive, salad or cooking', ['energy_kcal' => 884.0, 'fat_g' => 100.0])],
        ['olive oil' => [new FoodSearchHit('171413', 'Oil, olive, salad or cooking', 'Fats and Oils')]],
    );
    $catalog = app(FoodCatalog::class);
    $stored = FoodSourceRecord::factory()->create();

    $results = $catalog->search('olive oil');
    expect($results)->toHaveCount(1)->and($results[0]['record'])->toBeNull();

    $imported = $catalog->resolve('171413');
    expect($imported->name)->toBe('Oil, olive, salad or cooking')
        ->and($imported->is_curated)->toBeFalse()
        ->and($imported->fat_g)->toBe('100.00')
        ->and($catalog->resolve('171413')->is($imported))->toBeTrue()
        ->and($catalog->resolve($stored->id)->is($stored))->toBeTrue()
        ->and($catalog->search('olive oil')[0]['record']?->is($imported))->toBeTrue()
        ->and(fn () => $catalog->resolve('424242'))->toThrow(InvalidArgumentException::class, '424242')
        ->and(fn () => $catalog->resolve($stored->id + 1000))->toThrow(ModelNotFoundException::class)
        ->and(FoodSourceRecord::query()->count())->toBe(2);
});

it('keeps the stored dictionary usable without a key and reports search as unavailable', function () {
    $this->seed(FoodAliasSeeder::class);
    $fake = fakeFoodSource();
    $fake->configured = false;

    $catalog = app(FoodCatalog::class);
    expect($catalog->isSourceConfigured())->toBeFalse()
        ->and(FoodAlias::query()->where('normalized', 'cibuľa')->exists())->toBeTrue()
        ->and(fn () => $catalog->search('onion'))->toThrow(FoodSourceUnavailableException::class)
        ->and(app(AppSettings::class)->get(FoodCatalog::SETTING_LAST_SYNC))->toBeNull();
});
