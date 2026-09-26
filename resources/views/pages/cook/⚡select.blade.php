<?php

use App\Enums\MealType;
use App\Enums\PersonKind;
use App\Models\Person;
use App\Services\PlanningCalendar;
use App\Services\RecipeSelectionService;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vyber mi jedlo')] class extends Component {
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
            'filters' => [
                'include_untyped' => $this->includeUntyped,
                'only_favorites_of_all' => $this->onlyFavoritesOfAll,
                'allow_disliked' => $this->allowDisliked,
                'max_minutes' => $this->maxMinutes,
                'include_unknown_time' => $this->includeUnknownTime,
                'no_repeat_days' => $this->noRepeatDays,
            ],
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
