<?php

namespace App\Services;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Models\CookingEvent;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\PersonRecipeExclusion;
use App\Models\Recipe;
use App\Models\SelectionAction;
use App\Models\SelectionSession;
use App\Models\User;
use App\Services\Selection\RecipeCandidate;
use App\Services\Selection\SelectionConfig;
use App\Services\Selection\SelectionEngine;
use App\Services\Selection\SelectionFilters;
use App\Services\Selection\SelectionInput;
use App\Services\Selection\SelectionResult;
use App\Services\Selection\WeightedPicker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads candidates for a household, runs the pure engine and keeps the card session on the server.
 */
class RecipeSelectionService
{
    public function __construct(
        private SelectionConfig $config,
        private ?WeightedPicker $picker = null,
    ) {
        $this->picker ??= new WeightedPicker;
    }

    /**
     * Resolve the chosen term into a plan default and the reference day T.
     *
     * @return array{term: string, mode: string, scheduled_date: string|null, week_start_date: string|null, reference_day: string}
     */
    public function resolveTerm(Household $household, string $term, ?string $date = null): array
    {
        $calendar = new PlanningCalendar($household->timezone);

        return match ($term) {
            'today' => ['term' => $term, 'mode' => 'date', 'scheduled_date' => $calendar->today()->toDateString(), 'week_start_date' => null, 'reference_day' => $calendar->today()->toDateString()],
            'tomorrow' => ['term' => $term, 'mode' => 'date', 'scheduled_date' => $calendar->tomorrow()->toDateString(), 'week_start_date' => null, 'reference_day' => $calendar->tomorrow()->toDateString()],
            'date' => (function () use ($calendar, $date, $term) {
                $day = $date !== null ? $calendar->date($date) : $calendar->today();

                return ['term' => $term, 'mode' => 'date', 'scheduled_date' => $day->toDateString(), 'week_start_date' => null, 'reference_day' => $day->toDateString()];
            })(),
            'next_week' => ['term' => $term, 'mode' => 'week', 'scheduled_date' => null, 'week_start_date' => $calendar->nextWeekStart()->toDateString(), 'reference_day' => $calendar->nextWeekStart()->toDateString()],
            'this_week' => ['term' => $term, 'mode' => 'week', 'scheduled_date' => null, 'week_start_date' => $calendar->thisWeekStart()->toDateString(), 'reference_day' => $calendar->thisWeekStart()->toDateString()],
            default => ['term' => 'unknown', 'mode' => 'someday', 'scheduled_date' => null, 'week_start_date' => null, 'reference_day' => $calendar->today()->toDateString()],
        };
    }

    /**
     * @param  array{person_ids: list<int>, meal_type?: string|null, term?: string, date?: string|null, filters?: array<string, mixed>, one_off_guest_ids?: list<int>}  $inputs
     */
    public function start(Household $household, ?User $creator, array $inputs): SelectionSession
    {
        $personIds = $this->verifiedPersonIds($household, $inputs['person_ids']);
        if ($personIds === []) {
            throw new InvalidArgumentException('Vyber aspoň jedného stravníka.');
        }

        $mealType = isset($inputs['meal_type']) && $inputs['meal_type'] !== '' && $inputs['meal_type'] !== 'any'
            ? MealType::from($inputs['meal_type'])
            : null;
        $term = $this->resolveTerm($household, $inputs['term'] ?? 'unknown', $inputs['date'] ?? null);
        $filters = SelectionFilters::fromArray($inputs['filters'] ?? []);

        $result = $this->evaluate($household, $personIds, $mealType, $term, $filters);

        $session = SelectionSession::create([
            'household_id' => $household->id,
            'creator_id' => $creator?->id,
            'inputs' => array_filter([
                'person_ids' => $personIds,
                'meal_type' => $mealType?->value,
                'term' => $term,
                'filters' => $filters->toArray(),
                'one_off_guest_ids' => $inputs['one_off_guest_ids'] ?? null,
            ], fn ($v) => $v !== null),
            'config_version' => $result->configVersion,
            'candidates' => [
                'scored' => array_map(fn ($c) => $c->toArray(), $result->scored),
                'excluded' => array_map(fn ($e) => $e->toArray(), $result->excluded),
                'soft_counts' => $result->softExclusionCounts(),
            ],
            'state' => [
                'available' => array_map(fn ($c) => $c->id, $result->scored),
                'current' => null,
                'skipped' => [],
                'accepted' => [],
                'sequence' => 0,
            ],
            'expires_at' => now()->addHours($this->config->sessionTtlHours),
        ]);

        return $this->ensureCurrent($session);
    }

