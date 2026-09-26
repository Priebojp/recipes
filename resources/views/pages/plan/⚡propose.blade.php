<?php

use App\Enums\MealType;
use App\Enums\PlusFeature;
use App\Models\Household;
use App\Models\Person;
use App\Models\SelectionPreset;
use App\Services\PlanningCalendar;
use App\Services\Plus\PlusAccess;
use App\Services\Plus\SelectionPresets;
use App\Services\Plus\WeeklyMenuPlanner;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Návrh týždenného jedálnička')] class extends Component {
    #[Url]
    public ?string $week = null;

    /** @var list<int> */
    public array $personIds = [];

    /** @var list<string> */
    public array $mealTypes = ['dinner'];

    /** @var list<string> */
    public array $days = ['0', '1', '2', '3', '4', '5', '6'];

    public bool $includeUntyped = true;

    public bool $onlyFavoritesOfAll = false;

    public bool $allowDisliked = false;

    public ?int $maxMinutes = null;

    public bool $includeUnknownTime = false;

    public ?int $noRepeatDays = null;

    public ?int $servings = null;

    /** @var list<array<string, mixed>> */
    public array $proposal = [];

    public bool $confirmed = false;

    public int $createdCount = 0;

    /** @var list<string> */
    public array $skipped = [];

    public string $error = '';

    public function mount(): void
    {
        $household = $this->household;
        $calendar = $this->calendar;

        if ($this->week === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) !== 1) {
            $this->week = $calendar->nextWeekStart()->toDateString();
        } else {
            $this->week = $calendar->weekStartOf($calendar->date($this->week))->toDateString();
        }

        $this->personIds = array_values(array_intersect(array_map('intval', $household->default_person_ids ?? []), $this->people->pluck('id')->all()));
        $this->servings = count($this->personIds) ?: null;
    }

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function calendar(): PlanningCalendar
    {
        return new PlanningCalendar($this->household->timezone);
    }

    #[Computed]
    public function isPlus(): bool
    {
        return app(PlusAccess::class)->allows($this->household, PlusFeature::WeeklyMenu);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()->can('edit', $this->household);
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->calendar->date($this->week);
    }

    /** @return Collection<int, Person> */
    #[Computed]
    public function people(): Collection
    {
        return Person::query()->where('household_id', $this->household->id)->active()->orderByDesc('kind')->orderBy('name')->get();
    }

    /** @return Collection<int, SelectionPreset> */
    #[Computed]
    public function presets(): Collection
    {
        return app(SelectionPresets::class)->forHousehold($this->household);
    }

    #[Computed]
    public function recipes(): Collection
    {
        return app(WeeklyMenuPlanner::class)->recipesOf($this->household, $this->proposal);
    }

    public function updatedPersonIds(): void
    {
        if ($this->servings === null || $this->servings === count($this->personIds) + 1 || $this->servings === count($this->personIds) - 1) {
            $this->servings = count($this->personIds) ?: null;
        }
    }

    public function updatedWeek(): void
    {
        $this->proposal = [];
        $this->confirmed = false;
        unset($this->weekStart);
    }

    public function applyPreset(int $presetId): void
    {
        $preset = SelectionPreset::query()->where('household_id', $this->household->id)->findOrFail($presetId);
        $this->authorize('view', $preset);

        $this->personIds = $preset->activePersonIds();
        $this->mealTypes = [$preset->meal_type?->value ?? 'any'];
        $filters = $preset->selectionFilters();
        $this->includeUntyped = $filters->includeUntyped;
        $this->onlyFavoritesOfAll = $filters->onlyFavoritesOfAll;
        $this->allowDisliked = $filters->allowDisliked;
        $this->maxMinutes = $filters->maxMinutes;
        $this->includeUnknownTime = $filters->includeUnknownTime;
        $this->noRepeatDays = $filters->noRepeatDays;
        $this->servings = count($this->personIds) ?: null;
    }

    public function propose(WeeklyMenuPlanner $planner): void
    {
        $this->guard();
        $this->validate([
            'personIds' => ['required', 'array', 'min:1'],
            'mealTypes' => ['required', 'array', 'min:1'],
            'days' => ['required', 'array', 'min:1'],
            'maxMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'noRepeatDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:200'],
        ], [
            'personIds.required' => 'Vyber aspoň jedného stravníka.',
            'personIds.min' => 'Vyber aspoň jedného stravníka.',
            'mealTypes.required' => 'Vyber aspoň jeden typ jedla.',
            'mealTypes.min' => 'Vyber aspoň jeden typ jedla.',
            'days.required' => 'Vyber aspoň jeden deň.',
            'days.min' => 'Vyber aspoň jeden deň.',
        ]);

        try {
            $this->proposal = $planner->propose($this->household, $this->request());
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->error = '';
        $this->confirmed = false;
        unset($this->recipes);
    }

    public function reroll(int $index, WeeklyMenuPlanner $planner): void
    {
        $this->guard();
        if ($this->proposal === []) {
            return;
        }

        $this->proposal = $planner->reroll($this->household, $this->request(), $this->proposal, $index);
        unset($this->recipes);
    }

    public function clearSlot(int $index): void
    {
        if (isset($this->proposal[$index]) && ! $this->proposal[$index]['occupied']) {
            $this->proposal[$index] = array_merge($this->proposal[$index], ['recipe_id' => null, 'title' => null, 'reasons' => [], 'note' => 'Vynechané']);
        }
    }

    public function confirm(WeeklyMenuPlanner $planner): void
    {
        $this->guard();
        if ($this->proposal === []) {
            return;
        }

        try {
            $result = $planner->confirm($this->household, $this->proposal, $this->personIds, $this->servings, auth()->user());
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->createdCount = count($result['created']);
        $this->skipped = $result['skipped'];
        $this->confirmed = true;
        $this->proposal = [];
        $this->error = '';
    }

    /** @return array{week_start: string, days: list<int>, meal_types: list<string>, person_ids: list<int>, filters: array<string, mixed>} */
    private function request(): array
    {
        return [
            'week_start' => $this->week,
            'days' => array_map('intval', $this->days),
            'meal_types' => $this->mealTypes,
            'person_ids' => array_map('intval', $this->personIds),
            'filters' => [
                'include_untyped' => $this->includeUntyped,
                'only_favorites_of_all' => $this->onlyFavoritesOfAll,
                'allow_disliked' => $this->allowDisliked,
                'max_minutes' => $this->maxMinutes,
                'include_unknown_time' => $this->includeUnknownTime,
                'no_repeat_days' => $this->noRepeatDays,
            ],
        ];
    }

    private function guard(): void
    {
        app(PlusAccess::class)->assert($this->household, PlusFeature::WeeklyMenu);
        $this->authorize('edit', $this->household);
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-page-header title="Návrh týždenného jedálnička" :back="route('plan.index', ['week' => $week])" subtitle="Rovnaký generátor ako pri jednom jedle, len pre celý týždeň naraz. Bez AI." />

    @if (! $this->isPlus)
        <x-plus-gate :feature="App\Enums\PlusFeature::WeeklyMenu" :household="$this->household" />
    @elseif (! $this->canEdit)
        <flux:callout icon="lock-closed">Plán môže meniť vlastník alebo spolupracovník domácnosti.</flux:callout>
    @elseif ($confirmed)
        <flux:callout icon="check-circle" variant="success" data-test="menu-confirmed">
            <flux:callout.heading>{{ trans_choice('{0} Nič sa nenaplánovalo|{1} 1 jedlo je v pláne|[2,4] :count jedlá sú v pláne|[5,*] :count jedál je v pláne', $createdCount) }}</flux:callout.heading>
            <flux:callout.text>
                Uvarenie potvrdíš neskôr v Pláne – naplánovanie sa do histórie nepočíta.
                @if ($skipped !== [])
                    <div class="mt-2">Vynechané, lebo sa medzičasom zmenili (archív alebo výluka): {{ implode(', ', $skipped) }}.</div>
                @endif
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('plan.index', ['week' => $week])" wire:navigate variant="primary" size="sm">Otvoriť plán</flux:button>
                <flux:button :href="route('plan.shopping', ['week' => $week])" wire:navigate size="sm" icon="shopping-cart">Nákupný zoznam</flux:button>
            </x-slot>
        </flux:callout>
        <script type="application/json" id="mr-analytics-event">@json(['name' => 'meal_planned', 'properties' => ['mode' => 'week']])</script>
    @else
        <form wire:submit="propose" class="space-y-6">
            <flux:radio.group wire:model.live="week" label="Týždeň" variant="segmented">
                <flux:radio :value="$this->calendar->thisWeekStart()->toDateString()" label="Tento týždeň" />
                <flux:radio :value="$this->calendar->nextWeekStart()->toDateString()" label="Budúci týždeň" />
            </flux:radio.group>
            <flux:text class="-mt-4 text-sm">Od {{ $this->weekStart->format('j. n.') }} do {{ $this->weekStart->addDays(6)->format('j. n. Y') }}</flux:text>

            @if ($this->presets->isNotEmpty())
                <flux:fieldset>
                    <flux:legend>Šablóna</flux:legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->presets as $preset)
                            <flux:button size="sm" wire:click="applyPreset({{ $preset->id }})" icon="bookmark" wire:key="preset-{{ $preset->id }}" data-test="apply-preset-{{ $preset->id }}">{{ $preset->name }}</flux:button>
                        @endforeach
                    </div>
                </flux:fieldset>
            @endif

            <flux:fieldset>
                <flux:legend>Pre koho varíš?</flux:legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->people as $person)
                        <label class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm transition {{ in_array($person->id, $personIds) ? 'border-accent bg-accent/10 font-medium text-accent-content' : 'border-zinc-300 bg-white hover:border-zinc-400 dark:border-zinc-600 dark:bg-zinc-800' }}">
                            <input type="checkbox" wire:model.live="personIds" value="{{ $person->id }}" class="sr-only" />
                            <x-person-avatar :person="$person" size="size-6" />
                            {{ $person->name }}
                            @if ($person->isGuest())<flux:badge size="sm" color="zinc">hosť</flux:badge>@endif
                        </label>
                    @endforeach
                </div>
                @error('personIds')<flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text>@enderror
            </flux:fieldset>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:checkbox.group wire:model="mealTypes" label="Ktoré jedlá">
                    @foreach (MealType::cases() as $type)
                        <flux:checkbox :value="$type->value" :label="$type->label()" />
                    @endforeach
                    <flux:checkbox value="any" label="Jedno jedlo denne, čokoľvek" />
                </flux:checkbox.group>

                <flux:checkbox.group wire:model="days" label="Ktoré dni">
                    @for ($i = 0; $i < 7; $i++)
                        <flux:checkbox :value="(string) $i" :label="ucfirst($this->weekStart->addDays($i)->translatedFormat('l j. n.'))" />
                    @endfor
                </flux:checkbox.group>
            </div>
            @error('mealTypes')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror
            @error('days')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror

            <flux:input type="number" min="1" wire:model="servings" label="Porcie na jedlo" description="Návrh: 1 osoba = 1 porcia." class="sm:max-w-xs" />

            <flux:card size="sm" class="!py-1">
                <flux:accordion transition>
                    <flux:accordion.item heading="Voliteľné filtre">
                        <div class="space-y-4 pt-2">
                            <flux:input type="number" min="1" wire:model.live="maxMinutes" label="Najviac minút (celkový čas)" placeholder="napr. 30" />
                            @if ($maxMinutes)
                                <flux:checkbox wire:model="includeUnknownTime" label="Zahrnúť aj jedlá s neznámym časom" />
                            @endif
                            <flux:checkbox wire:model="onlyFavoritesOfAll" label="Iba obľúbené všetkých" />
                            <flux:checkbox wire:model="allowDisliked" label="Pripustiť aj menej obľúbené (Nemá rád dostane nízku váhu)" />
                            <flux:checkbox wire:model="includeUntyped" label="Zahrnúť recepty bez typu jedla" />
                            <flux:input type="number" min="1" wire:model="noRepeatDays" label="Neopakovať posledných X dní (prísny filter)" placeholder="napr. 14" />
                        </div>
                    </flux:accordion.item>
                </flux:accordion>
            </flux:card>

            @if ($error)
                <flux:callout icon="exclamation-circle" variant="danger">{{ $error }}</flux:callout>
            @endif

            <flux:button type="submit" variant="primary" icon="sparkles" class="w-full py-4 text-base" data-test="propose-week">{{ $proposal === [] ? 'Navrhnúť týždeň' : 'Navrhnúť celý týždeň znova' }}</flux:button>
        </form>

        @if ($proposal !== [])
            <section class="space-y-3" data-test="menu-proposal">
                <flux:heading size="lg" class="font-display">Návrh</flux:heading>
                <flux:text class="text-sm">Nič sa ešte neuložilo. Vymeň, čo sa nehodí, a potom potvrď. Každý recept je v týždni najviac raz; už naplánované jedlá zostávajú.</flux:text>

                @foreach ($proposal as $index => $slot)
                    @php($recipe = $slot['recipe_id'] ? $this->recipes->get($slot['recipe_id']) : null)
                    <flux:card size="sm" class="flex items-center gap-3 !p-2.5 {{ $slot['occupied'] ? 'opacity-70' : '' }}" wire:key="slot-{{ $index }}" data-test="slot-{{ $index }}">
                        <div class="w-20 shrink-0 text-xs font-semibold leading-tight">
                            <div class="capitalize">{{ CarbonImmutable::parse($slot['date'])->translatedFormat('D j. n.') }}</div>
                            <div class="font-normal text-zinc-500">{{ $slot['meal_type'] === 'any' ? 'Jedlo' : MealType::from($slot['meal_type'])->label() }}</div>
                        </div>
                        @if ($recipe)
                            <x-recipe-cover :recipe="$recipe" conversion="thumb" class="size-14 shrink-0 rounded-lg" />
                        @endif
                        <div class="min-w-0 flex-1">
                            @if ($slot['recipe_id'])
                                @if ($recipe)
                                    <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="block truncate font-medium">{{ $recipe->title }}</a>
                                @else
                                    <div class="truncate font-medium">{{ $slot['title'] }}</div>
                                @endif
                                <div class="truncate text-xs text-zinc-500">
                                    @if ($slot['occupied'])
                                        Už naplánované – zostáva
                                    @else
                                        {{ implode(' · ', $slot['reasons']) }}
                                    @endif
                                </div>
                            @else
                                <div class="text-sm text-zinc-500">{{ $slot['note'] ?? 'Bez návrhu' }}</div>
                            @endif
                        </div>
                        @unless ($slot['occupied'])
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="reroll({{ $index }})" aria-label="Vymeniť" data-test="reroll-{{ $index }}" />
                            @if ($slot['recipe_id'])
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearSlot({{ $index }})" aria-label="Vynechať" />
                            @endif
                        @endunless
                    </flux:card>
                @endforeach

                <div class="flex flex-col gap-2 sm:flex-row">
                    <flux:button variant="primary" icon="check" wire:click="confirm" class="w-full" data-test="confirm-week">Uložiť do plánu</flux:button>
                    <flux:button :href="route('plan.index', ['week' => $week])" wire:navigate variant="ghost" class="w-full">Zahodiť</flux:button>
                </div>
            </section>
        @endif
    @endif
</div>
