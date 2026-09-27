<?php

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;
use App\Models\IngredientLine;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Food\FoodDataSource;
use App\Services\Food\FoodMappingService;
use App\Services\Food\FoodSearchHit;
use App\Services\Food\IngredientMatcher;
use Database\Seeders\FoodAliasSeeder;
use Tests\Support\FakeFoodDataSource;

function ingredient(Recipe $recipe, string $name, ?string $amount, ?string $unit, ?string $text = null): IngredientLine
{
    return $recipe->ingredients()->create([
        'position' => (int) $recipe->ingredients()->max('position') + 1,
        'name' => $name,
        'numeric_amount' => $amount,
        'text_amount' => $text,
        'unit' => $unit,
    ]);
}

function seededRecord(string $externalId): FoodSourceRecord
{
    return FoodSourceRecord::query()->where('external_id', $externalId)->firstOrFail();
}

beforeEach(function () {
    $this->seed(FoodAliasSeeder::class);
});

it('offers raw and cooked rice as distinct records for the same written name, both at the written grams', function () {
    $recipe = Recipe::factory()->create();
    $line = ingredient($recipe, 'Ryža', '100', 'g');

    $candidates = app(IngredientMatcher::class)->propose($line)->candidates;

    expect($candidates)->toHaveCount(2)
        ->and(collect($candidates)->map(fn ($c) => $c->preparationState)->all())->toEqualCanonicalizing([FoodPreparationState::Raw, FoodPreparationState::Cooked])
        ->and(collect($candidates)->map(fn ($c) => $c->record->external_id)->unique())->toHaveCount(2)
        ->and($candidates[0]->grams)->toBe(100.0)
        ->and($candidates[1]->grams)->toBe(100.0)
        ->and($candidates[0]->gramsOrigin)->toBe(FoodGramsOrigin::UnitConversion)
        ->and($candidates[0]->matchedAlias)->toBe('ryža');
});

it('converts mass units directly and pieces, spoons and volumes only through a confirmed conversion of that food', function () {
    $recipe = Recipe::factory()->create();
    $matcher = app(IngredientMatcher::class);
    $oil = seededRecord('171025');
    $milk = seededRecord('171265');
    $egg = seededRecord('171287');
    $potato = seededRecord('170026');

    $spoon = ingredient($recipe, 'olej', '1', 'PL');
    $millilitres = ingredient($recipe, 'mlieko', '2', 'dl');
    $pieces = ingredient($recipe, 'vajcia', '2', null);
    $kilograms = ingredient($recipe, 'zemiaky', '1.5', 'kg');

    expect($matcher->resolveGrams($kilograms, $potato))->toMatchArray(['grams' => 1500.0, 'origin' => FoodGramsOrigin::UnitConversion, 'reason' => null])
        ->and($matcher->resolveGrams($spoon, $oil)['grams'])->toBeNull()
        ->and($matcher->resolveGrams($spoon, $oil)['reason'])->toContain('prevod jednotky „PL“')
        ->and($matcher->resolveGrams($millilitres, $milk)['reason'])->toContain('hustota')
        ->and($matcher->resolveGrams($pieces, $egg)['reason'])->toContain('„ks“');

    $oil->conversions()->create(['unit' => 'PL', 'grams' => 13.5, 'source' => FoodUnitConversion::SOURCE_MANUAL, 'confirmed_at' => now()]);
    $milk->conversions()->create(['unit' => 'ml', 'grams' => 1.03, 'source' => FoodUnitConversion::SOURCE_LABEL, 'confirmed_at' => now()]);
    $egg->conversions()->create(['unit' => 'ks', 'grams' => 50, 'source' => FoodUnitConversion::SOURCE_MANUAL, 'confirmed_at' => now()]);

    expect($matcher->resolveGrams($spoon, $oil->fresh())['grams'])->toBe(13.5)
        ->and($matcher->resolveGrams($millilitres, $milk->fresh())['grams'])->toBe(206.0)
        ->and($matcher->resolveGrams($pieces, $egg->fresh()))->toMatchArray(['grams' => 100.0, 'origin' => FoodGramsOrigin::UnitConversion])
        ->and($matcher->resolveGrams($pieces, $egg->fresh())['conversion']->unit)->toBe('ks');
});

it('leaves "podľa chuti", unknown units and unknown names unresolved instead of guessing', function () {
    $recipe = Recipe::factory()->create();
    $matcher = app(IngredientMatcher::class);

    $taste = ingredient($recipe, 'soľ', null, null, 'podľa chuti');
    $unknownUnit = ingredient($recipe, 'cukor', '1', 'hrsť');
    $unknownName = ingredient($recipe, 'xylitolový sirup', '20', 'g');

    expect($matcher->resolveGrams($taste, seededRecord('173468'))['reason'])->toContain('podľa chuti')
        ->and($matcher->resolveGrams($unknownUnit, seededRecord('169655'))['reason'])->toContain('hrsť')
        ->and($matcher->propose($unknownName)->candidates)->toBe([]);
});

it('matches through a bracketed note and a word of the name, ranking the exact alias first', function () {
    $recipe = Recipe::factory()->create();
    $matcher = app(IngredientMatcher::class);

    $noted = ingredient($recipe, 'Cibuľa (nakrájaná nadrobno)', '1', 'ks');
    $partial = ingredient($recipe, 'hladká múka na zahustenie', '2', 'PL');

    expect($matcher->propose($noted)->best()?->record->external_id)->toBe('170000')
        ->and($matcher->propose($partial)->candidates[0]->matchedAlias)->toBe('hladká múka')
        ->and($matcher->propose($partial)->candidates[0]->score)->toBe(2)
        ->and($matcher->propose(ingredient($recipe, 'múka', '1', 'kg'))->candidates[0]->score)->toBe(10);
});

