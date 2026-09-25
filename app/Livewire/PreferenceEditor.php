<?php

namespace App\Livewire;

use App\Enums\Preference;
use App\Models\Person;
use App\Models\PersonRecipeExclusion;
use App\Models\PersonRecipePreference;
use App\Models\Recipe;
use App\Services\PreferenceService;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Per-person preferences and the hard "do not offer" exclusion for one recipe.
 *
 * @property-read Recipe $recipe
 * @property-read Collection<int, Person> $people
 * @property-read array<int, string> $preferences
 * @property-read array<int, string|null> $exclusions
 */
class PreferenceEditor extends Component
{
    public int $recipeId;

    /** @var array<int, string> */
    public array $reasons = [];

    public ?int $excludeFormFor = null;

    public function mount(int $recipeId): void
    {
        $this->recipeId = $recipeId;
    }

    #[Computed]
    public function recipe(): Recipe
    {
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($this->recipeId);
        $this->authorize('view', $recipe);

        return $recipe;
    }

    /** @return Collection<int, Person> */
    #[Computed]
    public function people(): Collection
    {
        return Person::query()->where('household_id', app(CurrentHousehold::class)->id())->active()->orderBy('name')->get();
    }

    /** @return array<int, string> */
    #[Computed]
    public function preferences(): array
    {
        return PersonRecipePreference::query()->where('recipe_id', $this->recipeId)->get()
            ->mapWithKeys(fn ($p) => [$p->person_id => $p->preference->value])->all();
    }

    /** @return array<int, string|null> */
    #[Computed]
    public function exclusions(): array
    {
        return PersonRecipeExclusion::query()->where('recipe_id', $this->recipeId)->get()
            ->mapWithKeys(fn ($e) => [$e->person_id => $e->reason ?? ''])->all();
    }

    public function set(int $personId, string $preference, PreferenceService $service): void
    {
        $this->authorize('update', $this->recipe);
        $person = $this->people->firstWhere('id', $personId);
        if ($person === null) {
            return;
        }

        $service->set($person, $this->recipe, $preference === '' ? null : Preference::from($preference));
        unset($this->preferences);
        $this->dispatch('preferences-changed');
    }

    public function exclude(int $personId, PreferenceService $service): void
    {
        $this->authorize('update', $this->recipe);
        $person = $this->people->firstWhere('id', $personId);
        if ($person === null) {
            return;
        }

        $service->exclude($person, $this->recipe, $this->reasons[$personId] ?? null, auth()->user());
        $this->excludeFormFor = null;
        unset($this->exclusions);
        $this->dispatch('preferences-changed');
    }

    public function removeExclusion(int $personId, PreferenceService $service): void
    {
        $this->authorize('update', $this->recipe);
        $person = $this->people->firstWhere('id', $personId);
        if ($person === null) {
            return;
        }

        $service->removeExclusion($person, $this->recipe);
        unset($this->exclusions);
        $this->dispatch('preferences-changed');
    }

    #[On('preferences-changed')]
    public function refreshPreferences(): void
    {
        unset($this->preferences, $this->exclusions);
    }

    public function render(): View
    {
        return view('livewire.preference-editor', ['options' => Preference::cases()]);
    }
}
