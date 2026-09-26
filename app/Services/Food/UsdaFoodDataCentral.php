<?php

namespace App\Services\Food;

use App\Enums\FoodPreparationState;
use App\Models\FoodSourceRecord;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * USDA FoodData Central (https://fdc.nal.usda.gov/api-guide). Data is CC0; the API allows 1 000 requests per
 * hour and IP, so every answer is cached and an application-side limiter stops well before that.
 * Values are per 100 g; carbohydrate is "by difference" (nutrient 1005) and is stored with that method.
 */
class UsdaFoodDataCentral implements FoodDataSource
{
    public const LICENSE = 'CC0-1.0';

    private const RATE_LIMIT_KEY = 'food-source:usda_fdc';

    /** FDC nutrient IDs (same in the full record and in search results). */
    private const NUTRIENT_ENERGY_KCAL = 1008;

    private const NUTRIENT_ENERGY_KCAL_ATWATER_SPECIFIC = 2048;

    private const NUTRIENT_ENERGY_KCAL_ATWATER_GENERAL = 2047;

    private const NUTRIENT_ENERGY_KJ = 1062;

    private const NUTRIENT_PROTEIN = 1003;

    private const NUTRIENT_CARBOHYDRATE_BY_DIFFERENCE = 1005;

    private const NUTRIENT_CARBOHYDRATE_BY_SUMMATION = 1050;

    private const NUTRIENT_FAT = 1004;

    private const NUTRIENT_FIBER = 1079;

    /**
     * USDA portion words → [canonical unit, preference]. "medium" beats "large" for a piece; anything else
     * (a "serving", "1 NLEA serving", "package") is left to the curator.
     */
    private const PORTION_UNITS = [
        'cup' => [FoodUnit::CUP, 0],
        'tbsp' => [FoodUnit::TABLESPOON, 0], 'tablespoon' => [FoodUnit::TABLESPOON, 0],
        'tsp' => [FoodUnit::TEASPOON, 0], 'teaspoon' => [FoodUnit::TEASPOON, 0],
        'medium' => [FoodUnit::PIECE, 0], 'large' => [FoodUnit::PIECE, 1], 'piece' => [FoodUnit::PIECE, 2], 'whole' => [FoodUnit::PIECE, 2], 'each' => [FoodUnit::PIECE, 3], 'small' => [FoodUnit::PIECE, 4],
        'clove' => ['strúčik', 0],
        'slice' => ['plátok', 0],
    ];

    public function provider(): string
    {
        return FoodSourceRecord::PROVIDER_USDA;
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.usda.key')) !== '';
    }

    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $payload = $this->cached('search:'.md5($query.'|'.$limit), fn () => $this->request()->get('/foods/search', [
            'query' => $query,
            'dataType' => implode(',', (array) config('recipes.food.search_data_types', ['SR Legacy'])),
            'pageSize' => $limit,
            'pageNumber' => 1,
        ])->throw()->json());

        $hits = [];
        foreach ((array) ($payload['foods'] ?? []) as $food) {
            if (! isset($food['fdcId'], $food['description'])) {
                continue;
            }
            $hits[] = new FoodSearchHit((string) $food['fdcId'], (string) $food['description'], $food['foodCategory'] ?? null, $food['dataType'] ?? null);
        }

        return $hits;
    }

    public function fetch(string $externalId): ?FoodRecordData
    {
        $externalId = trim($externalId);
        if (! preg_match('/^\d{1,12}$/', $externalId)) {
            return null;
        }

        $payload = $this->cached('food:'.$externalId, function () use ($externalId) {
            $response = $this->request()->get('/food/'.$externalId, ['format' => 'full']);
            if ($response->notFound()) {
                return ['__missing' => true];
            }

            return $response->throw()->json();
        });

        if (! is_array($payload) || isset($payload['__missing']) || ! isset($payload['fdcId'], $payload['description'])) {
            return null;
        }

        return $this->parse($payload);
    }

    /**
     * Turn a full FDC food payload into the application's record data. Public so fixtures can be parsed in tests.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): FoodRecordData
    {
        $byId = [];
        foreach ((array) ($payload['foodNutrients'] ?? []) as $entry) {
            $id = $entry['nutrient']['id'] ?? $entry['nutrientId'] ?? null;
            $amount = $entry['amount'] ?? $entry['value'] ?? null;
            if ($id === null || $amount === null || ! is_numeric($amount)) {
                continue;
            }
            $byId[(int) $id] ??= (float) $amount;
        }

        $energySource = null;
        $energyKcal = null;
        foreach ([self::NUTRIENT_ENERGY_KCAL, self::NUTRIENT_ENERGY_KCAL_ATWATER_SPECIFIC, self::NUTRIENT_ENERGY_KCAL_ATWATER_GENERAL] as $candidate) {
            if (array_key_exists($candidate, $byId)) {
                $energySource = $candidate;
                $energyKcal = $byId[$candidate];
                break;
            }
        }

        $carbohydrate = null;
        $carbohydrateMethod = null;
        if (array_key_exists(self::NUTRIENT_CARBOHYDRATE_BY_DIFFERENCE, $byId)) {
            $carbohydrate = $byId[self::NUTRIENT_CARBOHYDRATE_BY_DIFFERENCE];
            $carbohydrateMethod = 'by_difference';
        } elseif (array_key_exists(self::NUTRIENT_CARBOHYDRATE_BY_SUMMATION, $byId)) {
            $carbohydrate = $byId[self::NUTRIENT_CARBOHYDRATE_BY_SUMMATION];
            $carbohydrateMethod = 'by_summation';
        }

        $description = (string) $payload['description'];

        return new FoodRecordData(
            externalId: (string) $payload['fdcId'],
            name: $description,
            license: self::LICENSE,
            basis: FoodSourceRecord::BASIS_100G,
            preparationState: FoodPreparationState::fromDescription($description),
            nutrients: [
                'energy_kcal' => $energyKcal,
                'energy_kj' => $byId[self::NUTRIENT_ENERGY_KJ] ?? null,
                'protein_g' => $byId[self::NUTRIENT_PROTEIN] ?? null,
                'carbohydrate_g' => $carbohydrate,
                'fat_g' => $byId[self::NUTRIENT_FAT] ?? null,
                'fiber_g' => $byId[self::NUTRIENT_FIBER] ?? null,
            ],
            carbohydrateMethod: $carbohydrateMethod,
            portions: $this->portions($payload),
            snapshot: [
                'provider' => $this->provider(),
                'fdc_id' => $payload['fdcId'],
                'description' => $description,
                'data_type' => $payload['dataType'] ?? null,
                'publication_date' => $payload['publicationDate'] ?? null,
                'ndb_number' => $payload['ndbNumber'] ?? null,
                'food_category' => $payload['foodCategory']['description'] ?? $payload['foodCategory'] ?? null,
                'energy_nutrient_id' => $energySource,
                'nutrients' => $byId,
                'food_portions' => $payload['foodPortions'] ?? [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{unit: string, grams: float, description: string}>
     */
    private function portions(array $payload): array
    {
        /** @var array<string, array{rank: int, portion: array{unit: string, grams: float, description: string}}> $best */
        $best = [];
        foreach ((array) ($payload['foodPortions'] ?? []) as $portion) {
            $grams = $portion['gramWeight'] ?? null;
            $amount = $portion['amount'] ?? 1;
            if (! is_numeric($grams) || (float) $grams <= 0 || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $measure = mb_strtolower(trim((string) ($portion['measureUnit']['name'] ?? '')));
            $modifier = mb_strtolower(trim((string) ($portion['modifier'] ?? '')));
            $label = $measure !== '' && $measure !== 'undetermined' ? $measure : $modifier;
            $word = preg_split('/[\s,(]+/', $label)[0] ?? '';
            if (! isset(self::PORTION_UNITS[$word])) {
                continue;
            }
            [$unit, $rank] = self::PORTION_UNITS[$word];
            if (isset($best[$unit]) && $best[$unit]['rank'] <= $rank) {
                continue;
            }

            $best[$unit] = ['rank' => $rank, 'portion' => [
                'unit' => $unit,
                'grams' => round((float) $grams / (float) $amount, 2),
                'description' => trim(($portion['amount'] ?? 1).' '.$label.' '.($portion['portionDescription'] ?? '')),
            ]];
        }

        return array_values(array_map(fn (array $entry) => $entry['portion'], $best));
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new FoodSourceUnavailableException('USDA FoodData Central nie je nakonfigurované – chýba USDA_FDC_API_KEY. Uložené záznamy fungujú, vyhľadávanie nových potravín je vypnuté.');
        }

        $limit = max(1, (int) config('recipes.food.rate_limit_per_hour', 900));
        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, $limit)) {
            throw new FoodSourceUnavailableException('Hodinový limit požiadaviek na USDA je vyčerpaný; skús to neskôr.');
        }
        RateLimiter::hit(self::RATE_LIMIT_KEY, 3600);

        $timeout = max(1, (int) config('recipes.food.timeout_seconds', 15));

        return Http::baseUrl((string) config('services.usda.base_url'))
            ->acceptJson()
            ->withQueryParameters(['api_key' => (string) config('services.usda.key')])
            ->connectTimeout(5)
            ->timeout($timeout)
            ->retry([200, 1000, 3000], 0, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429));
            }, throw: false);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function cached(string $key, Closure $callback): mixed
    {
        $hours = max(1, (int) config('recipes.food.cache_hours', 168));

        try {
            return Cache::remember('food-source:usda_fdc:'.$key, now()->addHours($hours), $callback);
        } catch (FoodSourceUnavailableException $e) {
            throw $e;
        } catch (RequestException $e) {
            throw new FoodSourceUnavailableException('USDA API odpovedalo chybou '.$e->response->status().'.', previous: $e);
        } catch (ConnectionException $e) {
            throw new FoodSourceUnavailableException('USDA API nie je dostupné (spojenie alebo časový limit).', previous: $e);
        }
    }
}
