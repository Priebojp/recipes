<?php

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\SelectionSession;
use App\Services\PlanningCalendar;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Čo variť')] class extends Component {
    #[Computed]
    public function recipeCount(): int
    {
        return Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->active()->count();
    }

    #[Computed]
    public function personCount(): int
    {
        return Person::query()->where('household_id', app(CurrentHousehold::class)->id())->active()->count();
    }

    #[Computed]
    public function todayPlans()
    {
        $household = app(CurrentHousehold::class)->get();
        $today = (new PlanningCalendar($household->timezone))->today()->toDateString();

        return MealPlan::query()
            ->where('household_id', $household->id)
            ->where('status', PlanStatus::Planned)
            ->where('scheduled_date', $today)
            ->with(['recipe.cover', 'people'])
            ->orderBy('meal_type')
            ->get();
    }

    #[Computed]
    public function unfinishedSession(): ?SelectionSession
    {
        $session = SelectionSession::query()
            ->where('household_id', app(CurrentHousehold::class)->id())
            ->where('creator_id', auth()->id())
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($session === null || ($session->state['current'] ?? null) === null || ($session->state['accepted'] ?? []) !== []) {
            return null;
        }

        return $session;
    }

    public function openCooked(int $recipeId, int $planId): void
    {
        $this->dispatch('open-cooked-panel', recipeId: $recipeId, planId: $planId);
    }
}; ?>

<div class="mx-auto max-w-2xl space-y-6">
    <x-page-header title="Čo dnes navarím?" />

    @if ($this->recipeCount === 0)
        <flux:callout icon="book-open">
            <flux:callout.heading>Pridaj prvé jedlo, stačí názov</flux:callout.heading>
            <flux:callout.text>Ostatné údaje doplníš, keď budeš chcieť. Generátor potom bude mať z čoho vyberať.</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('recipes.create')" wire:navigate variant="primary" icon="plus">Pridať recept</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->personCount === 0)
        <flux:callout icon="users">
            <flux:callout.heading>Vytvor prvý profil stravníka</flux:callout.heading>
            <flux:callout.text>Generátor potrebuje aspoň jedného človeka, pre ktorého sa varí.</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('family.index')" wire:navigate variant="primary" icon="plus">Pridať stravníka</flux:button>
            </x-slot>
        </flux:callout>
    @else
        <flux:button :href="route('cook.select')" wire:navigate variant="primary" icon="sparkles" class="w-full py-6 text-lg" data-test="pick-meal">
            Vyber mi jedlo
        </flux:button>

        @if ($this->unfinishedSession)
            <flux:callout icon="arrow-path" variant="secondary">
                <flux:callout.heading>Nedokončený výber</flux:callout.heading>
                <flux:callout.text>Máš rozpracovanú voľbu jedla.</flux:callout.text>
                <x-slot name="actions">
                    <flux:button :href="route('cook.session', $this->unfinishedSession)" wire:navigate size="sm">Pokračovať</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @endif

    <div>
        <div class="mb-2 flex items-center justify-between">
            <flux:heading size="lg">Dnešný plán</flux:heading>
            <flux:link :href="route('plan.index')" wire:navigate class="text-sm">Celý plán</flux:link>
        </div>

        @forelse ($this->todayPlans as $plan)
            <div class="mb-2 flex items-center gap-3 rounded-xl border border-zinc-200 p-2 dark:border-zinc-700">
                <x-recipe-cover :recipe="$plan->recipe" conversion="thumb" class="size-16 shrink-0 rounded-lg" />
                <div class="min-w-0 flex-1">
                    <a href="{{ route('recipes.show', $plan->recipe) }}" wire:navigate class="block truncate font-medium">{{ $plan->recipe->title }}</a>
                    <div class="flex flex-wrap items-center gap-1 text-xs text-zinc-500">
                        @if ($plan->meal_type)<span>{{ $plan->meal_type->label() }}</span>@endif
                        @if ($plan->servings)<span>· {{ $plan->servings }} porcií</span>@endif
                        <span class="flex -space-x-1">
                            @foreach ($plan->people as $person)
                                <x-person-avatar :person="$person" size="size-5" />
                            @endforeach
                        </span>
                    </div>
                </div>
                <flux:button size="sm" icon="check" wire:click="openCooked({{ $plan->recipe_id }}, {{ $plan->id }})">Uvarené</flux:button>
            </div>
        @empty
            <flux:text>Na dnes nie je nič naplánované.</flux:text>
        @endforelse
    </div>

    <livewire:cooked-panel />
</div>