    /**
     * Restart with relaxed filters (new session, same diners/term).
     *
     * @param  array<string, mixed>  $filterChanges
     */
    public function restartWith(SelectionSession $session, array $filterChanges): SelectionSession
    {
        $household = Household::findOrFail($session->household_id);
        $old = $session->inputs;
        $inputs = [
            'person_ids' => array_values(array_map('intval', $old['person_ids'])),
            'meal_type' => $old['meal_type'] ?? null,
            'term' => $old['term']['term'] ?? 'unknown',
            'date' => $old['term']['scheduled_date'] ?? null,
            'filters' => SelectionFilters::fromArray($old['filters'] ?? [])->with($filterChanges)->toArray(),
        ];
        if (isset($old['one_off_guest_ids'])) {
            $inputs['one_off_guest_ids'] = $old['one_off_guest_ids'];
        }

        return $this->start($household, $session->creator_id ? User::find($session->creator_id) : null, $inputs);
    }

    /**
     * @param  list<int>  $personIds
     * @param  array{mode: string, scheduled_date: string|null, week_start_date: string|null, reference_day: string}  $term
     */
    public function evaluate(Household $household, array $personIds, ?MealType $mealType, array $term, SelectionFilters $filters): SelectionResult
    {
        $referenceDay = CarbonImmutable::parse($term['reference_day'], $household->timezone)->startOfDay();
        $people = Person::query()->where('household_id', $household->id)->whereIn('id', $personIds)->get();

        $input = new SelectionInput(
            personIds: $personIds,
            personNames: $people->pluck('name', 'id')->all(),
            mealType: $mealType,
            referenceDay: $referenceDay,
            filters: $filters,
            weekStart: $term['week_start_date'] ? CarbonImmutable::parse($term['week_start_date']) : null,
        );

        return (new SelectionEngine($this->config))->run($this->loadCandidates($household, $personIds, $referenceDay), $input);
    }

    /**
     * Gather everything the pure engine needs in a handful of queries.
     *
     * @param  list<int>  $personIds
     * @return list<RecipeCandidate>
     */
    public function loadCandidates(Household $household, array $personIds, CarbonImmutable $referenceDay): array
    {
        $recipes = Recipe::query()
            ->where('household_id', $household->id)
            ->whereNull('archived_at')
            ->with([
                'mealTypes',
                'preferences' => fn ($q) => $q->whereIn('person_id', $personIds),
                'exclusions' => fn ($q) => $q->whereIn('person_id', $personIds),
            ])
            ->get();

        $recipeIds = $recipes->pluck('id')->all();

        $cookings = CookingEvent::query()
            ->whereIn('recipe_id', $recipeIds)
            ->whereNull('voided_at')
            ->where('cooked_on', '<=', $referenceDay->toDateString())
            ->where('cooked_on', '>=', $referenceDay->subDays(120)->toDateString())
            ->with('people:id')
            ->get()
            ->groupBy('recipe_id');

        $plans = MealPlan::query()
            ->whereIn('recipe_id', $recipeIds)
            ->where('status', PlanStatus::Planned)
            ->with('people:id')
            ->get()
            ->groupBy('recipe_id');

        return array_values($recipes->map(function (Recipe $recipe) use ($cookings, $plans) {
            return new RecipeCandidate(
                id: $recipe->id,
                title: $recipe->title,
                mealTypes: array_map(fn ($t) => $t->value, $recipe->mealTypeEnums()),
                totalMinutes: $recipe->totalMinutes(),
                archived: $recipe->isArchived(),
                preferences: $recipe->preferences->mapWithKeys(fn ($p) => [$p->person_id => $p->preference->value])->all(),
                excludedFor: array_values(array_map('intval', $recipe->exclusions->pluck('person_id')->all())),
                cookings: array_values(($cookings->get($recipe->id) ?? collect())->map(fn (CookingEvent $e) => [
                    'cooked_on' => $e->cooked_on->toDateString(),
                    'person_ids' => array_values(array_map('intval', $e->people->pluck('id')->all())),
                ])->all()),
                plans: array_values(($plans->get($recipe->id) ?? collect())->map(fn (MealPlan $p) => [
                    'mode' => $p->mode->value,
                    'scheduled_date' => $p->scheduled_date?->toDateString(),
                    'week_start_date' => $p->week_start_date?->toDateString(),
                    'person_ids' => array_values(array_map('intval', $p->people->pluck('id')->all())),
                ])->all()),
            );
        })->all());
    }

