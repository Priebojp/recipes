<?php

namespace App\Services;

use App\Enums\MealType;
use App\Enums\PlanMode;
use App\Enums\PlanStatus;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MealPlanningService
{
    public function __construct(private CookingHistoryService $history) {}

    /**
     * @param  array{mode: string, scheduled_date?: string|null, week_start_date?: string|null, meal_type?: string|null, servings?: int|string|null, person_ids?: list<int>}  $data
     */
    public function create(Household $household, Recipe $recipe, array $data, ?User $by): MealPlan
    {
        if ($recipe->household_id !== $household->id) {
            throw new InvalidArgumentException('Recept nepatrí do tejto domácnosti.');
        }

        $term = $this->normaliseTerm($household, $data);
        $people = $this->verifiedPeople($household, $data['person_ids'] ?? []);

        return DB::transaction(function () use ($household, $recipe, $data, $term, $people, $by) {
            $plan = MealPlan::create([
                'household_id' => $household->id,
                'recipe_id' => $recipe->id,
                'mode' => $term['mode'],
                'scheduled_date' => $term['scheduled_date'],
                'week_start_date' => $term['week_start_date'],
                'meal_type' => isset($data['meal_type']) && $data['meal_type'] !== '' && $data['meal_type'] !== 'any' ? MealType::from($data['meal_type']) : null,
                'servings' => $this->servings($data['servings'] ?? null),
                'status' => PlanStatus::Planned,
                'created_by' => $by?->id,
            ]);
            $plan->people()->sync($people->pluck('id'));

            return $plan->fresh(['people', 'recipe']);
        });
    }

    /**
     * Move the plan to another term and/or change diners/servings. Never touches history.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MealPlan $plan, array $data): MealPlan
    {
        $household = $plan->household;

        return DB::transaction(function () use ($plan, $data, $household) {
            if (isset($data['mode'])) {
                $term = $this->normaliseTerm($household, $data);
                $plan->fill($term);
            }
            if (array_key_exists('servings', $data)) {
                $plan->servings = $this->servings($data['servings']);
            }
            if (array_key_exists('meal_type', $data)) {
                $plan->meal_type = $data['meal_type'] !== null && $data['meal_type'] !== '' && $data['meal_type'] !== 'any' ? MealType::from($data['meal_type']) : null;
            }
            $plan->save();

            if (array_key_exists('person_ids', $data)) {
                $plan->people()->sync($this->verifiedPeople($household, $data['person_ids'] ?? [])->pluck('id'));
            }

            return $plan->fresh(['people', 'recipe']);
        });
    }

    public function cancel(MealPlan $plan): MealPlan
    {
        if ($plan->status === PlanStatus::Cooked) {
            throw new InvalidArgumentException('Uvarený plán sa nedá zrušiť; najprv odvolaj potvrdenie uvarenia.');
        }
        $plan->update(['status' => PlanStatus::Cancelled]);

        return $plan;
    }

    public function restore(MealPlan $plan): MealPlan
    {
        if ($plan->status === PlanStatus::Cancelled) {
            $plan->update(['status' => PlanStatus::Planned]);
        }

        return $plan;
    }

    /**
     * Confirm the plan was cooked: creates the cooking event and flips the status in one transaction.
     * Idempotent per plan thanks to the unique active_plan_key.
     *
     * @param  array{cooked_on?: string|null, servings?: int|string|null, note?: string|null, person_ids?: list<int>|null}  $data
     */
    public function markCooked(MealPlan $plan, array $data, ?User $by, ?string $idempotencyKey = null): MealPlan
    {
        return DB::transaction(function () use ($plan, $data, $by, $idempotencyKey) {
            $plan = MealPlan::query()->lockForUpdate()->with(['recipe', 'people'])->findOrFail($plan->id);

            if ($plan->status === PlanStatus::Cooked && $plan->activeCookingEvent()->exists()) {
                return $plan;
            }

            $recipe = $plan->recipe;
            $personIds = array_values(array_map('intval', $data['person_ids'] ?? $plan->people->pluck('id')->all()));

            $this->history->record($plan->household, $recipe, [
                'cooked_on' => $data['cooked_on'] ?? $plan->scheduled_date?->toDateString(),
                'servings' => $data['servings'] ?? $plan->servings,
                'note' => $data['note'] ?? null,
                'person_ids' => $personIds,
                'meal_plan_id' => $plan->id,
            ], $by, $idempotencyKey);

            $plan->update(['status' => PlanStatus::Cooked]);

            return $plan->fresh(['people', 'recipe']);
        });
    }

    /**
     * Fix a mistaken "cooked": voids the linked event and returns the plan to planned.
     */
    public function undoCooked(MealPlan $plan): MealPlan
    {
        return DB::transaction(function () use ($plan) {
            $plan = MealPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $event = $plan->activeCookingEvent()->first();
            if ($event !== null) {
                $this->history->void($event);
            }
            $plan->update(['status' => PlanStatus::Planned]);

            return $plan->fresh();
        });
    }

    /**
     * Existing active plans of the recipe that collide with the chosen day (used for the UI warning).
     *
     * @return Collection<int, MealPlan>
     */
    public function collisions(Recipe $recipe, ?CarbonImmutable $day, int $windowDays = 3): Collection
    {
        if ($day === null) {
            return collect();
        }

        $from = $day->subDays($windowDays)->toDateString();
        $to = $day->addDays($windowDays)->toDateString();

        return MealPlan::query()
            ->where('recipe_id', $recipe->id)
            ->where('status', PlanStatus::Planned)
            ->where(function ($q) use ($from, $to, $day) {
                $q->whereBetween('scheduled_date', [$from, $to])
                    ->orWhere(function ($q) use ($day) {
                        $weekStart = $day->startOfWeek(CarbonImmutable::MONDAY);
                        $q->where('mode', PlanMode::Week)->whereBetween('week_start_date', [$weekStart->subWeek()->toDateString(), $weekStart->addWeek()->toDateString()]);
                    });
            })
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{mode: PlanMode, scheduled_date: string|null, week_start_date: string|null}
     */
    private function normaliseTerm(Household $household, array $data): array
    {
        $calendar = new PlanningCalendar($household->timezone);
        $mode = PlanMode::from($data['mode']);

        return match ($mode) {
            PlanMode::Date => (function () use ($data, $calendar) {
                $date = $data['scheduled_date'] ?? null;
                if (! $date) {
                    throw new InvalidArgumentException('Pre plán na konkrétny deň treba zadať dátum.');
                }

                return ['mode' => PlanMode::Date, 'scheduled_date' => $calendar->date($date)->toDateString(), 'week_start_date' => null];
            })(),
            PlanMode::Week => (function () use ($data, $calendar) {
                $week = $data['week_start_date'] ?? null;
                if (! $week) {
                    throw new InvalidArgumentException('Pre týždenný plán treba zadať týždeň.');
                }

                return ['mode' => PlanMode::Week, 'scheduled_date' => null, 'week_start_date' => $calendar->weekStartOf($calendar->date($week))->toDateString()];
            })(),
            PlanMode::Someday => ['mode' => PlanMode::Someday, 'scheduled_date' => null, 'week_start_date' => null],
        };
    }

    /**
     * @param  array<int|string>  $ids
     * @return Collection<int, Person>
     */
    private function verifiedPeople(Household $household, array $ids): Collection
    {
        return Person::query()->where('household_id', $household->id)->whereIn('id', array_map('intval', $ids))->get();
    }

    private function servings(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
