<?php

namespace App\Livewire;

use App\Enums\MealType;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\SelectionSession;
use App\Services\MealPlanningService;
use App\Services\PlanningCalendar;
use App\Services\RecipeSelectionService;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The single planning panel used from the card, the recipe detail and the plan page.
 *
 * @property-read Collection<int, Person> $people
 * @property-read Recipe|null $recipe
 * @property-read Collection<int, MealPlan> $collisions
 */
class PlanPanel extends Component
{
    public bool $open = false;

    public ?int $recipeId = null;

    public ?int $planId = null;

    public ?string $sessionId = null;

    /** @var list<int> */
    public array $personIds = [];

    public string $mealType = 'any';

    public string $term = 'today';

    public ?string $date = null;

    public ?int $servings = null;

    public bool $saved = false;

    public bool $forceDuplicate = false;

    public string $error = '';

    /**
     * @param  array<string, mixed>  $defaults
     */
    #[On('open-plan-panel')]
    public function openFor(int $recipeId, array $defaults = [], ?string $sessionId = null, ?int $planId = null): void
    {
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($recipeId);
        $this->authorize('update', $recipe);

        $household = app(CurrentHousehold::class)->get();
        $this->recipeId = $recipe->id;
        $this->planId = $planId;
        $this->sessionId = $sessionId;
        $this->saved = false;
        $this->forceDuplicate = false;
        $this->error = '';

        $this->personIds = array_values(array_map('intval', $defaults['person_ids'] ?? ($household->default_person_ids ?? [])));
        $this->personIds = array_values(array_intersect($this->personIds, $this->people->pluck('id')->all()));
        $this->mealType = $defaults['meal_type'] ?? 'any';
        $this->term = $defaults['term'] ?? 'today';
        if ($this->term === 'unknown') {
            $this->term = 'today';
        }
        $this->date = $defaults['date'] ?? null;
        $this->servings = isset($defaults['servings']) ? (int) $defaults['servings'] : (count($this->personIds) ?: null);
        $this->open = true;
    }

    public function updatedPersonIds(): void
    {
        // Suggest 1 person = 1 serving until the user changes it explicitly.
        if ($this->servings === null || $this->servings === count($this->personIds) + 1 || $this->servings === count($this->personIds) - 1) {
            $this->servings = count($this->personIds) ?: null;
        }
    }

    /** @return Collection<int, Person> */
    #[Computed]
    public function people(): Collection
    {
        return Person::query()->where('household_id', app(CurrentHousehold::class)->id())->active()->orderBy('name')->get();
    }

    #[Computed]
    public function recipe(): ?Recipe
    {
        return $this->recipeId ? Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->find($this->recipeId) : null;
    }

    /**
     * Existing active plans of the same recipe close to the chosen day (warning before duplicate planning).
     *
     * @return Collection<int, MealPlan>
     */
    #[Computed]
    public function collisions(): Collection
    {
        $recipe = $this->recipe;
        if ($recipe === null) {
            return collect();
        }

        $term = app(RecipeSelectionService::class)->resolveTerm(app(CurrentHousehold::class)->get(), $this->term, $this->date);
        if ($term['mode'] === 'someday') {
            return collect();
        }

        $day = (new PlanningCalendar(app(CurrentHousehold::class)->timezone()))->date($term['reference_day']);

        return app(MealPlanningService::class)->collisions($recipe, $day)->when($this->planId, fn ($c) => $c->reject(fn ($p) => $p->id === $this->planId));
    }

    public function save(MealPlanningService $planning, RecipeSelectionService $selection): void
    {
        $recipe = $this->recipe;
        if ($recipe === null) {
            return;
        }
        $this->authorize('update', $recipe);

        $this->validate([
            'personIds' => ['array'],
            'date' => ['nullable', 'date_format:Y-m-d', 'required_if:term,date'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:200'],
        ], ['date.required_if' => 'Vyber dátum.']);

        if ($this->collisions->isNotEmpty() && ! $this->forceDuplicate) {
            $this->error = 'Toto jedlo už je naplánované v blízkych dňoch. Môžeš otvoriť existujúci plán alebo vedome pridať ďalší.';

            return;
        }

        $household = app(CurrentHousehold::class)->get();
        $term = $selection->resolveTerm($household, $this->term, $this->date);

        try {
            $data = [
                'mode' => $term['mode'],
                'scheduled_date' => $term['scheduled_date'],
                'week_start_date' => $term['week_start_date'],
                'meal_type' => $this->mealType,
                'servings' => $this->servings,
                'person_ids' => $this->personIds,
            ];

            if ($this->planId) {
                $plan = MealPlan::query()->where('household_id', $household->id)->findOrFail($this->planId);
                $this->authorize('update', $plan);
                $plan = $planning->update($plan, $data);
            } else {
                $plan = $planning->create($household, $recipe, $data, auth()->user());
            }
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($this->sessionId) {
            $session = SelectionSession::query()->where('household_id', $household->id)->find($this->sessionId);
            if ($session) {
                $selection->markAccepted($session, $recipe->id);
            }
        }

        $this->planId = $plan->id;
        $this->saved = true;
        $this->error = '';
        $this->dispatch('plan-saved', planId: $plan->id);
    }

    public function done(): void
    {
        $this->open = false;
        $this->dispatch('plan-done');
    }

    public function next(): void
    {
        $this->open = false;
        $this->dispatch('plan-next');
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render(): View
    {
        return view('livewire.plan-panel', [
            'mealTypes' => MealType::cases(),
        ]);
    }
}
