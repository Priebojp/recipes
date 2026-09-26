<?php

namespace Tests\Support;

use App\Enums\FoodPreparationState;
use App\Models\FoodSourceRecord;
use App\Services\Food\FoodDataSource;
use App\Services\Food\FoodRecordData;
use App\Services\Food\FoodSearchHit;
use App\Services\Food\FoodSourceUnavailableException;

/**
 * In-memory provider: answers with the fixtures it was given and records every call.
 */
class FakeFoodDataSource implements FoodDataSource
{
    /** @var array<string, FoodRecordData> */
    public array $records = [];

    /** @var list<string> */
    public array $fetched = [];

    /** @var list<string> */
    public array $searched = [];

    public bool $configured = true;

    public function __construct(
        /** @var array<string, FoodRecordData> */
        array $records = [],
        /** @var array<string, list<FoodSearchHit>> */
        public array $searchResults = [],
    ) {
        foreach ($records as $record) {
            $this->records[$record->externalId] = $record;
        }
    }

    /**
     * A record with the values that matter for the calculations; anything not given is unknown (null).
     *
     * @param  array<string, float|null>  $nutrients
     * @param  list<array{unit: string, grams: float, description: string}>  $portions
     */
    public static function record(string $externalId, string $name, array $nutrients = [], array $portions = [], ?FoodPreparationState $state = null): FoodRecordData
    {
        return new FoodRecordData(
            externalId: $externalId,
            name: $name,
            license: 'CC0-1.0',
            basis: FoodSourceRecord::BASIS_100G,
            preparationState: $state ?? FoodPreparationState::fromDescription($name),
            nutrients: array_merge(['energy_kcal' => null, 'energy_kj' => null, 'protein_g' => null, 'carbohydrate_g' => null, 'fat_g' => null, 'fiber_g' => null], $nutrients),
            carbohydrateMethod: array_key_exists('carbohydrate_g', $nutrients) ? 'by_difference' : null,
            portions: $portions,
            snapshot: ['provider' => 'usda_fdc', 'fdc_id' => $externalId, 'description' => $name, 'fake' => true],
        );
    }

    public function add(FoodRecordData $record): self
    {
        $this->records[$record->externalId] = $record;

        return $this;
    }

    public function provider(): string
    {
        return FoodSourceRecord::PROVIDER_USDA;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function search(string $query, int $limit = 10): array
    {
        $this->guard();
        $this->searched[] = $query;

        return array_slice($this->searchResults[$query] ?? [], 0, $limit);
    }

    public function fetch(string $externalId): ?FoodRecordData
    {
        $this->guard();
        $this->fetched[] = $externalId;

        return $this->records[$externalId] ?? null;
    }

    private function guard(): void
    {
        if (! $this->configured) {
            throw new FoodSourceUnavailableException('USDA FoodData Central nie je nakonfigurované – chýba USDA_FDC_API_KEY.');
        }
    }
}
