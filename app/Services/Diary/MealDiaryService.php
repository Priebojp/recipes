<?php

namespace App\Services\Diary;

use App\Enums\ConsumptionSource;
use App\Enums\ManualNutritionOrigin;
use App\Enums\NutritionCompleteness;
use App\Enums\PortionMode;
use App\Models\ConsumptionNutritionSnapshot;
use App\Models\FoodSourceRecord;
use App\Models\Household;
use App\Models\MealAnalysis;
use App\Models\MealConsumption;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Nutrition\NutritionFormatter;
use App\Services\Nutrition\RecipeNutrition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The private "Zjedol som" diary (v2.1 stage 12). An entry comes from a recipe with a stored calculation, from a
 * confirmed photo analysis or from a meal typed by hand; it records what was actually eaten and freezes the
 * numbers in a snapshot. Corrections are pure arithmetic over that snapshot – no AI, no usage, no Plus – and
 * nothing here creates or reads a cooking event.
 */
class MealDiaryService
{
    public function __construct(
        private ConsumptionCalculator $calculator,
        private RecipeNutrition $nutrition,
    ) {}

    /**
     * Log a serving (or part of one) of a recipe. Needs the recipe's latest calculation; a stale one is allowed
     * with a visible note so the person can decide to recalculate first.
     */
    public function logRecipe(User $by, Household $household, Recipe $recipe, ConsumptionPortion $portion, CarbonInterface $eatenAt, string $timezone, ?string $note = null, bool $withNutrition = true): MealConsumption
    {
        if ((int) $recipe->household_id !== (int) $household->id) {
            throw new InvalidArgumentException(__('Recept nepatrí do tejto domácnosti.'));
        }

        $calculation = $this->nutrition->current($recipe);
        if ($withNutrition && $calculation === null) {
            throw new InvalidArgumentException(__('Recept ešte nemá vypočítané výživové hodnoty. Vypočítaj ich na stránke receptu alebo zapíš jedlo bez kalórií.'));
        }

        $basis = $withNutrition ? $this->basisForCalculation($calculation) : null;

        return $this->store($by, $household, [
            'source' => ConsumptionSource::Recipe,
            'recipe_id' => $recipe->id,
            'recipe_revision_id' => $calculation !== null ? $calculation->recipe_revision_id : $recipe->active_revision_id,
            'nutrition_calculation_id' => $withNutrition ? $calculation->id : null,
            'title_snapshot' => $recipe->title,
        ], $basis, $portion, $eatenAt, $timezone, $note);
    }

    /**
     * Log a confirmed photo analysis of the same person. One saved without calories gives an entry without them.
     */
    public function logAnalysis(User $by, MealAnalysis $analysis, ConsumptionPortion $portion, CarbonInterface $eatenAt, string $timezone, ?string $note = null): MealConsumption
    {
        if ((int) $analysis->user_id !== (int) $by->id) {
            throw new InvalidArgumentException(__('Analýza nepatrí tebe.'));
        }
        if (! $analysis->isConfirmed()) {
            throw new InvalidArgumentException(__('Do denníka sa dá zapísať iba potvrdené jedlo.'));
        }

        $household = $analysis->household()->firstOrFail();

        return $this->store($by, $household, [
            'source' => ConsumptionSource::Analysis,
            'meal_analysis_id' => $analysis->id,
            'title_snapshot' => $analysis->dish_name ?? __('Jedlo z fotky'),
        ], $this->basisForAnalysis($analysis), $portion, $eatenAt, $timezone, $note);
    }

