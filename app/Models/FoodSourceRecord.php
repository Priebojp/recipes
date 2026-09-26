<?php

namespace App\Models;

use App\Enums\FoodPreparationState;
use Database\Factories\FoodSourceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One food of one provider, with the values per basis as the provider published them and the original payload
 * as a snapshot. A nullable nutrient is unknown – it is never replaced by zero.
 *
 * @property int $id
 * @property string $provider
 * @property string $external_id
 * @property string $license
 * @property string $name
 * @property string|null $name_sk
 * @property FoodPreparationState $preparation_state
 * @property string $basis
 * @property string|null $energy_kcal
 * @property string|null $energy_kj
 * @property string|null $protein_g
 * @property string|null $carbohydrate_g
 * @property string|null $carbohydrate_method
 * @property string|null $fat_g
 * @property string|null $fiber_g
 * @property array<string, mixed>|null $source_snapshot
 * @property Carbon|null $fetched_at
 * @property bool $is_curated
 * @property string|null $sync_warning
 */
#[Fillable([
    'provider', 'external_id', 'license', 'name', 'name_sk', 'preparation_state', 'basis', 'energy_kcal', 'energy_kj',
    'protein_g', 'carbohydrate_g', 'carbohydrate_method', 'fat_g', 'fiber_g', 'source_snapshot', 'fetched_at', 'is_curated', 'sync_warning',
])]
class FoodSourceRecord extends Model
{
    /** @use HasFactory<FoodSourceRecordFactory> */
    use HasFactory;

    public const PROVIDER_USDA = 'usda_fdc';

    public const BASIS_100G = '100g';

    public const BASIS_100ML = '100ml';

    public const BASIS_SERVING = 'serving';

    /** Nutrient columns in display order; every one is nullable. */
    public const NUTRIENTS = ['energy_kcal', 'energy_kj', 'protein_g', 'carbohydrate_g', 'fat_g', 'fiber_g'];

    protected function casts(): array
    {
        return [
            'preparation_state' => FoodPreparationState::class,
            'energy_kcal' => 'decimal:2',
            'energy_kj' => 'decimal:2',
            'protein_g' => 'decimal:2',
            'carbohydrate_g' => 'decimal:2',
            'fat_g' => 'decimal:2',
            'fiber_g' => 'decimal:2',
            'source_snapshot' => 'array',
            'fetched_at' => 'datetime',
            'is_curated' => 'boolean',
        ];
    }

    /** @return HasMany<FoodAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(FoodAlias::class);
    }

    /** @return HasMany<FoodUnitConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(FoodUnitConversion::class);
    }

    /** @return HasMany<IngredientFoodMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(IngredientFoodMapping::class);
    }

    /**
     * Label for people: the curated Slovak name, otherwise the provider's description.
     */
    public function displayName(): string
    {
        return $this->name_sk !== null && trim($this->name_sk) !== '' ? $this->name_sk : $this->name;
    }

    /**
     * Values per basis as floats, null where the provider has no value.
     *
     * @return array{energy_kcal: float|null, energy_kj: float|null, protein_g: float|null, carbohydrate_g: float|null, fat_g: float|null, fiber_g: float|null}
     */
    public function nutrients(): array
    {
        $values = [];
        foreach (self::NUTRIENTS as $nutrient) {
            $values[$nutrient] = $this->{$nutrient} === null ? null : (float) $this->{$nutrient};
        }

        return $values;
    }
}
