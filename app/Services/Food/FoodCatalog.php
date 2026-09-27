<?php

namespace App\Services\Food;

use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;
use App\Services\Admin\AppSettings;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * The application's copy of the provider's foods: import, refresh (sync) and lookup. Curated fields (Slovak name,
 * preparation state, curated flag) and manual conversions survive a refresh; ingredient mappings are never touched
 * here – they point at record IDs, and historical personal calculations keep their own snapshots (stages 10/12).
 */
class FoodCatalog
{
    public const SETTING_LAST_SYNC = 'food.sync.last';

    public function __construct(private FoodDataSource $source, private AppSettings $settings) {}

    public function provider(): string
    {
        return $this->source->provider();
    }

    public function isSourceConfigured(): bool
    {
        return $this->source->isConfigured();
    }

    /**
     * Provider search with the already stored record next to each hit.
     *
     * @return list<array{hit: FoodSearchHit, record: FoodSourceRecord|null}>
     *
     * @throws FoodSourceUnavailableException
     */
    public function search(string $query, int $limit = 10): array
    {
        $hits = $this->source->search($query, $limit);
        if ($hits === []) {
            return [];
        }

        $stored = FoodSourceRecord::query()
            ->where('provider', $this->provider())
            ->whereIn('external_id', array_map(fn (FoodSearchHit $hit) => $hit->externalId, $hits))
            ->get()
            ->keyBy('external_id');

        return array_map(fn (FoodSearchHit $hit) => ['hit' => $hit, 'record' => $stored->get($hit->externalId)], $hits);
    }

    /**
     * Fetch a provider record and store (or refresh) it. The ID must exist at the provider – nothing is ever
     * created from an unverified ID.
     *
     * @throws InvalidArgumentException when the provider has no such food
     * @throws FoodSourceUnavailableException
     */
    public function import(string $externalId): FoodSourceRecord
    {
        $data = $this->source->fetch($externalId);
        if ($data === null) {
            throw new InvalidArgumentException("Potravina s ID {$externalId} v zdroji {$this->provider()} neexistuje.");
        }

        return $this->store($data);
    }

    /**
     * A record for a reference coming from a client or from AI: an internal ID must exist in the database, a
     * provider ID must exist at the provider. Anything else is refused.
     *
     * @throws ModelNotFoundException|InvalidArgumentException|FoodSourceUnavailableException
     */
    public function resolve(int|string $reference): FoodSourceRecord
    {
        if (is_int($reference)) {
            return FoodSourceRecord::query()->findOrFail($reference);
        }

        $reference = trim($reference);
        if ($reference === '') {
            throw new InvalidArgumentException(__('Chýba odkaz na potravinu.'));
        }

        $existing = FoodSourceRecord::query()->where('provider', $this->provider())->where('external_id', $reference)->first();

        return $existing ?? $this->import($reference);
    }

    /**
     * Re-fetch one stored record. Returns what happened: updated, warning (kept, but the provider's description no
     * longer matches the curated expectation) or missing (the provider no longer has it).
     *
     * @throws FoodSourceUnavailableException
     */
    public function refresh(FoodSourceRecord $record, bool $dryRun = false): string
    {
        $data = $this->source->fetch($record->external_id);
        if ($data === null) {
            if (! $dryRun) {
                $record->update(['sync_warning' => 'Záznam v zdroji už neexistuje; hodnoty ostávajú zo snímky.']);
            }

            return 'missing';
        }

        $warning = $this->warningFor($record, $data);
        if (! $dryRun) {
            $this->store($data, $record);
        }

        return $warning === null ? 'updated' : 'warning';
    }

