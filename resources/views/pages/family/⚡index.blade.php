<?php

use App\Enums\PersonKind;
use App\Models\Household;
use App\Models\Person;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rodina')] class extends Component {
    public string $name = '';

    public string $kind = 'member';

    public string $color = '';

    public ?int $editingId = null;

    public bool $showArchived = false;

    /** @var list<int> */
    public array $defaultPersonIds = [];

    public function mount(): void
    {
        $this->defaultPersonIds = array_map('intval', app(CurrentHousehold::class)->get()->default_person_ids ?? []);
    }

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function people()
    {
        return Person::query()->where('household_id', $this->household->id)
            ->when(! $this->showArchived, fn ($q) => $q->active())
            ->orderBy('archived_at')->orderBy('kind')->orderBy('name')->get();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()->can('edit', $this->household);
    }

    public function save(): void
    {
        $this->authorize('edit', $this->household);
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['in:member,guest'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.required' => 'Zadaj meno.']);

        $data = ['name' => trim($this->name), 'kind' => PersonKind::from($this->kind), 'color' => $this->color ?: null];

        if ($this->editingId) {
            $person = Person::query()->where('household_id', $this->household->id)->findOrFail($this->editingId);
            $person->update($data);
        } else {
            Person::create($data + ['household_id' => $this->household->id]);
        }

        $this->reset('name', 'kind', 'color', 'editingId');
        unset($this->people);
    }

    public function edit(int $personId): void
    {
        $person = Person::query()->where('household_id', $this->household->id)->findOrFail($personId);
        $this->editingId = $person->id;
        $this->name = $person->name;
        $this->kind = $person->kind->value;
        $this->color = $person->color ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset('name', 'kind', 'color', 'editingId');
    }

    public function archive(int $personId): void
    {
        $this->authorize('edit', $this->household);
        Person::query()->where('household_id', $this->household->id)->findOrFail($personId)->update(['archived_at' => now()]);
        unset($this->people);
    }

    public function restore(int $personId): void
    {
        $this->authorize('edit', $this->household);
        Person::query()->where('household_id', $this->household->id)->findOrFail($personId)->update(['archived_at' => null]);
        unset($this->people);
    }

    public function saveDefaults(): void
    {
        $this->authorize('edit', $this->household);
        $ids = Person::query()->where('household_id', $this->household->id)->active()->whereIn('id', $this->defaultPersonIds)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->household->update(['default_person_ids' => $ids]);
        \Flux\Flux::toast(variant: 'success', text: 'Predvolená skupina uložená.');
    }

    public function setActive(int $personId): void
    {
        $person = Person::query()->where('household_id', $this->household->id)->active()->findOrFail($personId);
        app(CurrentHousehold::class)->setActivePerson($person);
        \Flux\Flux::toast(text: 'Upravuješ chute: '.$person->name);
    }
}; ?>

<div class="mx-auto max-w-2xl space-y-6">
    <x-page-header title="Rodina a hostia" subtitle="Stravníci nepotrebujú vlastný účet. Dvaja ľudia môžu mať rovnaké meno.">
        <flux:button :href="route('household.edit')" wire:navigate variant="ghost" icon="cog-6-tooth" size="sm">Domácnosť</flux:button>
    </x-page-header>

    @if ($this->canEdit)
        <flux:card class="space-y-3">
            <form wire:submit="save" class="space-y-3">
                <flux:heading size="lg" class="font-display">{{ $editingId ? 'Upraviť stravníka' : 'Nový stravník' }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="name" label="Meno" placeholder="napr. Eva" data-test="person-name" />
                    <flux:select wire:model="kind" label="Typ">
                        <flux:select.option value="member">Člen</flux:select.option>
                        <flux:select.option value="guest">Hosť</flux:select.option>
                    </flux:select>
                    <flux:input type="color" wire:model="color" label="Farba (voliteľné)" />
                </div>
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" data-test="person-save">{{ $editingId ? 'Uložiť' : 'Pridať' }}</flux:button>
                    @if ($editingId)<flux:button wire:click="cancelEdit" variant="ghost">Zrušiť</flux:button>@endif
                </div>
            </form>
        </flux:card>
    @endif

    <section class="space-y-2">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" class="font-display">Stravníci</flux:heading>
            <flux:checkbox wire:model.live="showArchived" label="Aj archivovaní" />
        </div>
        @foreach ($this->people as $person)
            <flux:card size="sm" class="flex items-center gap-3 !p-2.5 {{ $person->archived_at ? 'opacity-60' : '' }}" wire:key="person-{{ $person->id }}">
                <x-person-avatar :person="$person" size="size-9" />
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $person->name }}</div>
                    <div class="text-xs text-zinc-500">
                        {{ $person->isGuest() ? 'Hosť' : 'Člen' }}
                        @if ($person->user_id) · prepojený účet @endif
                        @if ($person->archived_at) · archivovaný @endif
                    </div>
                </div>
                @if ($this->canEdit)
                    <flux:dropdown align="end">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="Akcie" />
                        <flux:menu>
                            <flux:menu.item wire:click="setActive({{ $person->id }})" icon="heart">Upravovať chute tohto človeka</flux:menu.item>
                            @if ($person->archived_at)
                                <flux:menu.item wire:click="restore({{ $person->id }})" icon="arrow-uturn-left">Obnoviť</flux:menu.item>
                            @else
                                <flux:menu.item wire:click="edit({{ $person->id }})" icon="pencil">Upraviť</flux:menu.item>
                                <flux:menu.item wire:click="archive({{ $person->id }})" icon="archive-box">Archivovať</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </flux:card>
        @endforeach
    </section>

    @if ($this->canEdit)
        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">Predvolená skupina stravníkov</flux:heading>
            <flux:text class="text-sm">Použije sa pri ďalšom otvorení generátora a plánovania.</flux:text>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->people->whereNull('archived_at') as $person)
                    <label class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition {{ in_array($person->id, $defaultPersonIds) ? 'border-accent bg-accent/10 font-medium text-accent-content' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-600' }}">
                        <input type="checkbox" wire:model.live="defaultPersonIds" value="{{ $person->id }}" class="sr-only" />
                        <x-person-avatar :person="$person" size="size-5" /> {{ $person->name }}
                    </label>
                @endforeach
            </div>
            <flux:button wire:click="saveDefaults" size="sm" variant="primary">Uložiť skupinu</flux:button>
        </flux:card>

        <livewire:household-invitations />
    @endif
</div>
