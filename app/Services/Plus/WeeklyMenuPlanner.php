<?php

namespace App\Services\Plus;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\PersonRecipeExclusion;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealPlanningService;
use App\Services\PlanningCalendar;
use App\Services\RecipeSelectionService;
use App\Services\Selection\SelectionConfig;
use App\Services\Selection\SelectionEngine;
use App\Services\Selection\SelectionFilters;
use App\Services\Selection\SelectionInput;
use App\Services\Selection\WeightedPicker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Proposes a whole week with the existing selection engine (no AI): one weighted pick per slot, no recipe twice in
 * the week, slots that already hold a plan are left alone. The proposal lives in the UI until the user confirms;
 * only then ordinary meal plans are created.
 *
 * @phpstan-type Request array{week_start: string, days: list<int>, meal_types: list<string>, person_ids: list<int>, filters?: array<string, mixed>}
 * @phpstan-type Slot array{date: string, meal_type: string, recipe_id: int|null, title: string|null, reasons: list<string>, note: string|null, occupied: bool, existing_plan_id: int|null}
 */
class WeeklyMenuPlanner
{
    public function __construct(
        private RecipeSelectionService $selection,
        private MealPlanningService $planning,
        private SelectionConfig $config,
        private ?WeightedPicker $picker = null,
    ) {
        $this->picker ??= new WeightedPicker;
    }

    /**
     * Build the slots of the week and fill every free one.
     *
     * @param  Request  $request
     * @return list<Slot>
     */
    public function propose(Household $household, array $request): array
    {
        $request = $this->normalise($household, $request);
        $slots = $this->emptySlots($household, $request);

        return $this->fill($household, $request, $slots, array_keys(array_filter($slots, fn ($s) => ! $s['occupied'])));
    }

    /**
     * Replace the pick of one slot; every other slot stays as it is.
     *
     * @param  Request  $request
     * @param  list<Slot>  $slots
     * @return list<Slot>
     */
    public function reroll(Household $household, array $request, array $slots, int $index): array
    {
        $request = $this->normalise($household, $request);
        if (! isset($slots[$index]) || $slots[$index]['occupied']) {
            return $slots;
        }

        $previous = $slots[$index]['recipe_id'];
        $slots[$index] = array_merge($slots[$index], ['recipe_id' => null, 'title' => null, 'reasons' => [], 'note' => null]);

        return $this->fill($household, $request, $slots, [$index], $previous !== null ? [$previous] : []);
    }

