<?php

namespace App\Services\Food;

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\IngredientFoodMapping;
use App\Models\IngredientLine;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Persists the link between ingredient lines and foods. Proposals never overwrite what a person confirmed or
 * rejected; a confirmation without a usable amount stays unresolved rather than getting a hidden number.
 */
class FoodMappingService
{
    public function __construct(private IngredientMatcher $matcher) {}

    /**
     * (Re)propose a mapping for every line of the recipe that nobody has decided on yet.
     *
     * @return Collection<int, IngredientFoodMapping> keyed by ingredient line ID
     */
    public function propose(Recipe $recipe): Collection
    {
        $recipe->loadMissing('ingredients.foodMapping');
        $result = new Collection;

        foreach ($recipe->ingredients as $line) {
            $existing = $line->foodMapping;
            if ($existing !== null && in_array($existing->status, [FoodMappingStatus::Confirmed, FoodMappingStatus::Rejected], true)) {
                $result->put($line->id, $existing);

                continue;
            }

            $best = $this->matcher->propose($line)->best();
            $attributes = $best === null
                ? [
                    'food_source_record_id' => null, 'preparation_state' => null, 'grams' => null, 'grams_origin' => null, 'conversion_id' => null,
                    'status' => FoodMappingStatus::Unresolved, 'unresolved_reason' => 'V slovníku nie je zhoda pre „'.mb_substr(trim($line->name), 0, 80).'“.',
                ]
                : [
                    'food_source_record_id' => $best->record->id,
                    'preparation_state' => $best->preparationState,
                    'grams' => $best->grams,
                    'grams_origin' => $best->gramsOrigin,
                    'conversion_id' => $best->conversion?->id,
                    'status' => $best->isResolved() ? FoodMappingStatus::Suggested : FoodMappingStatus::Unresolved,
                    'unresolved_reason' => $best->unresolvedReason,
                ];

            $mapping = IngredientFoodMapping::query()->updateOrCreate(
                ['ingredient_line_id' => $line->id],
                [...$attributes, 'confirmed_by' => null, 'confirmed_at' => null],
            );
            $result->put($line->id, $mapping);
        }

        return $result;
    }

    /**
     * Confirmed mappings whose grams came from the recipe amount follow the amount when it changes (300 g instead
     * of 250 g). Typed or estimated grams are a person's word and stay. A line whose amount no longer converts
     * (unit changed to "podľa chuti") keeps its food but becomes unresolved with the reason.
     *
     * @return int number of mappings changed
     */
    public function refreshRecipeAmounts(Recipe $recipe): int
    {
        $recipe->loadMissing('ingredients.foodMapping.record.conversions');
        $changed = 0;

        foreach ($recipe->ingredients as $line) {
            $mapping = $line->foodMapping;
            if ($mapping === null || $mapping->record === null || $mapping->status === FoodMappingStatus::Rejected) {
                continue;
            }
            if ($mapping->grams !== null && $mapping->grams_origin !== FoodGramsOrigin::UnitConversion) {
                continue;
            }

            $resolved = $this->matcher->resolveGrams($line, $mapping->record);
            $attributes = [
                'grams' => $resolved['grams'],
                'grams_origin' => $resolved['origin'],
                'conversion_id' => $resolved['conversion']?->id,
                'unresolved_reason' => $resolved['reason'],
                'status' => match (true) {
                    $resolved['grams'] === null => FoodMappingStatus::Unresolved,
                    $mapping->confirmed_at !== null => FoodMappingStatus::Confirmed,
                    default => FoodMappingStatus::Suggested,
                },
            ];

            $mapping->fill($attributes);
            if ($mapping->isDirty()) {
                $mapping->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * A person chose the food and its state. Grams come from the recipe amount (through a mass unit or a confirmed
     * conversion) unless they typed or estimated them; without either the mapping stays unresolved.
     */
    public function confirm(IngredientLine $line, FoodSourceRecord $record, FoodPreparationState $state, ?float $grams, ?FoodGramsOrigin $origin, User $by): IngredientFoodMapping
    {
        if ($grams !== null) {
            if ($grams <= 0) {
                throw new InvalidArgumentException(__('Gramáž musí byť väčšia ako nula.'));
            }
            $origin ??= FoodGramsOrigin::UserEntered;
            if ($origin === FoodGramsOrigin::UnitConversion) {
                throw new InvalidArgumentException(__('Ručne zadaná gramáž musí byť označená ako zadaná alebo odhad.'));
            }
            $resolved = ['grams' => round($grams, 2), 'origin' => $origin, 'conversion' => null, 'reason' => null];
        } else {
            $resolved = $this->matcher->resolveGrams($line, $record);
        }

        return IngredientFoodMapping::query()->updateOrCreate(
            ['ingredient_line_id' => $line->id],
            [
                'food_source_record_id' => $record->id,
                'preparation_state' => $state,
                'grams' => $resolved['grams'],
                'grams_origin' => $resolved['origin'],
                'conversion_id' => $resolved['conversion']?->id,
                'status' => $resolved['grams'] === null ? FoodMappingStatus::Unresolved : FoodMappingStatus::Confirmed,
                'unresolved_reason' => $resolved['reason'],
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ],
        );
    }

    /**
     * The proposal is wrong and no better food is known. Kept as a row so it is not proposed again.
     */
    public function reject(IngredientLine $line, User $by): IngredientFoodMapping
    {
        return IngredientFoodMapping::query()->updateOrCreate(
            ['ingredient_line_id' => $line->id],
            [
                'food_source_record_id' => null, 'preparation_state' => null, 'grams' => null, 'grams_origin' => null, 'conversion_id' => null,
                'status' => FoodMappingStatus::Rejected, 'unresolved_reason' => null,
                'confirmed_by' => $by->id, 'confirmed_at' => now(),
            ],
        );
    }

    /**
     * Ingredient names that could not be mapped, aggregated for the curator. Names only – no recipes, no households.
     *
     * @return list<array{name: string, count: int, reasons: list<string>}>
     */
    public function unresolvedNames(int $limit = 50): array
    {
        $rows = IngredientFoodMapping::query()
            ->whereIn('status', [FoodMappingStatus::Unresolved, FoodMappingStatus::Rejected])
            ->join('ingredient_lines', 'ingredient_lines.id', '=', 'ingredient_food_mappings.ingredient_line_id')
            ->orderByDesc('ingredient_food_mappings.updated_at')
            ->limit(2000)
            ->toBase()
            ->get(['ingredient_lines.name', 'ingredient_lines.unit', 'ingredient_food_mappings.status', 'ingredient_food_mappings.unresolved_reason']);

        $groups = [];
        foreach ($rows as $row) {
            $key = FoodAlias::normalize((string) $row->name);
            if ($key === '') {
                continue;
            }
            $groups[$key] ??= ['name' => trim((string) $row->name), 'count' => 0, 'reasons' => []];
            $groups[$key]['count']++;
            $reason = FoodMappingStatus::tryFrom((string) $row->status) === FoodMappingStatus::Rejected ? __('návrh odmietnutý') : (string) $row->unresolved_reason;
            if ($reason !== '' && ! in_array($reason, $groups[$key]['reasons'], true) && count($groups[$key]['reasons']) < 3) {
                $groups[$key]['reasons'][] = $reason;
            }
        }

        usort($groups, fn (array $a, array $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return array_slice($groups, 0, $limit);
    }
}
