<?php

use App\Enums\Preference;
use App\Models\Person;
use App\Models\Recipe;
use App\Services\PreferenceService;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Recepty')] class extends Component {
    use WithPagination;

    #[Url]
    public string $q = '';

    #[Url]
    public string $filter = 'all';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function activePerson(): ?Person
    {
        return app(CurrentHousehold::class)->activePerson(auth()->user());
    }

    #[Computed]
    public function people()
    {
        return Person::query()->where('household_id', app(CurrentHousehold::class)->id())->active()->orderBy('name')->get();
    }

    #[Computed]
    public function recipes()
    {
        $householdId = app(CurrentHousehold::class)->id();

        return Recipe::query()
            ->where('household_id', $householdId)
            ->when($this->filter === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when(str_starts_with($this->filter, 'favorites:'), function ($q) {
                $personId = (int) substr($this->filter, 10);
                $q->whereHas('preferences', fn ($p) => $p->where('person_id', $personId)->where('preference', Preference::Favorite));
            })
            ->when(trim($this->q) !== '', function ($q) {
                $term = '%'.mb_strtolower(trim($this->q)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(description) LIKE ?', [$term]));
            })
            ->with(['cover', 'mealTypes', 'preferences' => fn ($p) => $p->where('preference', Preference::Favorite)->with('person')])
            ->orderBy('title')
            ->paginate(24);
    }

    public function switchProfile(int $personId): void
    {
        $person = $this->people->firstWhere('id', $personId);
        if ($person) {
            app(CurrentHousehold::class)->setActivePerson($person);
            unset($this->activePerson);
        }
    }

    public function toggleFavorite(int $recipeId, PreferenceService $preferences): void
    {
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($recipeId);
        $this->authorize('update', $recipe);
        $person = $this->activePerson;
        if ($person === null) {
            return;
        }

        $preferences->toggleFavorite($person, $recipe);
        unset($this->recipes);
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-4">
    <x-page-header title="Recepty">
        <flux:button :href="route('recipes.create')" wire:navigate variant="primary" icon="plus" data-test="new-recipe">Pridať</flux:button>
    </x-page-header>

    <div class="flex flex-col gap-2 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="q" icon="magnifying-glass" placeholder="Hľadať recept…" clearable class="flex-1" />
        <flux:select wire:model.live="filter" class="sm:w-64">
            <flux:select.option value="all">Všetky aktívne</flux:select.option>
            @if ($this->activePerson)
                <flux:select.option value="favorites:{{ $this->activePerson->id }}">Moje obľúbené ({{ $this->activePerson->name }})</flux:select.option>
            @endif
            @foreach ($this->people as $person)
                @continue($this->activePerson && $person->id === $this->activePerson->id)
                <flux:select.option value="favorites:{{ $person->id }}">Obľúbené: {{ $person->name }}</flux:select.option>
            @endforeach
            <flux:select.option value="archived">Archivované</flux:select.option>
        </flux:select>
    </div>

    @if ($this->activePerson && $this->people->count() > 1)
        <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-500">
            <span>Upravuješ chute:</span>
            <flux:dropdown>
                <flux:button size="xs" icon-trailing="chevron-down"><x-person-avatar :person="$this->activePerson" size="size-4" class="mr-1" /> {{ $this->activePerson->name }}</flux:button>
                <flux:menu>
                    @foreach ($this->people as $person)
                        <flux:menu.item wire:click="switchProfile({{ $person->id }})">{{ $person->name }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        </div>
    @endif

    @if ($this->recipes->isEmpty())
        <flux:callout icon="book-open">
            <flux:callout.heading>{{ trim($q) !== '' || $filter !== 'all' ? 'Nič sa nenašlo' : 'Pridaj prvé jedlo, stačí názov' }}</flux:callout.heading>
            @if (trim($q) === '' && $filter === 'all')
                <x-slot name="actions">
                    <flux:button :href="route('recipes.create')" wire:navigate variant="primary" icon="plus">Pridať recept</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @else
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($this->recipes as $recipe)
                <x-recipe-card :recipe="$recipe" :href="route('recipes.show', $recipe)" wire:key="recipe-{{ $recipe->id }}">
                    <x-slot:meta>
                        @if ($recipe->isPublic())
                            <flux:icon name="globe-alt" class="size-3.5 text-zinc-400" title="Verejný recept" />
                        @endif
                        <span class="ml-auto flex -space-x-1">
                            @foreach ($recipe->preferences->take(4) as $pref)
                                @if ($pref->person)<x-person-avatar :person="$pref->person" size="size-5" />@endif
                            @endforeach
                        </span>
                    </x-slot:meta>
                    @if ($this->activePerson && ! $recipe->isArchived())
                        @php($liked = $recipe->preferences->contains(fn ($p) => $p->person_id === $this->activePerson->id))
                        <button type="button" wire:click="toggleFavorite({{ $recipe->id }})" class="absolute right-2 top-2 rounded-full bg-white/90 p-1.5 shadow transition hover:scale-110 dark:bg-zinc-900/90" aria-label="{{ $liked ? 'Zrušiť obľúbené' : 'Označiť ako obľúbené' }}" data-test="heart-{{ $recipe->id }}">
                            <flux:icon name="heart" :variant="$liked ? 'solid' : 'outline'" class="size-5 {{ $liked ? 'text-red-500' : 'text-zinc-500' }}" />
                        </button>
                    @endif
                </x-recipe-card>
            @endforeach
        </div>
        <div>{{ $this->recipes->links() }}</div>
    @endif
</div>
