<?php

use App\Enums\MealType;
use App\Models\CookingEvent;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\SelectionSession;
use App\Services\RecipeSelectionService;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Návrh jedla')] class extends Component {
    public string $sessionId;

    public function mount(string $session, RecipeSelectionService $selection): void
    {
        $model = SelectionSession::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($session);
        abort_if($model->isExpired(), 404);
        $this->sessionId = $model->id;
        $selection->ensureCurrent($model);
    }

    #[Computed]
    public function session(): SelectionSession
    {
        return SelectionSession::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($this->sessionId);
    }

    #[Computed]
    public function candidate(): ?array
    {
        return app(RecipeSelectionService::class)->currentCandidate($this->session);
    }

    #[Computed]
    public function recipe(): ?Recipe
    {
        $candidate = $this->candidate;

        return $candidate ? Recipe::query()->with(['cover', 'mealTypes', 'preferences.person'])->find($candidate['id']) : null;
    }

    #[Computed]
    public function people()
    {
        return Person::query()->whereIn('id', $this->session->inputs['person_ids'])->get();
    }

    #[Computed]
    public function lastCooked(): ?CookingEvent
    {
        return $this->recipe ? CookingEvent::query()->where('recipe_id', $this->recipe->id)->active()->orderByDesc('cooked_on')->first() : null;
    }

    #[Computed]
    public function likedBy()
    {
        $ids = $this->session->inputs['person_ids'];

        return $this->recipe
            ? $this->recipe->preferences->filter(fn ($p) => $p->preference->value === 'favorite' && in_array($p->person_id, $ids))->map->person->filter()
            : collect();
    }

    public function skip(RecipeSelectionService $selection): void
    {
        $selection->skip($this->session);
        $this->forget();
    }

    public function undo(RecipeSelectionService $selection): void
    {
        $selection->undo($this->session);
        $this->forget();
    }

    public function wantToCook(): void
    {
        $recipe = $this->recipe;
        if ($recipe === null) {
            return;
        }
        $term = $this->session->inputs['term'];

        $this->dispatch('open-plan-panel', recipeId: $recipe->id, defaults: [
            'person_ids' => $this->session->inputs['person_ids'],
            'meal_type' => $this->session->inputs['meal_type'] ?? 'any',
            'term' => $term['term'] ?? 'today',
            'date' => $term['scheduled_date'] ?? null,
        ], sessionId: $this->sessionId);
    }

    #[On('plan-next')]
    public function planNext(RecipeSelectionService $selection): void
    {
        $this->skip($selection);
    }

    #[On('plan-done')]
    public function planDone(): void
    {
        $this->archiveOneOffGuests();
        $this->redirectRoute('cook.index', navigate: true);
    }

    public function relax(string $rule, RecipeSelectionService $selection): void
    {
        $changes = match ($rule) {
            'dislikes' => ['allow_disliked' => true],
            'not_favorite' => ['only_favorites_of_all' => false],
            'untyped' => ['include_untyped' => true],
            'unknown_time' => ['include_unknown_time' => true],
            'too_long' => ['max_minutes' => null],
            'no_repeat' => ['no_repeat_days' => null],
            'meal_type' => [],
            default => [],
        };

        $new = $selection->restartWith($this->session, $changes);
        $this->redirectRoute('cook.session', $new, navigate: true);
    }

    public function restart(RecipeSelectionService $selection): void
    {
        $new = $selection->restartWith($this->session, []);
        $this->redirectRoute('cook.session', $new, navigate: true);
    }

    private function forget(): void
    {
        unset($this->session, $this->candidate, $this->recipe, $this->lastCooked, $this->likedBy);
    }

    private function archiveOneOffGuests(): void
    {
        $ids = $this->session->inputs['one_off_guest_ids'] ?? [];
        if ($ids !== []) {
            Person::query()->where('household_id', app(CurrentHousehold::class)->id())->whereIn('id', $ids)->whereNull('archived_at')->update(['archived_at' => now()]);
        }
    }
}; ?>

