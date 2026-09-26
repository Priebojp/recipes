<?php

use App\Enums\MealType;
use App\Enums\PersonKind;
use App\Enums\PlusFeature;
use App\Models\Person;
use App\Models\SelectionPreset;
use App\Services\PlanningCalendar;
use App\Services\Plus\PlusAccess;
use App\Services\Plus\SelectionPresets;
use App\Services\RecipeSelectionService;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vyber mi jedlo')] class extends Component {
    public bool $showPresetForm = false;

    public string $presetName = '';

    public string $presetNotice = '';

    /** @var list<int> */
    public array $personIds = [];

    public string $mealType = 'any';

    public string $term = 'unknown';

    public ?string $date = null;

    public bool $includeUntyped = true;

    public bool $onlyFavoritesOfAll = false;

    public bool $allowDisliked = false;

    public ?int $maxMinutes = null;

    public bool $includeUnknownTime = false;

    public ?int $noRepeatDays = null;

    public bool $showGuestForm = false;

    public string $guestName = '';

    public bool $guestSave = true;

    /** @var list<int> */
    public array $oneOffGuestIds = [];

    public string $error = '';

    public function mount(): void
    {
        $household = app(CurrentHousehold::class)->get();
        $last = session('last_selection_inputs.'.$household->id);

        $this->personIds = array_map('intval', $last['person_ids'] ?? ($household->default_person_ids ?? []));
        $this->personIds = array_values(array_intersect($this->personIds, $this->people->pluck('id')->all()));
        $this->mealType = MealType::suggestForHour((new PlanningCalendar($household->timezone))->localHour())->value;
    }

    #[Computed]
    public function people()
    {
        return Person::query()->where('household_id', app(CurrentHousehold::class)->id())->active()->orderByDesc('kind')->orderBy('name')->get();
    }

    /** @return Collection<int, SelectionPreset> */
    #[Computed]
    public function presets(): Collection
    {
        return app(SelectionPresets::class)->forHousehold(app(CurrentHousehold::class)->get());
    }

    #[Computed]
    public function canSavePresets(): bool
    {
        return app(PlusAccess::class)->allows(app(CurrentHousehold::class)->get(), PlusFeature::SelectionPresets);
    }

    #[Computed]
    public function canEditPresets(): bool
    {
        return auth()->user()->can('edit', app(CurrentHousehold::class)->get());
    }

    /** Applying a saved preset only fills the form; it stays possible after Plus ends so nothing becomes inaccessible. */
    public function applyPreset(int $presetId): void
    {
        $preset = SelectionPreset::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($presetId);
        $this->authorize('view', $preset);

        $this->personIds = $preset->activePersonIds();
        $this->mealType = $preset->meal_type?->value ?? 'any';
        $filters = $preset->selectionFilters();
        $this->includeUntyped = $filters->includeUntyped;
        $this->onlyFavoritesOfAll = $filters->onlyFavoritesOfAll;
        $this->allowDisliked = $filters->allowDisliked;
        $this->maxMinutes = $filters->maxMinutes;
        $this->includeUnknownTime = $filters->includeUnknownTime;
        $this->noRepeatDays = $filters->noRepeatDays;
        $this->presetNotice = 'Šablóna „'.$preset->name.'“ je použitá.';
        $this->error = '';
    }

    public function savePreset(SelectionPresets $presets): void
    {
        $household = app(CurrentHousehold::class)->get();
        app(PlusAccess::class)->assert($household, PlusFeature::SelectionPresets);
        $this->authorize('edit', $household);
        $this->validate(['presetName' => ['required', 'string', 'max:60']], ['presetName.required' => 'Zadaj názov šablóny.']);

        try {
            $preset = $presets->save($household, $this->presetName, [
                'person_ids' => $this->personIds,
                'meal_type' => $this->mealType,
                'filters' => $this->filters(),
            ], auth()->user());
        } catch (\InvalidArgumentException $e) {
            $this->addError('presetName', $e->getMessage());

            return;
        }

        $this->presetName = '';
        $this->showPresetForm = false;
        $this->presetNotice = 'Šablóna „'.$preset->name.'“ je uložená.';
        unset($this->presets);
    }

    public function deletePreset(int $presetId, SelectionPresets $presets): void
    {
        $preset = SelectionPreset::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($presetId);
        $this->authorize('delete', $preset);
        $presets->delete($preset);
        $this->presetNotice = '';
        unset($this->presets);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'include_untyped' => $this->includeUntyped,
            'only_favorites_of_all' => $this->onlyFavoritesOfAll,
            'allow_disliked' => $this->allowDisliked,
            'max_minutes' => $this->maxMinutes,
            'include_unknown_time' => $this->includeUnknownTime,
            'no_repeat_days' => $this->noRepeatDays,
        ];
    }

    public function addGuest(): void
    {
        $this->validate(['guestName' => ['required', 'string', 'max:100']], ['guestName.required' => 'Zadaj meno hosťa.']);

        $person = Person::create([
            'household_id' => app(CurrentHousehold::class)->id(),
            'name' => trim($this->guestName),
            'kind' => PersonKind::Guest,
        ]);

        if (! $this->guestSave) {
            $this->oneOffGuestIds[] = $person->id;
        }

        $this->personIds[] = $person->id;
        $this->guestName = '';
        $this->showGuestForm = false;
        unset($this->people);
    }

    public function start(RecipeSelectionService $selection): void
    {
        $this->validate([
            'personIds' => ['required', 'array', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d', 'required_if:term,date'],
            'maxMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'noRepeatDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'personIds.required' => 'Vyber aspoň jedného stravníka.',
            'personIds.min' => 'Vyber aspoň jedného stravníka.',
            'date.required_if' => 'Vyber dátum.',
        ]);

        $household = app(CurrentHousehold::class)->get();
        $inputs = [
            'person_ids' => $this->personIds,
            'meal_type' => $this->mealType,
            'term' => $this->term,
            'date' => $this->date,
            'filters' => $this->filters(),
        ];

        try {
            $session = $selection->start($household, auth()->user(), $inputs);
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($this->oneOffGuestIds !== []) {
            $session->inputs = array_merge($session->inputs, ['one_off_guest_ids' => $this->oneOffGuestIds]);
            $session->save();
        }

        session(['last_selection_inputs.'.$household->id => ['person_ids' => $this->personIds]]);

        $this->redirectRoute('cook.session', $session, navigate: true);
    }
}; ?>

<div class="mx-auto max-w-2xl space-y-6">
    <x-page-header title="Vyber mi jedlo" :back="route('cook.index')" />

    <form wire:submit="start" class="space-y-6">
        @if ($this->presets->isNotEmpty() || $this->canEditPresets)
            <flux:card size="sm" class="space-y-3" data-test="presets">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="sm" class="me-1">Šablóny</flux:heading>
                    @foreach ($this->presets as $preset)
                        <span class="inline-flex items-center rounded-full border border-zinc-300 bg-white text-sm dark:border-zinc-600 dark:bg-zinc-800" wire:key="preset-{{ $preset->id }}">
                            <button type="button" wire:click="applyPreset({{ $preset->id }})" class="flex items-center gap-1.5 rounded-full px-3 py-1.5 hover:text-accent" data-test="apply-preset-{{ $preset->id }}">
                                <flux:icon name="bookmark" class="size-4" />{{ $preset->name }}
                            </button>
                            @if ($this->canEditPresets)
                                <button type="button" wire:click="deletePreset({{ $preset->id }})" wire:confirm="Odstrániť šablónu „{{ $preset->name }}“?" class="pe-2 text-zinc-400 hover:text-red-600" aria-label="Odstrániť šablónu {{ $preset->name }}" data-test="delete-preset-{{ $preset->id }}">
                                    <flux:icon name="x-mark" class="size-4" />
                                </button>
                            @endif
                        </span>
                    @endforeach
                    @if ($this->presets->isEmpty())
                        <flux:text class="text-sm">Ulož si skupinu stravníkov a filtre, napr. „Rodina“ alebo „Návšteva“.</flux:text>
                    @endif
                    @if ($this->canEditPresets)
                        @if ($this->canSavePresets)
                            <flux:button size="sm" variant="ghost" icon="bookmark-square" wire:click="$toggle('showPresetForm')" data-test="save-preset-toggle">Uložiť ako šablónu</flux:button>
                        @else
                            <flux:link :href="route('pricing')" wire:navigate class="text-xs">Ukladanie šablón je súčasťou Plus</flux:link>
                        @endif
                    @endif
                </div>
                @if ($presetNotice)
                    <flux:text class="text-sm text-accent-content" data-test="preset-notice">{{ $presetNotice }}</flux:text>
                @endif
                @if ($showPresetForm && $this->canSavePresets)
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <flux:input wire:model="presetName" label="Názov šablóny" placeholder="napr. Rodina" description="Uloží aktuálnych stravníkov, typ jedla a filtre. Rovnaký názov šablónu prepíše." class="flex-1" />
                        <flux:button wire:click="savePreset" size="sm" variant="primary" data-test="save-preset">Uložiť</flux:button>
                    </div>
                @endif
            </flux:card>
        @endif

        <flux:fieldset>
            <flux:legend>1. Pre koho varíš?</flux:legend>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->people as $person)
                    <label class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm transition {{ in_array($person->id, $personIds) ? 'border-accent bg-accent/10 font-medium text-accent-content' : 'border-zinc-300 bg-white hover:border-zinc-400 dark:border-zinc-600 dark:bg-zinc-800' }}">
                        <input type="checkbox" wire:model.live="personIds" value="{{ $person->id }}" class="sr-only" />
                        <x-person-avatar :person="$person" size="size-6" />
                        {{ $person->name }}
                        @if ($person->isGuest())<flux:badge size="sm" color="zinc">hosť</flux:badge>@endif
                    </label>
                @endforeach
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="$toggle('showGuestForm')">Pridať hosťa</flux:button>
            </div>
            @error('personIds')<flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text>@enderror

            @if ($showGuestForm)
                <div class="mt-3 flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 sm:flex-row sm:items-end dark:border-zinc-700">
                    <flux:input wire:model="guestName" label="Meno hosťa" placeholder="napr. Babka" class="flex-1" />
                    <flux:checkbox wire:model="guestSave" label="Uložiť na nabudúce" />
                    <flux:button wire:click="addGuest" size="sm" variant="primary" data-test="add-guest">Pridať</flux:button>
                </div>
            @endif
        </flux:fieldset>

        <flux:radio.group wire:model="mealType" label="2. Čo vyberáme?" variant="segmented">
            @foreach (MealType::cases() as $type)
                <flux:radio :value="$type->value" :label="$type->label()" />
            @endforeach
            <flux:radio value="any" label="Čokoľvek" />
        </flux:radio.group>

        <flux:radio.group wire:model.live="term" label="3. Kedy? (voliteľné)">
            <flux:radio value="today" label="Dnes" />
            <flux:radio value="tomorrow" label="Zajtra" />
            <flux:radio value="date" label="Konkrétny dátum" />
            <flux:radio value="next_week" label="Budúci týždeň" />
            <flux:radio value="unknown" label="Zatiaľ neviem" />
        </flux:radio.group>
        @if ($term === 'date')
            <flux:date-picker wire:model="date" label="Dátum" with-today clearable />
        @endif

        <flux:card size="sm" class="!py-1">
            <flux:accordion transition>
                <flux:accordion.item heading="4. Voliteľné filtre">
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

        <flux:button type="submit" variant="primary" icon="sparkles" class="w-full py-4 text-base" data-test="show-suggestion">Ukáž návrh</flux:button>
    </form>
</div>