    /**
     * Log a meal typed by hand. Values are optional; when given, their origin (a label, a guess) is stored with
     * them and shown next to every number.
     *
     * @param  array<string, float|null>|null  $values  per what was eaten; null = without calories
     */
    public function logManual(User $by, Household $household, string $title, ?array $values, ?ManualNutritionOrigin $origin, CarbonInterface $eatenAt, string $timezone, ?string $note = null): MealConsumption
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException(__('Zadaj názov jedla.'));
        }

        $basis = $this->basisForManual($title, $values, $origin);

        return $this->store($by, $household, [
            'source' => ConsumptionSource::Manual,
            'title_snapshot' => mb_substr($title, 0, 200),
        ], $basis, ConsumptionPortion::fraction(1.0), $eatenAt, $timezone, $note, $basis === null ? null : $origin);
    }

    /**
     * Correct how much was eaten: a new revision computed from the basis frozen in the previous snapshot. The
     * recipe, the mapping and the food database are not consulted, so a later change of theirs cannot leak in.
     */
    public function adjust(MealConsumption $entry, ConsumptionPortion $portion): ConsumptionNutritionSnapshot
    {
        $previous = $entry->snapshot;
        if ($previous === null || ! $previous->hasNutrition()) {
            throw new InvalidArgumentException(__('Záznam bez kalórií nemá čo prepočítať.'));
        }

        $result = $this->calculator->calculate(ConsumptionBasis::fromSnapshot($previous), $portion);

        return DB::transaction(function () use ($entry, $previous, $result, $portion) {
            $entry->update($this->portionAttributes($portion));

            return $this->storeSnapshot($entry, $previous->revision + 1, $result, $portion, $previous->manual_origin);
        });
    }

    /**
     * Change when it was eaten or the note; the numbers stay as they are.
     */
    public function reschedule(MealConsumption $entry, CarbonInterface $eatenAt, string $timezone, ?string $note = null): MealConsumption
    {
        $entry->update([
            'eaten_at' => CarbonImmutable::instance($eatenAt)->setTimezone(config('app.timezone')),
            'timezone' => $timezone,
            'eaten_on' => CarbonImmutable::instance($eatenAt)->timezone($timezone)->toDateString(),
            'note' => $this->clean($note),
        ]);

        return $entry;
    }

    public function delete(MealConsumption $entry): void
    {
        $entry->delete();
    }

    /**
     * Entries of a person for one local day, newest first.
     *
     * @return Collection<int, MealConsumption>
     */
    public function entriesOn(User $user, CarbonInterface $day): Collection
    {
        return MealConsumption::query()
            ->where('user_id', $user->id)
            ->whereDate('eaten_on', $day->toDateString())
            ->with('snapshot')
            ->orderByDesc('eaten_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The day's sum over entries with values. A nutrient unknown in any entry, a partial entry or an entry without
     * values makes the day a partial sum – shown as such, never as a smaller "complete" number.
     *
     * @param  iterable<MealConsumption>  $entries
     * @return array{totals: array<string, float|null>|null, partial: bool, without_values: int, entries: int}
     */
    public function dayTotals(iterable $entries): array
    {
        $totals = array_fill_keys(FoodSourceRecord::NUTRIENTS, null);
        $partial = false;
        $withoutValues = 0;
        $count = 0;
        $withValues = 0;

        foreach ($entries as $entry) {
            $count++;
            $snapshot = $entry->snapshot;
            if ($snapshot === null || ! $snapshot->hasNutrition()) {
                $withoutValues++;
                $partial = true;

                continue;
            }
            $withValues++;
            $partial = $partial || $snapshot->isPartial();
            foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
                $value = $snapshot->totals[$nutrient] ?? null;
                if ($value === null) {
                    $partial = $partial || in_array($nutrient, NutritionCalculator::CORE_NUTRIENTS, true);

                    continue;
                }
                $totals[$nutrient] = ($totals[$nutrient] ?? 0.0) + (float) $value;
            }
        }

        return [
            'totals' => $withValues === 0 ? null : array_map(fn (?float $value) => $value === null ? null : round($value, 3), $totals),
            'partial' => $partial,
            'without_values' => $withoutValues,
            'entries' => $count,
        ];
    }

    /**
     * The frozen basis of a stored recipe calculation: one unit = one serving.
     */
    public function basisForCalculation(NutritionCalculation $calculation): ConsumptionBasis
    {
        $servings = $calculation->servings !== null && $calculation->servings > 0 ? $calculation->servings : 1;
        $assumptions = $calculation->assumptions;
        if ($calculation->servings === null || $calculation->servings <= 0) {
            $assumptions[] = __('Recept nemá počet porcií – jedna porcia je celý recept.');
        }
        if ($calculation->isStale()) {
            $assumptions[] = __('Výpočet je z :date pre staršiu verziu receptu.', ['date' => $calculation->created_at?->timezone(config('recipes.default_timezone'))->format('j. n. Y')]);
        }

        return new ConsumptionBasis(
            meta: [
                'kind' => ConsumptionSource::Recipe->value,
                'recipe_id' => $calculation->recipe_id,
                'recipe_revision_id' => $calculation->recipe_revision_id,
                'nutrition_calculation_id' => $calculation->id,
                'calculation_version' => $calculation->calculation_version,
                'calculated_at' => $calculation->created_at?->toIso8601String(),
                'stale' => $calculation->isStale(),
                'servings' => $calculation->servings,
                'final_weight_g' => $calculation->final_weight_g === null ? null : (float) $calculation->final_weight_g,
            ],
            components: $calculation->components,
            totals: $calculation->totals,
            completeness: $calculation->completeness,
            missing: $calculation->missing,
            assumptions: $assumptions,
            divisor: $servings,
            unitGrams: $calculation->final_weight_g === null ? null : round((float) $calculation->final_weight_g / $servings, 2),
        );
    }

    /**
     * The frozen basis of a confirmed photo: one unit = the whole plate. Null when it was saved without calories.
     */
    public function basisForAnalysis(MealAnalysis $analysis): ?ConsumptionBasis
    {
        $nutrition = $analysis->nutrition;
        if ($nutrition === null) {
            return null;
        }

        return new ConsumptionBasis(
            meta: [
                'kind' => ConsumptionSource::Analysis->value,
                'meal_analysis_id' => $analysis->id,
                'ai_job_id' => $analysis->ai_job_id,
                'calculation_version' => (int) ($nutrition['calculation_version'] ?? NutritionCalculator::VERSION),
                'calculated_at' => $nutrition['calculated_at'] ?? null,
                'confirmed_at' => $analysis->confirmed_at?->toIso8601String(),
            ],
            components: array_values((array) ($nutrition['components'] ?? [])),
            totals: $nutrition['totals'] ?? null,
            completeness: NutritionCompleteness::from((string) ($nutrition['completeness'] ?? NutritionCompleteness::Partial->value)),
            missing: array_values((array) ($nutrition['missing'] ?? [])),
            assumptions: array_values((array) ($nutrition['assumptions'] ?? [])),
            divisor: 1,
            unitGrams: isset($nutrition['included_grams']) && (float) $nutrition['included_grams'] > 0 ? (float) $nutrition['included_grams'] : null,
        );
    }

    /**
     * Values typed by hand for what was eaten. A core nutrient left empty makes the entry a partial sum.
     *
     * @param  array<string, float|null>|null  $values
     */
    public function basisForManual(string $title, ?array $values, ?ManualNutritionOrigin $origin): ?ConsumptionBasis
    {
        if ($values === null) {
            return null;
        }
        $clean = [];
        $any = false;
        foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
            $value = $values[$nutrient] ?? null;
            $clean[$nutrient] = $value === null ? null : (float) $value;
            $any = $any || $clean[$nutrient] !== null;
        }
        if (! $any) {
            return null;
        }
        if ($origin === null) {
            throw new InvalidArgumentException(__('Uveď, odkiaľ hodnoty pochádzajú (etiketa alebo odhad).'));
        }

        $missing = [];
        foreach (NutritionCalculator::CORE_NUTRIENTS as $nutrient) {
            if ($clean[$nutrient] === null) {
                $missing[] = ['name' => $title, 'reason' => __('Hodnota „:nutrient“ nebola zadaná.', ['nutrient' => NutritionFormatter::label($nutrient)])];
            }
        }

        return new ConsumptionBasis(
            meta: ['kind' => ConsumptionSource::Manual->value, 'origin' => $origin->value, 'title' => $title],
            components: [],
            totals: $clean,
            completeness: $missing === [] ? NutritionCompleteness::Complete : NutritionCompleteness::Partial,
            missing: $missing,
            assumptions: [__('Hodnoty zadané ručne – zdroj: :origin.', ['origin' => $origin->label()])],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(User $by, Household $household, array $attributes, ?ConsumptionBasis $basis, ConsumptionPortion $portion, CarbonInterface $eatenAt, string $timezone, ?string $note, ?ManualNutritionOrigin $origin = null): MealConsumption
    {
        $result = $basis === null ? null : $this->calculator->calculate($basis, $portion);

        return DB::transaction(function () use ($by, $household, $attributes, $result, $portion, $eatenAt, $timezone, $note, $origin) {
            $entry = MealConsumption::create(array_merge([
                'user_id' => $by->id,
                'household_id' => $household->id,
                // Eloquent stores the wall-clock of the instance it is given: normalise to the app zone first.
                'eaten_at' => CarbonImmutable::instance($eatenAt)->setTimezone(config('app.timezone')),
                'timezone' => $timezone,
                'eaten_on' => CarbonImmutable::instance($eatenAt)->timezone($timezone)->toDateString(),
                'note' => $this->clean($note),
            ], $this->portionAttributes($portion), $attributes));

            $this->storeSnapshot($entry, 1, $result, $portion, $origin);

            return $entry->fresh(['snapshot']);
        });
    }

    private function storeSnapshot(MealConsumption $entry, int $revision, ?ConsumptionResult $result, ConsumptionPortion $portion, ?ManualNutritionOrigin $origin): ConsumptionNutritionSnapshot
    {
        return ConsumptionNutritionSnapshot::create(array_merge([
            'meal_consumption_id' => $entry->id,
            'revision' => $revision,
            'calculation_version' => ConsumptionCalculator::VERSION,
            'component_shares' => $portion->mode === PortionMode::PerComponent ? $portion->componentShares : null,
            'basis' => $result === null ? ['meta' => ['kind' => $entry->source->value, 'without_nutrition' => true]] : $result->basis->toArray(),
            'totals' => $result?->totals,
            'completeness' => $result?->completeness,
            'components' => $result === null ? [] : $result->components,
            'missing' => $result === null ? [] : $result->missing,
            'assumptions' => $result === null ? [] : $result->assumptions,
            'manual_origin' => $origin,
            'created_at' => now(),
        ], $this->portionAttributes($portion)));
    }

    /**
     * @return array{portion_mode: PortionMode, portion_fraction: float|null, grams: float|null}
     */
    private function portionAttributes(ConsumptionPortion $portion): array
    {
        return [
            'portion_mode' => $portion->mode,
            'portion_fraction' => $portion->mode === PortionMode::Fraction ? $portion->fraction : null,
            'grams' => $portion->mode === PortionMode::Grams ? $portion->grams : null,
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
