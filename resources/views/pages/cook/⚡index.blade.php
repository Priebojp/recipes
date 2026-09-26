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

<div class="mx-auto max-w-3xl space-y-8">
    <x-page-header title="Čo dnes navarím?" :subtitle="app(App\Support\CurrentHousehold::class)->get()->name" />

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
        <section class="relative overflow-hidden rounded-3xl bg-zinc-900 p-6 text-white shadow-lg sm:p-8 dark:bg-zinc-800">
            <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-accent/50 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 left-1/3 size-56 rounded-full bg-amber-300/20 blur-3xl"></div>
            <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <h2 class="font-display text-2xl font-bold sm:text-3xl">Nechaj si navrhnúť jedlo</h2>
                    <p class="text-sm text-zinc-300">{{ trans_choice('{1} :count recept|[2,4] :count recepty|[0,*] :count receptov', $this->recipeCount) }} · {{ trans_choice('{1} :count stravník|[2,4] :count stravníci|[0,*] :count stravníkov', $this->personCount) }}</p>
                </div>
                <flux:button :href="route('cook.select')" wire:navigate variant="primary" icon="sparkles" class="shrink-0 px-6 py-6 text-base" data-test="pick-meal">
                    Vyber mi jedlo
                </flux:button>
            </div>
        </section>

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

    <section class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" class="font-display">Dnešný plán</flux:heading>
            <flux:link :href="route('plan.index')" wire:navigate class="text-sm">Celý plán</flux:link>
        </div>

        @forelse ($this->todayPlans as $plan)
            <flux:card size="sm" class="flex items-center gap-3 !p-2.5">
                <x-recipe-cover :recipe="$plan->recipe" conversion="thumb" class="size-16 shrink-0 rounded-xl" />
                <div class="min-w-0 flex-1">
                    <a href="{{ route('recipes.show', $plan->recipe) }}" wire:navigate class="block truncate font-semibold">{{ $plan->recipe->title }}</a>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                        @if ($plan->meal_type)<flux:badge size="sm" color="orange" variant="pill">{{ $plan->meal_type->label() }}</flux:badge>@endif
                        @if ($plan->servings)<span>{{ $plan->servings }} porcií</span>@endif
                        <span class="flex -space-x-1">
                            @foreach ($plan->people as $person)
                                <x-person-avatar :person="$person" size="size-5" />
                            @endforeach
                        </span>
                    </div>
                </div>
                <flux:button size="sm" icon="check" wire:click="openCooked({{ $plan->recipe_id }}, {{ $plan->id }})">Uvarené</flux:button>
            </flux:card>
        @empty
            <flux:card variant="soft" size="sm" class="text-center">
                <flux:text>Na dnes nie je nič naplánované.</flux:text>
            </flux:card>
        @endforelse
    </section>

    <section class="grid grid-cols-3 gap-3">
        <a href="{{ route('recipes.create') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-2xl border border-zinc-200/80 bg-white p-4 text-center text-sm font-medium transition hover:border-accent/50 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="plus" class="size-6 text-accent" /> Nový recept
        </a>
        <a href="{{ route('plan.history') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-2xl border border-zinc-200/80 bg-white p-4 text-center text-sm font-medium transition hover:border-accent/50 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="clock" class="size-6 text-accent" /> História
        </a>
        <a href="{{ route('home') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-2xl border border-zinc-200/80 bg-white p-4 text-center text-sm font-medium transition hover:border-accent/50 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="globe-alt" class="size-6 text-accent" /> Verejné recepty
        </a>
    </section>

    <livewire:cooked-panel />
</div>
