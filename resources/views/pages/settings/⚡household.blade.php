<?php

use App\Models\Household;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Domácnosť')] class extends Component {
    public string $name = '';

    public string $timezone = '';

    public function mount(): void
    {
        $household = app(CurrentHousehold::class)->get();
        $this->name = $household->name;
        $this->timezone = $household->timezone;
    }

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function timezones(): array
    {
        return ['Europe/Bratislava', 'Europe/Prague', 'Europe/Vienna', 'Europe/Budapest', 'Europe/Warsaw', 'Europe/Berlin', 'Europe/London', 'UTC'];
    }

    public function save(): void
    {
        $this->authorize('manage', $this->household);
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->household->update(['name' => $this->name, 'timezone' => $this->timezone]);
        \Flux\Flux::toast(variant: 'success', text: 'Uložené.');
    }
}; ?>

<div class="mx-auto max-w-xl space-y-6">
    <x-page-header title="Nastavenia domácnosti" :back="route('family.index')" />

    <form wire:submit="save" class="space-y-4">
        <flux:input wire:model="name" label="Názov domácnosti" />
        <flux:select wire:model="timezone" label="Časová zóna" description="Určuje „dnes“, „zajtra“ aj hranice týždňa.">
            @foreach ($this->timezones as $tz)
                <flux:select.option :value="$tz">{{ $tz }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" :disabled="! auth()->user()->can('manage', $this->household)">Uložiť</flux:button>
    </form>

    <section class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">Export údajov</flux:heading>
        <flux:text class="text-sm">ZIP s JSON (recepty, pôvodné texty, chute, plány, história) a všetkými obrázkami.</flux:text>
        <flux:button :href="route('export')" icon="arrow-down-tray" size="sm">Stiahnuť export</flux:button>
    </section>

    <section class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">AI</flux:heading>
        @php($ai = app(App\Services\Ai\AiAvailability::class))
        <flux:text class="text-sm">
            Text: {{ $ai->textConfigured() ? 'nakonfigurované ('.$ai->textProvider().')' : 'nenakonfigurované – chýba API kľúč' }}<br>
            Obrázky: {{ $ai->imageConfigured() ? 'nakonfigurované ('.$ai->imageProvider().')' : 'nenakonfigurované – chýba API kľúč' }}
        </flux:text>
    </section>
</div>
