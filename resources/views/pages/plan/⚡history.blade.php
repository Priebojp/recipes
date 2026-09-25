<?php

use App\Models\CookingEvent;
use App\Models\Recipe;
use App\Services\CookingHistoryService;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('História varenia')] class extends Component {
    use WithPagination;

    #[Url]
    public ?int $recipe = null;

    #[Computed]
    public function filterRecipe(): ?Recipe
    {
        return $this->recipe ? Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->find($this->recipe) : null;
    }

    #[Computed]
    public function events()
    {
        return CookingEvent::query()
            ->where('household_id', app(CurrentHousehold::class)->id())
            ->active()
            ->when($this->recipe, fn ($q) => $q->where('recipe_id', $this->recipe))
            ->with(['recipe.cover', 'people', 'mealPlan'])
            ->orderByDesc('cooked_on')->orderByDesc('id')
            ->paginate(30);
    }

    public function void(int $eventId, CookingHistoryService $history): void
    {
        $event = CookingEvent::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($eventId);
        $this->authorize('update', $event);
        $history->void($event);
        unset($this->events);
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-4">
    <x-page-header title="História varenia" :back="route('plan.index')" />

    @if ($this->filterRecipe)
        <div class="flex items-center gap-2 text-sm">
            <span>Iba: <strong>{{ $this->filterRecipe->title }}</strong></span>
            <flux:button size="xs" variant="ghost" wire:click="$set('recipe', null)">Zrušiť filter</flux:button>
        </div>
    @endif

    @forelse ($this->events as $event)
        <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="event-{{ $event->id }}">
            @if ($event->recipe)
                <x-recipe-cover :recipe="$event->recipe" conversion="thumb" class="size-12 shrink-0 rounded-md" />
            @else
                <div class="size-12 shrink-0 rounded-md bg-zinc-200 dark:bg-zinc-700"></div>
            @endif
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium">
                    @if ($event->recipe)
                        <a href="{{ route('recipes.show', $event->recipe) }}" wire:navigate>{{ $event->recipe->title }}</a>
                    @else
                        {{ $event->recipe_title_snapshot }} <span class="text-xs text-zinc-500">(recept vymazaný)</span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-1 text-xs text-zinc-500">
                    <span>{{ $event->cooked_on->translatedFormat('D j. n. Y') }}</span>
                    @if ($event->servings)<span>· {{ $event->servings }} porc.</span>@endif
                    <span class="flex -space-x-1">@foreach ($event->people as $person)<x-person-avatar :person="$person" size="size-4" />@endforeach</span>
                    @if ($event->note)<span class="truncate">· {{ $event->note }}</span>@endif
                </div>
            </div>
            <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="void({{ $event->id }})" wire:confirm="Zneplatniť tento záznam? Prepojený plán sa vráti medzi naplánované.">Opraviť omyl</flux:button>
        </div>
    @empty
        <flux:text>Zatiaľ žiadne potvrdené varenie.</flux:text>
    @endforelse

    <div>{{ $this->events->links() }}</div>
</div>