it('asks the source only for names the dictionary does not know and never turns a hit into a record by itself', function () {
    $fake = new FakeFoodDataSource([], ['tofu' => [new FoodSearchHit('172475', 'Tofu, raw, firm', 'Legumes')]]);
    app()->instance(FoodDataSource::class, $fake);
    $recipe = Recipe::factory()->create();

    $known = app(IngredientMatcher::class)->propose(ingredient($recipe, 'cibuľa', '1', 'ks'), searchSource: true);
    $unknown = app(IngredientMatcher::class)->propose(ingredient($recipe, 'tofu', '200', 'g'), searchSource: true);

    expect($known->sourceHits)->toBe([])
        ->and($unknown->candidates)->toBe([])
        ->and($unknown->sourceHits[0]->externalId)->toBe('172475')
        ->and($fake->searched)->toBe(['tofu'])
        ->and(FoodSourceRecord::query()->where('external_id', '172475')->exists())->toBeFalse();
});

it('proposes a mapping per line, keeps what a person decided and records why a line stays unresolved', function () {
    $recipe = Recipe::factory()->create();
    $service = app(FoodMappingService::class);
    $user = User::factory()->create();

    $flour = ingredient($recipe, 'hladká múka', '250', 'g');
    $oil = ingredient($recipe, 'olej', '2', 'PL');
    $unknown = ingredient($recipe, 'xylitolový sirup', '20', 'g');

    $mappings = $service->propose($recipe->fresh());

    expect($mappings)->toHaveCount(3)
        ->and($mappings[$flour->id]->status)->toBe(FoodMappingStatus::Suggested)
        ->and($mappings[$flour->id]->grams)->toBe('250.00')
        ->and($mappings[$flour->id]->grams_origin)->toBe(FoodGramsOrigin::UnitConversion)
        ->and($mappings[$oil->id]->status)->toBe(FoodMappingStatus::Unresolved)
        ->and($mappings[$oil->id]->food_source_record_id)->toBe(seededRecord('171025')->id)
        ->and($mappings[$oil->id]->unresolved_reason)->toContain('prevod')
        ->and($mappings[$unknown->id]->status)->toBe(FoodMappingStatus::Unresolved)
        ->and($mappings[$unknown->id]->food_source_record_id)->toBeNull()
        ->and($mappings[$unknown->id]->unresolved_reason)->toContain('xylitolový sirup');

    $confirmed = $service->confirm($oil, seededRecord('171413'), FoodPreparationState::Raw, 27.0, FoodGramsOrigin::Estimated, $user);
    $rejected = $service->reject($unknown, $user);
    expect($confirmed->status)->toBe(FoodMappingStatus::Confirmed)
        ->and($confirmed->grams)->toBe('27.00')
        ->and($confirmed->grams_origin)->toBe(FoodGramsOrigin::Estimated)
        ->and($confirmed->confirmed_by)->toBe($user->id)
        ->and($rejected->status)->toBe(FoodMappingStatus::Rejected);

    $flour->update(['numeric_amount' => 300]);
    $again = $service->propose($recipe->fresh());
    expect($again[$flour->id]->grams)->toBe('300.00')
        ->and($again[$oil->id]->status)->toBe(FoodMappingStatus::Confirmed)
        ->and($again[$oil->id]->food_source_record_id)->toBe(seededRecord('171413')->id)
        ->and($again[$unknown->id]->status)->toBe(FoodMappingStatus::Rejected);

    $names = $service->unresolvedNames();
    expect($names)->toHaveCount(1)
        ->and($names[0])->toMatchArray(['name' => 'xylitolový sirup', 'count' => 1, 'reasons' => ['návrh odmietnutý']]);
});

it('confirms a food without grams as unresolved rather than inventing an amount and rejects impossible grams', function () {
    $recipe = Recipe::factory()->create();
    $service = app(FoodMappingService::class);
    $user = User::factory()->create();
    $spoon = ingredient($recipe, 'olej', '1', 'PL');
    $oil = seededRecord('171025');

    $mapping = $service->confirm($spoon, $oil, FoodPreparationState::Raw, null, null, $user);
    expect($mapping->status)->toBe(FoodMappingStatus::Unresolved)
        ->and($mapping->food_source_record_id)->toBe($oil->id)
        ->and($mapping->grams)->toBeNull()
        ->and($mapping->unresolved_reason)->toContain('prevod');

    $oil->conversions()->create(['unit' => 'PL', 'grams' => 13.5, 'source' => FoodUnitConversion::SOURCE_MANUAL, 'confirmed_at' => now()]);
    $resolved = $service->confirm($spoon, $oil->fresh(), FoodPreparationState::Raw, null, null, $user);
    expect($resolved->status)->toBe(FoodMappingStatus::Confirmed)
        ->and($resolved->grams)->toBe('13.50')
        ->and($resolved->conversion_id)->not->toBeNull();

    expect(fn () => $service->confirm($spoon, $oil, FoodPreparationState::Raw, 0.0, FoodGramsOrigin::UserEntered, $user))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->confirm($spoon, $oil, FoodPreparationState::Raw, 10.0, FoodGramsOrigin::UnitConversion, $user))->toThrow(InvalidArgumentException::class);
});
