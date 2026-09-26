<?php

use App\Enums\FoodPreparationState;
use App\Services\Food\FoodSourceUnavailableException;
use App\Services\Food\UsdaFoodDataCentral;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

function usdaFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/usda/'.$name.'.json')), true);
}

beforeEach(function () {
    config()->set('services.usda.key', 'test-usda-key');
    config()->set('services.usda.base_url', 'https://api.nal.usda.gov/fdc/v1');
    RateLimiter::clear('food-source:usda_fdc');
});

it('stores the provider values per 100 g, the carbohydrate method, the licence and the cup and spoon portions of a fetched food', function () {
    Http::preventStrayRequests();
    Http::fake(['api.nal.usda.gov/fdc/v1/food/169756*' => Http::response(usdaFixture('food_169756'))]);

    $data = app(UsdaFoodDataCentral::class)->fetch('169756');

    expect($data)->not->toBeNull()
        ->and($data->externalId)->toBe('169756')
        ->and($data->name)->toBe('Rice, white, long-grain, regular, raw, unenriched')
        ->and($data->license)->toBe('CC0-1.0')
        ->and($data->basis)->toBe('100g')
        ->and($data->preparationState)->toBe(FoodPreparationState::Raw)
        ->and($data->nutrients)->toBe(['energy_kcal' => 365.0, 'energy_kj' => 1527.0, 'protein_g' => 7.13, 'carbohydrate_g' => 79.95, 'fat_g' => 0.66, 'fiber_g' => 1.3])
        ->and($data->carbohydrateMethod)->toBe('by_difference')
        ->and($data->portions)->toBe([
            ['unit' => 'šálka', 'grams' => 185.0, 'description' => '1 cup'],
            ['unit' => 'PL', 'grams' => 12.5, 'description' => '1 tbsp'],
        ])
        ->and($data->snapshot['description'])->toBe('Rice, white, long-grain, regular, raw, unenriched')
        ->and($data->snapshot['energy_nutrient_id'])->toBe(1008);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/food/169756') && str_contains($request->url(), 'api_key=test-usda-key') && $request['format'] === 'full');
});

it('leaves a nutrient unknown instead of zero when the provider has no value and prefers a medium piece over a large one', function () {
    $data = app(UsdaFoodDataCentral::class)->parse([
        'fdcId' => 1, 'description' => 'Egg, whole, raw, fresh',
        'foodNutrients' => [['nutrient' => ['id' => 2048], 'amount' => 148.0], ['nutrient' => ['id' => 1003], 'amount' => 12.4]],
        'foodPortions' => [
            ['gramWeight' => 50, 'amount' => 1, 'modifier' => 'large', 'measureUnit' => ['name' => 'undetermined']],
            ['gramWeight' => 44, 'amount' => 1, 'modifier' => 'medium', 'measureUnit' => ['name' => 'undetermined']],
            ['gramWeight' => 243, 'amount' => 1, 'modifier' => 'cup (4.86 large eggs)', 'measureUnit' => ['name' => 'undetermined']],
            ['gramWeight' => 0, 'amount' => 1, 'modifier' => 'serving', 'measureUnit' => ['name' => 'undetermined']],
        ],
    ]);

    expect($data->nutrients['energy_kcal'])->toBe(148.0)
        ->and($data->snapshot['energy_nutrient_id'])->toBe(2048)
        ->and($data->nutrients['fat_g'])->toBeNull()
        ->and($data->nutrients['carbohydrate_g'])->toBeNull()
        ->and($data->carbohydrateMethod)->toBeNull()
        ->and(collect($data->portions)->pluck('grams', 'unit')->all())->toBe(['ks' => 44.0, 'šálka' => 243.0]);
});

it('returns search hits with the provider id and category and asks only for the configured data types', function () {
    Http::preventStrayRequests();
    Http::fake(['api.nal.usda.gov/fdc/v1/foods/search*' => Http::response(usdaFixture('search_rice'))]);

    $hits = app(UsdaFoodDataCentral::class)->search('rice white long-grain', 2);

    expect($hits)->toHaveCount(2)
        ->and($hits[0]->externalId)->toBe('169708')
        ->and($hits[0]->name)->toBe('Rice, white, long-grain, parboiled, enriched, cooked')
        ->and($hits[0]->category)->toBe('Cereal Grains and Pasta')
        ->and($hits[0]->dataType)->toBe('SR Legacy');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/foods/search') && $request['query'] === 'rice white long-grain' && (string) $request['pageSize'] === '2' && $request['dataType'] === 'SR Legacy,Foundation' && str_contains($request->url(), 'api_key=test-usda-key'));
});

it('reports a missing food as null and refuses to fetch a malformed id without calling the provider', function () {
    Http::preventStrayRequests();
    Http::fake(['api.nal.usda.gov/fdc/v1/food/999999999*' => Http::response(['error' => ['code' => 'NOT_FOUND']], 404)]);

    $source = app(UsdaFoodDataCentral::class);

    expect($source->fetch('999999999'))->toBeNull()
        ->and($source->fetch('DROP TABLE'))->toBeNull();

    Http::assertSentCount(1);
});

it('is unavailable with a clear message and never calls the provider when the key is missing', function () {
    config()->set('services.usda.key', '');
    Http::preventStrayRequests();
    Http::fake();

    $source = app(UsdaFoodDataCentral::class);

    expect($source->isConfigured())->toBeFalse()
        ->and(fn () => $source->search('onion'))->toThrow(FoodSourceUnavailableException::class, 'USDA_FDC_API_KEY')
        ->and(fn () => $source->fetch('170000'))->toThrow(FoodSourceUnavailableException::class);

    Http::assertNothingSent();
});

it('retries a transient provider error and reports a persistent one as unavailable', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.nal.usda.gov/fdc/v1/food/170000*' => Http::sequence()->push(['message' => 'busy'], 503)->push(usdaFixture('food_169756')),
        'api.nal.usda.gov/fdc/v1/food/170393*' => Http::response(['message' => 'down'], 500),
    ]);

    $source = app(UsdaFoodDataCentral::class);

    expect($source->fetch('170000')?->name)->toBe('Rice, white, long-grain, regular, raw, unenriched')
        ->and(fn () => $source->fetch('170393'))->toThrow(FoodSourceUnavailableException::class, 'chybou 500');

    Http::assertSentCount(2 + 4); // one success after a retry, then four attempts (first + three retries) that all fail
});

it('stops before the provider limit and serves repeated lookups from the cache', function () {
    config()->set('recipes.food.rate_limit_per_hour', 1);
    Http::preventStrayRequests();
    Http::fake(['api.nal.usda.gov/fdc/v1/food/169756*' => Http::response(usdaFixture('food_169756'))]);

    $source = app(UsdaFoodDataCentral::class);

    expect($source->fetch('169756'))->not->toBeNull()
        ->and($source->fetch('169756'))->not->toBeNull() // cached: no request, no limiter hit
        ->and(fn () => $source->fetch('170000'))->toThrow(FoodSourceUnavailableException::class, 'limit');

    Http::assertSentCount(1);
});
