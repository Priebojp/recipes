<?php

use App\Models\Recipe;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::public')] #[Title('Čo dnes navarím?')] class extends Component {
    use WithPagination;

    #[Url]
    public string $q = '';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function recipes()
    {
        return Recipe::query()
            ->published()
            ->when(trim($this->q) !== '', function ($query) {
                $term = '%'.mb_strtolower(trim($this->q)).'%';
                $query->where(fn ($w) => $w->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(description) LIKE ?', [$term]));
            })
            ->with(['cover', 'mealTypes'])
            ->orderByDesc('published_at')
            ->paginate(12);
    }

    #[Computed]
    public function publicCount(): int
    {
        return Recipe::query()->published()->count();
    }
}; ?>

<div class="space-y-16">
    <section class="relative overflow-hidden rounded-3xl bg-zinc-900 px-6 py-14 text-white sm:px-12 sm:py-20 dark:bg-zinc-800">
        <div class="pointer-events-none absolute -right-24 -top-24 size-96 rounded-full bg-accent/40 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 size-80 rounded-full bg-amber-300/20 blur-3xl"></div>
        <div class="relative max-w-2xl space-y-6">
            <flux:badge color="orange" variant="pill" icon="sparkles">Rodinná kuchárka</flux:badge>
            <h1 class="font-display text-4xl font-bold leading-tight sm:text-6xl">Čo dnes navarím?</h1>
            <p class="max-w-xl text-lg text-zinc-300">Vlastné recepty, chute každého člena rodiny a náhodný výber, ktorý berie ohľad na to, čo ste jedli naposledy. Plán na týždeň a história varenia k tomu.</p>
            <div class="flex flex-wrap gap-3">
                @auth
                    <flux:button :href="route('cook.index')" wire:navigate variant="primary" icon="sparkles">Vyber mi jedlo</flux:button>
                    <flux:button :href="route('recipes.index')" wire:navigate variant="ghost" icon="book-open" class="!bg-white/10 !text-white hover:!bg-white/20">Moje recepty</flux:button>
                @else
                    @if (Route::has('register'))
                        <flux:button :href="route('register')" wire:navigate variant="primary" icon="user-plus">Založiť domácnosť</flux:button>
                    @endif
                    <flux:button :href="route('login')" wire:navigate variant="ghost" class="!bg-white/10 !text-white hover:!bg-white/20">Prihlásiť sa</flux:button>
                @endauth
            </div>
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            ['icon' => 'book-open', 'title' => 'Recepty na jednom mieste', 'text' => 'Stačí názov. Suroviny, postup a fotky doplníš, keď budeš mať čas.'],
            ['icon' => 'heart', 'title' => 'Chute každého stravníka', 'text' => 'Obľúbené, zje, nemá rád. Generátor sa podľa toho rozhoduje.'],
            ['icon' => 'sparkles', 'title' => 'Vážený náhodný výber', 'text' => 'Čo sa varilo nedávno, ide dozadu. Čo majú všetci radi, ide dopredu.'],
        ] as $feature)
            <flux:card class="space-y-2">
                <span class="inline-flex size-10 items-center justify-center rounded-xl bg-accent/10 text-accent">
                    <flux:icon :name="$feature['icon']" class="size-5" />
                </span>
                <flux:heading size="lg" class="font-display">{{ $feature['title'] }}</flux:heading>
                <flux:text>{{ $feature['text'] }}</flux:text>
            </flux:card>
        @endforeach
    </section>

    <section class="space-y-6" id="recepty">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="font-display text-3xl font-bold">Verejné recepty</h2>
                <flux:text class="mt-1">Recepty, ktoré rodiny zdieľajú s ostatnými.</flux:text>
            </div>
            <flux:input wire:model.live.debounce.300ms="q" icon="magnifying-glass" placeholder="Hľadať recept…" clearable class="sm:w-72" />
        </div>

        @if ($this->recipes->isEmpty())
            <flux:callout icon="book-open">
                <flux:callout.heading>{{ trim($q) !== '' ? 'Nič sa nenašlo' : 'Zatiaľ žiadne verejné recepty' }}</flux:callout.heading>
                <flux:callout.text>{{ trim($q) !== '' ? 'Skús iné slovo.' : 'Recept sa dá zverejniť v jeho úprave, v časti Zdieľanie.' }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($this->recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" :href="route('public.recipe', $recipe)" wire:key="public-{{ $recipe->id }}" />
                @endforeach
            </div>
            <div>{{ $this->recipes->links() }}</div>
        @endif
    </section>
</div>
