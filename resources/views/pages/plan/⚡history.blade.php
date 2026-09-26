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

    public ?int $voidingId = null;

    public function askVoid(int $eventId): void
    {
        $this->voidingId = $eventId;
        \Flux\Flux::modal('void-event')->show();
    }

    public function void(CookingHistoryService $history): void
    {
        $event = CookingEvent::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($this->voidingId);
        $this->authorize('update', $event);
        $history->void($event);
        $this->voidingId = null;
        unset($this->events);
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-page-header title="História varenia" :back="route('plan.index')" subtitle="Iba potvrdené varenia. Ovplyvňujú, čo generátor navrhne nabudúce." />

    @if ($this->filterRecipe)
        <div class="flex items-center gap-2 text-sm">
            <flux:badge color="orange" variant="pill">Iba: {{ $this->filterRecipe->title }}</flux:badge>
            <flux:button size="xs" variant="ghost" wire:click="$set('recipe', null)">Zrušiť filter</flux:button>
        </div>
    @endif

    <x-confirm-modal name="void-event" title="Zneplatniť tento záznam?" text="Prepojený plán sa vráti medzi naplánované." confirm="Opraviť omyl" action="void" variant="primary" icon="arrow-uturn-left" />

    @if ($this->events->isEmpty())
        <flux:card variant="soft" class="text-center">
            <flux:text>Zatiaľ žiadne potvrdené varenie.</flux:text>
        </flux:card>
    @else
        <flux:timeline>
            @foreach ($this->events as $event)
                <flux:timeline.item wire:key="event-{{ $event->id }}">
                    <flux:timeline.indicator color="orange">
                        <flux:icon name="check" class="size-4" />
                    </flux:timeline.indicator>
                    <flux:timeline.content>
                        <flux:card size="sm" class="flex items-center gap-3 !p-2.5">
                            @if ($event->recipe)
                                <x-recipe-cover :recipe="$event->recipe" conversion="thumb" class="size-14 shrink-0 rounded-xl" />
                            @else
                                <div class="size-14 shrink-0 rounded-xl bg-zinc-200 dark:bg-zinc-700"></div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold">
                                    @if ($event->recipe)
                                        <a href="{{ route('recipes.show', $event->recipe) }}" wire:navigate>{{ $event->recipe->title }}</a>
                                    @else
                                        {{ $event->recipe_title_snapshot }} <span class="text-xs font-normal text-zinc-500">(recept vymazaný)</span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                                    <span>{{ $event->cooked_on->translatedFormat('D j. n. Y') }}</span>
                                    @if ($event->servings)<span>· {{ $event->servings }} porc.</span>@endif
                                    <span class="flex -space-x-1">@foreach ($event->people as $person)<x-person-avatar :person="$person" size="size-4" />@endforeach</span>
                                    @if ($event->note)<span class="truncate">· {{ $event->note }}</span>@endif
                                </div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="askVoid({{ $event->id }})" data-test="void-{{ $event->id }}">Opraviť omyl</flux:button>
                        </flux:card>
                    </flux:timeline.content>
                </flux:timeline.item>
            @endforeach
        </flux:timeline>
    @endif

    <div>{{ $this->events->links() }}</div>
</div>
