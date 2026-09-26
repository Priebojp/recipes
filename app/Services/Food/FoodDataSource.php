<?php

namespace App\Services\Food;

/**
 * A provider of nutrition data. The application starts with USDA FoodData Central as the only implementation;
 * the interface exists so a second provider (Open Food Facts after a licence review, a manual label source)
 * can be added later without touching the matcher or the calculations.
 */
interface FoodDataSource
{
    /** Provider code stored on every record (FoodSourceRecord::PROVIDER_*). */
    public function provider(): string;

    /** Whether the provider can be called at all (API key present). Stored snapshots work without it. */
    public function isConfigured(): bool;

    /**
     * @return list<FoodSearchHit>
     *
     * @throws FoodSourceUnavailableException when the provider is not configured, rate-limited or failing
     */
    public function search(string $query, int $limit = 10): array;

    /**
     * The provider's full record, or null when the ID does not exist there.
     *
     * @throws FoodSourceUnavailableException
     */
    public function fetch(string $externalId): ?FoodRecordData;
}
