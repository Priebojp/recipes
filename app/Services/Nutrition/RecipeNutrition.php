<?php

namespace App\Services\Nutrition;

use App\Enums\FoodMappingStatus;
use App\Models\IngredientFoodMapping;
use App\Models\IngredientLine;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Food\FoodMappingService;
use App\Services\RecipeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "Vypočítať výživové hodnoty" flow of a recipe: propose and refresh the ingredient mappings (stage 9), turn
 * them into calculator components, run the pure calculation and store the result against the recipe's current
 * revision. Nothing here calls AI or the usage ledger, and a Free household may use it.
 */
class RecipeNutrition
{
    public function __construct(
        private FoodMappingService $mappings,
        private NutritionCalculator $calculator,
        private RecipeService $recipes,
    ) {}

    /**
     * The latest run for the recipe, stale or not.
     */
    public function current(Recipe $recipe): ?NutritionCalculation
    {
        return NutritionCalculation::query()->where('recipe_id', $recipe->id)->orderByDesc('id')->first();
    }

    /**
     * Step 1: a mapping for every line – recipe-derived grams of confirmed lines follow the current amounts, lines
     * nobody decided on get a proposal. Confirmed and rejected decisions are never overwritten.
     *
     * @return Collection<int, IngredientFoodMapping> keyed by ingredient line ID
     */
    public function prepare(Recipe $recipe): Collection
    {
        $this->mappings->refreshRecipeAmounts($recipe);

        return $this->mappings->propose($recipe);
    }

    /**
     * Lines a person must decide before the result means anything: a food with real energy and no grams. Salt-like
     * foods and lines without any food are reported as assumptions or missing instead of blocking.
     *
     * @param  array<int, float>  $shares  line ID => 0..1
     * @return Collection<int, IngredientLine>
     */
    public function linesNeedingAmount(Recipe $recipe, array $shares = []): Collection
    {
        $recipe->loadMissing('ingredients.foodMapping.record');

        return $recipe->ingredients->filter(function (IngredientLine $line) use ($shares) {
            $mapping = $line->foodMapping;
            if ($mapping === null || $mapping->record === null || $mapping->grams !== null || $mapping->status === FoodMappingStatus::Rejected) {
                return false;
            }
            if (($shares[$line->id] ?? 1.0) <= 0.0) {
                return false;
            }

            return ! $this->calculator->isNegligible($this->component($line, $mapping, 1.0));
        })->values();
    }

    /**
     * Step 3: accept the remaining proposals as they stand, calculate and store. Grams and sources are frozen in the
     * stored components; later changes of the recipe, the mapping or the food database never rewrite them.
     *
     * @param  array<int, float>  $shares  line ID => 0..1 (missing = the whole line is eaten)
     */
    public function calculate(Recipe $recipe, User $by, ?float $finalWeightG = null, array $shares = []): NutritionCalculation
    {
        return DB::transaction(function () use ($recipe, $by, $finalWeightG, $shares) {
            $this->prepare($recipe);
            $recipe->load('ingredients.foodMapping.record');

            foreach ($recipe->ingredients as $line) {
                $mapping = $line->foodMapping;
                if ($mapping !== null && $mapping->status === FoodMappingStatus::Suggested) {
                    $mapping->update(['status' => FoodMappingStatus::Confirmed, 'confirmed_by' => $by->id, 'confirmed_at' => now()]);
                }
            }

            $components = $recipe->ingredients
                ->map(fn (IngredientLine $line) => $this->component($line, $line->foodMapping, (float) ($shares[$line->id] ?? 1.0)))
                ->values()
                ->all();

            $result = $this->calculator->calculate($components, $recipe->base_servings, $finalWeightG);

            $revisionId = $recipe->active_revision_id ?? $this->recipes->snapshotRevision($recipe->fresh(['ingredients', 'steps', 'mealTypes']), $by, 'nutrition')->id;

            return NutritionCalculation::create([
                'recipe_id' => $recipe->id,
                'recipe_revision_id' => $revisionId,
                'calculation_version' => NutritionCalculator::VERSION,
                'servings' => $result->servings,
                'final_weight_g' => $result->finalWeightG,
                'totals' => $result->totals,
                'per_serving' => $result->perServing,
                'per_100g' => $result->per100g,
                'completeness' => $result->completeness,
                'components' => $result->components,
                'missing' => $result->missing,
                'assumptions' => $result->assumptions,
                'created_by' => $by->id,
            ]);
        });
    }

    /**
     * Whether a newer revision changed anything the calculation depends on: ingredient names, amounts, units or the
     * base servings. Notes, steps and the title do not invalidate a result.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $next
     */
    public static function inputsChanged(array $previous, array $next): bool
    {
        $lines = fn (array $snapshot) => array_map(
            fn (array $line) => [trim((string) ($line['name'] ?? '')), $line['numeric_amount'] ?? null, $line['text_amount'] ?? null, $line['unit'] ?? null],
            (array) ($snapshot['ingredients'] ?? []),
        );

        return ($previous['base_servings'] ?? null) !== ($next['base_servings'] ?? null) || $lines($previous) !== $lines($next);
    }

    /**
     * Mark every current run of the recipe as computed for an older version. Runs are kept for history.
     */
    public static function markStale(Recipe $recipe): int
    {
        return NutritionCalculation::query()->where('recipe_id', $recipe->id)->whereNull('stale_at')->update(['stale_at' => now()]);
    }

    private function component(IngredientLine $line, ?IngredientFoodMapping $mapping, float $share): NutritionComponent
    {
        $record = $mapping?->status === FoodMappingStatus::Rejected ? null : $mapping?->record;
        $amount = trim($line->displayAmount().' '.(string) $line->unit);

        return new NutritionComponent(
            name: $line->name,
            amount: $amount,
            grams: $record === null || $mapping->grams === null ? null : (float) $mapping->grams,
            gramsOrigin: $record === null ? null : $mapping->grams_origin,
            nutrients: $record?->nutrients(),
            source: $record === null ? null : [
                'record_id' => $record->id,
                'provider' => $record->provider,
                'external_id' => $record->external_id,
                'name' => $record->name,
                'name_sk' => $record->name_sk,
                'license' => $record->license,
                'basis' => $record->basis,
                'preparation_state' => ($mapping->preparation_state ?? $record->preparation_state)->value,
            ],
            preparationState: $record === null ? null : ($mapping->preparation_state ?? $record->preparation_state),
            share: $share,
            unresolvedReason: $mapping?->status === FoodMappingStatus::Rejected ? __('Návrh potraviny bol odmietnutý a iná nebola zvolená.') : $mapping?->unresolved_reason,
            lineId: $line->id,
        );
    }
}