    /**
     * Make sure the session has a current card if any candidate is left. Re-validates archive/exclusion state.
     */
    public function ensureCurrent(SelectionSession $session): SelectionSession
    {
        return DB::transaction(function () use ($session) {
            $session = SelectionSession::query()->lockForUpdate()->findOrFail($session->id);
            $state = $session->state;

            while ($state['current'] === null && $state['available'] !== []) {
                $weights = [];
                foreach ($session->candidates['scored'] as $c) {
                    if (in_array($c['id'], $state['available'], true)) {
                        $weights[$c['id']] = (float) $c['weight'];
                    }
                }

                $picked = $this->picker->pick($weights);
                if ($picked === null) {
                    break;
                }

                $state['available'] = array_values(array_filter($state['available'], fn ($id) => $id !== $picked));

                if (! $this->stillValid($session, $picked)) {
                    continue;
                }

                $state['current'] = $picked;
                $state['sequence']++;
                $this->log($session, $picked, 'shown', $state['sequence']);
            }

            $session->state = $state;
            $session->save();

            return $session;
        });
    }

    public function skip(SelectionSession $session): SelectionSession
    {
        $session = DB::transaction(function () use ($session) {
            $session = SelectionSession::query()->lockForUpdate()->findOrFail($session->id);
            $state = $session->state;
            if ($state['current'] !== null) {
                $state['skipped'][] = $state['current'];
                $state['sequence']++;
                $this->log($session, $state['current'], 'skipped', $state['sequence']);
                $state['current'] = null;
            }
            $session->state = $state;
            $session->save();

            return $session;
        });

        return $this->ensureCurrent($session);
    }

    /**
     * Restore the last skipped card. The current card (if any) goes back to the pool.
     */
    public function undo(SelectionSession $session): SelectionSession
    {
        return DB::transaction(function () use ($session) {
            $session = SelectionSession::query()->lockForUpdate()->findOrFail($session->id);
            $state = $session->state;

            if ($state['skipped'] === []) {
                return $session;
            }

            $restored = array_pop($state['skipped']);
            if ($state['current'] !== null) {
                array_unshift($state['available'], $state['current']);
            }
            $state['current'] = $restored;
            $state['sequence']++;
            $this->log($session, $restored, 'undo', $state['sequence']);

            $session->state = $state;
            $session->save();

            return $session;
        });
    }

    public function markAccepted(SelectionSession $session, int $recipeId): void
    {
        DB::transaction(function () use ($session, $recipeId) {
            $session = SelectionSession::query()->lockForUpdate()->findOrFail($session->id);
            $state = $session->state;
            $state['accepted'][] = $recipeId;
            $state['sequence']++;
            $this->log($session, $recipeId, 'accepted', $state['sequence']);
            $session->state = $state;
            $session->save();
        });
    }

    /**
     * Re-check archive state and hard exclusions that may have changed since the session started.
     */
    public function stillValid(SelectionSession $session, int $recipeId): bool
    {
        $recipe = Recipe::query()->where('household_id', $session->household_id)->find($recipeId);
        if ($recipe === null || $recipe->isArchived()) {
            return false;
        }

        return ! PersonRecipeExclusion::query()
            ->where('recipe_id', $recipeId)
            ->whereIn('person_id', $session->inputs['person_ids'])
            ->exists();
    }

    /** @return array<string, mixed>|null */
    public function currentCandidate(SelectionSession $session): ?array
    {
        $current = $session->state['current'];
        if ($current === null) {
            return null;
        }

        foreach ($session->candidates['scored'] as $c) {
            if ($c['id'] === $current) {
                return $c;
            }
        }

        return null;
    }

    public function remainingCount(SelectionSession $session): int
    {
        return count($session->state['available']);
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    private function verifiedPersonIds(Household $household, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return array_values(array_map('intval', Person::query()
            ->where('household_id', $household->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all()));
    }

    private function log(SelectionSession $session, int $recipeId, string $action, int $sequence): void
    {
        SelectionAction::create([
            'session_id' => $session->id,
            'recipe_id' => $recipeId,
            'action' => $action,
            'sequence' => $sequence,
            'created_at' => now(),
        ]);
    }

    /** @return Collection<int, Person> */
    public function people(SelectionSession $session): Collection
    {
        return Person::query()->whereIn('id', $session->inputs['person_ids'])->get();
    }
}