    /**
     * Turn the accepted proposal into meal plans in one transaction. Recipes archived or excluded meanwhile are
     * skipped and reported, never planned silently.
     *
     * @param  list<Slot>  $slots
     * @param  list<int>  $personIds
     * @return array{created: list<MealPlan>, skipped: list<string>}
     */
    public function confirm(Household $household, array $slots, array $personIds, ?int $servings, ?User $by): array
    {
        $personIds = $this->verifiedPersonIds($household, $personIds);
        if ($personIds === []) {
            throw new InvalidArgumentException('Vyber aspoň jedného stravníka.');
        }

        return DB::transaction(function () use ($household, $slots, $personIds, $servings, $by) {
            $created = [];
            $skipped = [];

            foreach ($slots as $slot) {
                if ($slot['occupied'] || $slot['recipe_id'] === null) {
                    continue;
                }

                $recipe = Recipe::query()->where('household_id', $household->id)->find($slot['recipe_id']);
                if ($recipe === null || $recipe->isArchived() || $this->excludedForAny($recipe, $personIds)) {
                    $skipped[] = $slot['title'] ?? ('#'.$slot['recipe_id']);

                    continue;
                }

                $created[] = $this->planning->create($household, $recipe, [
                    'mode' => 'date',
                    'scheduled_date' => $slot['date'],
                    'meal_type' => $slot['meal_type'],
                    'servings' => $servings,
                    'person_ids' => $personIds,
                ], $by);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * @param  Request  $request
     * @param  list<Slot>  $slots
     * @param  list<int>  $indexes  slots to fill, in order
     * @param  list<int>  $avoid  recipe ids not to pick (the previous pick of a rerolled slot)
     * @return list<Slot>
     */
    private function fill(Household $household, array $request, array $slots, array $indexes, array $avoid = []): array
    {
        $calendar = new PlanningCalendar($household->timezone);
        $weekStart = $calendar->date($request['week_start']);
        $weekEnd = $weekStart->addDays(6);
        $filters = SelectionFilters::fromArray($request['filters'] ?? []);
        $people = Person::query()->where('household_id', $household->id)->whereIn('id', $request['person_ids'])->pluck('name', 'id')->all();

        $candidates = $this->selection->loadCandidates($household, $request['person_ids'], $weekEnd);
        $engine = new SelectionEngine($this->config);

        // Nothing twice in the week: neither two proposed slots nor a recipe the household already planned this week.
        $taken = array_merge(
            $avoid,
            $this->plannedThisWeek($household, $weekStart, $weekEnd),
            array_values(array_filter(array_map(fn ($s) => $s['recipe_id'], $slots))),
        );

        foreach ($indexes as $index) {
            $slot = $slots[$index];
            $mealType = $slot['meal_type'] === 'any' ? null : MealType::from($slot['meal_type']);
            $input = new SelectionInput(
                personIds: $request['person_ids'],
                personNames: $people,
                mealType: $mealType,
                referenceDay: $calendar->date($slot['date']),
                filters: $filters,
                weekStart: $weekStart,
            );

            $result = $engine->run($candidates, $input);
            $weights = [];
            $byId = [];
            foreach ($result->scored as $scored) {
                if (! in_array($scored->id, $taken, true)) {
                    $weights[$scored->id] = $scored->weight;
                    $byId[$scored->id] = $scored;
                }
            }

            $picked = $this->picker->pick($weights);
            if ($picked === null || ! isset($byId[$picked])) {
                $slots[$index] = array_merge($slot, ['note' => $this->emptyReason(count($result->scored), $result->softExclusionCounts(), count($candidates))]);

                continue;
            }

            $taken[] = $picked;
            $slots[$index] = array_merge($slot, [
                'recipe_id' => $picked,
                'title' => $byId[$picked]->title,
                'reasons' => $byId[$picked]->reasons,
                'note' => null,
            ]);
        }

        return $slots;
    }

    /**
     * One slot per chosen day and meal type; slots already planned are marked occupied with the existing plan.
     *
     * @param  Request  $request
     * @return list<Slot>
     */
    private function emptySlots(Household $household, array $request): array
    {
        $calendar = new PlanningCalendar($household->timezone);
        $weekStart = $calendar->date($request['week_start']);

        $existing = MealPlan::query()
            ->where('household_id', $household->id)
            ->where('status', PlanStatus::Planned)
            ->whereBetween('scheduled_date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->with('recipe:id,title')
            ->get();

        $slots = [];
        foreach ($request['days'] as $offset) {
            $date = $weekStart->addDays($offset)->toDateString();
            foreach ($request['meal_types'] as $type) {
                $plan = $existing->first(fn (MealPlan $p) => $p->scheduled_date?->toDateString() === $date
                    && ($type === 'any' || $p->meal_type?->value === $type));

                $slots[] = [
                    'date' => $date,
                    'meal_type' => $type,
                    'recipe_id' => $plan?->recipe_id,
                    'title' => $plan?->recipe?->title,
                    'reasons' => [],
                    'note' => $plan ? 'Už naplánované' : null,
                    'occupied' => $plan !== null,
                    'existing_plan_id' => $plan?->id,
                ];
            }
        }

        return $slots;
    }

    /** @return list<int> */
    private function plannedThisWeek(Household $household, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        return array_values(array_map('intval', MealPlan::query()
            ->where('household_id', $household->id)
            ->where('status', PlanStatus::Planned)
            ->where(function ($q) use ($weekStart, $weekEnd) {
                $q->whereBetween('scheduled_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                    ->orWhere('week_start_date', $weekStart->toDateString());
            })
            ->pluck('recipe_id')
            ->all()));
    }

    /**
     * @param  array<string, int>  $softCounts
     */
    private function emptyReason(int $scoredCount, array $softCounts, int $candidateCount): string
    {
        if ($candidateCount === 0) {
            return 'Domácnosť nemá žiadne aktívne recepty.';
        }
        if ($scoredCount === 0 && $softCounts !== []) {
            return 'Filtre vylúčili všetky recepty ('.array_sum($softCounts).').';
        }
        if ($scoredCount === 0) {
            return 'Pre týchto stravníkov nezostal žiadny vhodný recept.';
        }

        return 'Všetky vhodné recepty už sú v tomto týždni použité.';
    }

    /**
     * @param  Request  $request
     * @return Request
     */
    private function normalise(Household $household, array $request): array
    {
        $request['person_ids'] = $this->verifiedPersonIds($household, $request['person_ids']);
        if ($request['person_ids'] === []) {
            throw new InvalidArgumentException('Vyber aspoň jedného stravníka.');
        }

        $days = array_values(array_unique(array_filter(array_map('intval', $request['days']), fn (int $d) => $d >= 0 && $d <= 6)));
        sort($days);
        if ($days === []) {
            throw new InvalidArgumentException('Vyber aspoň jeden deň.');
        }
        $request['days'] = $days;

        $types = array_values(array_unique(array_filter($request['meal_types'], fn ($t) => $t === 'any' || MealType::tryFrom((string) $t) !== null)));
        if ($types === []) {
            throw new InvalidArgumentException('Vyber aspoň jeden typ jedla.');
        }
        $order = ['breakfast' => 0, 'lunch' => 1, 'dinner' => 2, 'any' => 3];
        usort($types, fn ($a, $b) => $order[$a] <=> $order[$b]);
        $request['meal_types'] = $types;

        $calendar = new PlanningCalendar($household->timezone);
        $request['week_start'] = $calendar->weekStartOf($calendar->date($request['week_start']))->toDateString();

        return $request;
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    private function verifiedPersonIds(Household $household, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return array_values(array_map('intval', Person::query()->where('household_id', $household->id)->whereIn('id', $ids)->pluck('id')->all()));
    }

    /** @param  list<int>  $personIds */
    private function excludedForAny(Recipe $recipe, array $personIds): bool
    {
        return PersonRecipeExclusion::query()->where('recipe_id', $recipe->id)->whereIn('person_id', $personIds)->exists();
    }

    /**
     * Recipes of a proposal for the UI (covers, minutes), keyed by id.
     *
     * @param  list<Slot>  $slots
     * @return Collection<int, Recipe>
     */
    public function recipesOf(Household $household, array $slots): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($s) => $s['recipe_id'], $slots))));

        return Recipe::query()->where('household_id', $household->id)->whereIn('id', $ids)->with('cover')->get()->keyBy('id');
    }
}