<div class="mx-auto max-w-lg space-y-4" x-data="{
    startX: null,
    onStart(e) { this.startX = e.touches ? e.touches[0].clientX : e.clientX },
    onEnd(e) {
        if (this.startX === null) return;
        const x = e.changedTouches ? e.changedTouches[0].clientX : e.clientX;
        const dx = x - this.startX; this.startX = null;
        if (dx < -80) $wire.skip(); else if (dx > 80) $wire.wantToCook();
    }
}">
    <x-page-header title="Návrh" :back="route('cook.select')">
        <flux:text class="text-sm">{{ app(App\Services\RecipeSelectionService::class)->remainingCount($this->session) }} ďalších</flux:text>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-1 text-sm text-zinc-500">
        <span>Pre:</span>
        @foreach ($this->people as $person)
            <x-person-avatar :person="$person" size="size-5" />
        @endforeach
        @php($mt = $this->session->inputs['meal_type'] ?? null)
        <span>· {{ $mt ? MealType::from($mt)->label() : 'Čokoľvek' }}</span>
        @php($term = $this->session->inputs['term'])
        @if (($term['mode'] ?? 'someday') !== 'someday')
            <span>· {{ $term['mode'] === 'date' ? \Carbon\CarbonImmutable::parse($term['scheduled_date'])->translatedFormat('D j. n.') : 'týždeň od '.\Carbon\CarbonImmutable::parse($term['week_start_date'])->format('j. n.') }}</span>
        @endif
    </div>

    @if ($this->recipe)
        @php($candidate = $this->candidate)
        <article class="overflow-hidden rounded-2xl border border-zinc-200 shadow-sm dark:border-zinc-700" @touchstart="onStart" @touchend="onEnd" data-test="recipe-card">
            <a href="{{ route('recipes.show', ['recipe' => $this->recipe, 'session' => $sessionId]) }}" wire:navigate>
                <x-recipe-cover :recipe="$this->recipe" class="aspect-[4/3] w-full" />
            </a>
            <div class="space-y-3 p-4">
                <div>
                    <flux:heading size="xl">{{ $this->recipe->title }}</flux:heading>
                    @if ($this->recipe->description)
                        <flux:text class="mt-1">{{ $this->recipe->description }}</flux:text>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-500">
                    @if ($this->recipe->totalMinutes())
                        <span class="inline-flex items-center gap-1"><flux:icon name="clock" class="size-4" /> {{ $this->recipe->totalMinutes() }} min</span>
                    @endif
                    @if ($this->recipe->mealTypes->isEmpty())
                        <flux:badge size="sm" color="zinc">Typ jedla nevyplnený</flux:badge>
                    @endif
                    @if ($this->likedBy->isNotEmpty())
                        <span class="inline-flex items-center gap-1">
                            <flux:icon name="heart" class="size-4 text-red-500" />
                            @foreach ($this->likedBy as $person)
                                <x-person-avatar :person="$person" size="size-5" />
                            @endforeach
                        </span>
                    @endif
                    @if ($this->lastCooked)
                        <span>Naposledy {{ $this->lastCooked->cooked_on->translatedFormat('j. n. Y') }}</span>
                    @endif
                </div>

                <flux:text class="text-sm">{{ implode(' • ', $candidate['reasons'] ?? []) }}</flux:text>

                @if (count($this->session->state['available']) === 0 && count($this->session->state['skipped']) === 0)
                    <flux:callout icon="information-circle" variant="secondary">Toto je jediný vhodný kandidát.</flux:callout>
                @endif
            </div>
        </article>

        <div class="grid grid-cols-2 gap-2">
            <flux:button wire:click="skip" icon="x-mark" class="py-4" data-test="skip">Teraz nie</flux:button>
            <flux:button wire:click="wantToCook" variant="primary" icon="check" class="py-4" data-test="want-to-cook">Chcem variť</flux:button>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <flux:button :href="route('recipes.show', ['recipe' => $this->recipe, 'session' => $sessionId])" wire:navigate variant="ghost" icon="book-open">Pozrieť recept</flux:button>
            <flux:button wire:click="undo" variant="ghost" icon="arrow-uturn-left" :disabled="count($this->session->state['skipped']) === 0" data-test="undo">Späť</flux:button>
        </div>
        <flux:text class="text-center text-xs text-zinc-500">Teraz nie iba preskočí kartu v tomto výbere, chute sa nemenia.</flux:text>
    @else
        @php($softCounts = $this->session->candidates['soft_counts'] ?? [])
        @php($hasShown = count($this->session->state['skipped']) > 0)
        <flux:callout icon="face-frown" variant="secondary">
            <flux:callout.heading>{{ $hasShown ? 'Všetko si preskočil' : 'Žiadna zhoda' }}</flux:callout.heading>
            <flux:callout.text>
                @if ($hasShown)
                    Môžeš výber zopakovať alebo upraviť filtre. Karty sa samy neopakujú.
                @else
                    Podľa aktuálnych filtrov sa nenašiel žiadny recept.
                @endif
            </flux:callout.text>
        </flux:callout>

        @if ($softCounts !== [])
            <div class="space-y-2">
                <flux:heading size="sm">Dôvody vylúčenia a čo môžeš zmierniť</flux:heading>
                @foreach ($softCounts as $rule => $count)
                    @php($label = match ($rule) {
                        'dislikes' => 'Nemá rád niekto zo stravníkov',
                        'not_favorite' => 'Nie je obľúbené u všetkých',
                        'untyped' => 'Typ jedla nevyplnený',
                        'unknown_time' => 'Neznámy čas prípravy',
                        'too_long' => 'Trvá dlhšie ako limit',
                        'no_repeat' => 'Varilo sa nedávno',
                        'meal_type' => 'Iný typ jedla',
                        default => $rule,
                    })
                    @php($action = match ($rule) {
                        'dislikes' => 'Pripustiť aj menej obľúbené',
                        'not_favorite' => 'Nevyžadovať obľúbené u všetkých',
                        'untyped' => 'Zahrnúť nezaradené',
                        'unknown_time' => 'Zahrnúť neznámy čas',
                        'too_long' => 'Zrušiť časový limit',
                        'no_repeat' => 'Zrušiť neopakovanie',
                        default => null,
                    })
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <span>{{ $label }} ({{ $count }})</span>
                        @if ($action)
                            <flux:button size="xs" wire:click="relax('{{ $rule }}')">{{ $action }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @php($hard = collect($this->session->candidates['excluded'] ?? [])->where('hard', true))
        @if ($hard->isNotEmpty())
            <flux:text class="text-sm text-zinc-500">{{ $hard->count() }} receptov je vylúčených pevnou výlukou „Neponúkať“. Tá sa zmierňovaním nezruší.</flux:text>
        @endif

        <div class="flex flex-col gap-2 sm:flex-row">
            @if ($hasShown)
                <flux:button wire:click="restart" variant="primary" icon="arrow-path" data-test="restart">Zopakovať výber</flux:button>
                <flux:button wire:click="undo" variant="ghost" icon="arrow-uturn-left">Späť na poslednú kartu</flux:button>
            @endif
            <flux:button :href="route('cook.select')" wire:navigate variant="ghost">Upraviť výber</flux:button>
        </div>
    @endif

    <livewire:plan-panel />
</div>
