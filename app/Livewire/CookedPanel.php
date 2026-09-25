<?php

namespace App\Livewire;

use App\Enums\Preference;
use App\Models\CookingEvent;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Services\CookingHistoryService;
use App\Services\MealPlanningService;
use App\Services\PlanningCalendar;
use App\Services\PreferenceService;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Uvaril som" confirmation, shared by plan items and the recipe detail. Idempotent per opened panel.
 *
 * @property-read Collection<int, Person> $people
 * @property-read Recipe|null $recipe
 */
class CookedPanel extends Component
{
    public bool $open = false;

    public ?int $recipeId = null;

    public ?int $planId = null;

    public string $cookedOn = '';

    /** @var list<int> */
    public array $personIds = [];

    public ?int $servings = null;

    public string $note = '';

    public string $idempotencyKey = '';

    public bool $saved = false;

    public ?int $eventId = null;

    public string $error = '';

    /** @var array<int, string> */
    public array $tastes = [];

    /**
     * @param  array<string, mixed>  $defaults
     */
    #[On('open-cooked-panel')]
    public function openFor(int $recipeId, ?int $planId = null, array $defaults = []): void
    {
        $household = app(CurrentHousehold::class)->get();
        $recipe = Recipe::query()->where('household_id', $household->id)->findOrFail($recipeId);
        $this->authorize('update', $recipe);

        $calendar = new PlanningCalendar($household->timezone);
        $plan = $planId ? MealPlan::query()->where('household_id', $household->id)->with('people')->findOrFail($planId) : null;

        $this->recipeId = $recipe->id;
        $this->planId = $plan?->id;
        $this->saved = false;
        $this->eventId = null;
        $this->error = '';
        $this->note = '';
        $this->tastes = [];
        $this->idempotencyKey = (string) Str::uuid();

        $suggested = $plan?->scheduled_date;
        $this->cookedOn = ($suggested !== null && $suggested->lessThanOrEqualTo($calendar->today()) ? $suggested : $calendar->today())->toDateString();
        $this->personIds = array_values(array_map('intval', $defaults['person_ids'] ?? $plan?->people->pluck('id')->all() ?? ($household->default_person_ids ?? [])));
        $this->servings = $defaults['servings'] ?? $plan->servings ?? (count($this->personIds) ?: null);
        $this->open = true;
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

    public function save(MealPlanningService $planning, CookingHistoryService $history): void
    {
        $recipe = $this->recipe;
        if ($recipe === null || $this->saved) {
            return;
        }
        $this->authorize('update', $recipe);

        $household = app(CurrentHousehold::class)->get();
        $today = (new PlanningCalendar($household->timezone))->today()->toDateString();

        $this->validate([
            'cookedOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'servings' => ['nullable', 'integer', 'min:1', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['cookedOn.before_or_equal' => 'Dátum uvarenia nemôže byť v budúcnosti.']);

        $data = [
            'cooked_on' => $this->cookedOn,
            'servings' => $this->servings,
            'note' => $this->note,
            'person_ids' => $this->personIds,
        ];

        try {
            if ($this->planId) {
                $plan = MealPlan::query()->where('household_id', $household->id)->findOrFail($this->planId);
                $this->authorize('update', $plan);
                $plan = $planning->markCooked($plan, $data, auth()->user(), $this->idempotencyKey);
                $event = $plan->activeCookingEvent()->first();
            } else {
                $event = $history->record($household, $recipe, $data, auth()->user(), $this->idempotencyKey);
            }
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->eventId = $event?->id;
        $this->saved = true;
        $this->dispatch('cooked-saved', eventId: $this->eventId);
    }

    /**
     * Optional "Chutilo?" quick preference update per diner. Cooking alone never changes a preference.
     */
    public function setTaste(int $personId, string $preference, PreferenceService $preferences): void
    {
        $recipe = $this->recipe;
        $person = $this->people->firstWhere('id', $personId);
        if ($recipe === null || $person === null) {
            return;
        }

        $preferences->set($person, $recipe, Preference::from($preference));
        $this->tastes[$personId] = $preference;
        $this->dispatch('preferences-changed');
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render(): View
    {
        return view('livewire.cooked-panel', [
            'event' => $this->eventId ? CookingEvent::find($this->eventId) : null,
            'preferenceOptions' => Preference::cases(),
        ]);
    }
}