    /**
     * Refresh every stored record of the provider. Idempotent; with dryRun nothing is written (the provider is
     * still asked, which also warms the cache).
     *
     * @param  Closure(FoodSourceRecord, string): void|null  $each
     * @param  list<string>|null  $onlyExternalIds
     * @return array{checked: int, updated: int, warnings: int, missing: int, failed: int, dry_run: bool, at: string, error?: string}
     */
    public function sync(bool $dryRun = false, ?Closure $each = null, ?array $onlyExternalIds = null): array
    {
        $summary = ['checked' => 0, 'updated' => 0, 'warnings' => 0, 'missing' => 0, 'failed' => 0, 'dry_run' => $dryRun, 'at' => now()->toIso8601String()];

        $query = FoodSourceRecord::query()->where('provider', $this->provider())->orderBy('id');
        if ($onlyExternalIds !== null) {
            $query->whereIn('external_id', $onlyExternalIds);
        }

        foreach ($query->lazyById(100) as $record) {
            $summary['checked']++;
            try {
                $outcome = $this->refresh($record, $dryRun);
            } catch (FoodSourceUnavailableException $e) {
                // Provider gone away mid-run: stop, keep what was done, report clearly.
                $summary['failed']++;
                $summary['error'] = $e->getMessage();
                $each?->__invoke($record, 'failed');
                break;
            }
            $summary[match ($outcome) {
                'updated' => 'updated', 'warning' => 'warnings', default => 'missing'
            }]++;
            $each?->__invoke($record, $outcome);
        }

        if (! $dryRun) {
            $this->settings->set(self::SETTING_LAST_SYNC, $summary);
        }

        return $summary;
    }

    /**
     * Summary of the last real sync run, for the admin page.
     *
     * @return array<string, mixed>|null keys as sync() returns them
     */
    public function lastSync(): ?array
    {
        $value = $this->settings->get(self::SETTING_LAST_SYNC);

        return is_array($value) && isset($value['at']) ? $value : null;
    }

    /**
     * Write provider data into a record. Curated fields are kept on refresh; the seed's expectation (stored under
     * snapshot.seed) survives so a later sync can still compare against it.
     */
    private function store(FoodRecordData $data, ?FoodSourceRecord $existing = null): FoodSourceRecord
    {
        $existing ??= FoodSourceRecord::query()->where('provider', $this->provider())->where('external_id', $data->externalId)->first();

        $snapshot = $data->snapshot;
        if ($existing !== null && isset($existing->source_snapshot['seed'])) {
            $snapshot['seed'] = $existing->source_snapshot['seed'];
        }

        $attributes = [
            'license' => $data->license,
            'name' => $data->name,
            'basis' => $data->basis,
            'energy_kcal' => $data->nutrients['energy_kcal'],
            'energy_kj' => $data->nutrients['energy_kj'],
            'protein_g' => $data->nutrients['protein_g'],
            'carbohydrate_g' => $data->nutrients['carbohydrate_g'],
            'carbohydrate_method' => $data->carbohydrateMethod,
            'fat_g' => $data->nutrients['fat_g'],
            'fiber_g' => $data->nutrients['fiber_g'],
            'source_snapshot' => $snapshot,
            'fetched_at' => now(),
        ];

        if ($existing === null) {
            $record = FoodSourceRecord::create([
                ...$attributes,
                'provider' => $this->provider(),
                'external_id' => $data->externalId,
                'preparation_state' => $data->preparationState,
                'is_curated' => false,
                'sync_warning' => null,
            ]);
        } else {
            $existing->update([...$attributes, 'sync_warning' => $this->warningFor($existing, $data)]);
            $record = $existing;
        }

        foreach ($data->portions as $portion) {
            $conversion = $record->conversions()->where('unit', $portion['unit'])->first();
            if ($conversion === null) {
                $record->conversions()->create([
                    'unit' => $portion['unit'],
                    'grams' => $portion['grams'],
                    'source' => FoodUnitConversion::SOURCE_USDA_PORTION,
                    'note' => mb_substr($portion['description'], 0, 200),
                    'confirmed_at' => now(),
                ]);
            } elseif ($conversion->source === FoodUnitConversion::SOURCE_USDA_PORTION) {
                $conversion->update(['grams' => $portion['grams'], 'note' => mb_substr($portion['description'], 0, 200)]);
            }
        }

        return $record;
    }

    private function warningFor(FoodSourceRecord $record, FoodRecordData $data): ?string
    {
        $expected = $record->source_snapshot['seed']['expected_name'] ?? null;
        if (! is_string($expected) || $expected === '') {
            return null;
        }

        if (mb_strtolower(trim($expected)) === mb_strtolower(trim($data->name))) {
            return null;
        }

        return 'Názov v zdroji sa zmenil: „'.mb_substr($data->name, 0, 120).'“ (očakávané „'.mb_substr($expected, 0, 80).'“). Skontroluj priradenie.';
    }
}
