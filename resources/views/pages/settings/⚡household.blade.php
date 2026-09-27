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
        \Flux\Flux::toast(variant: 'success', text: __('Uložené.'));
    }
}; ?>

<div class="mx-auto max-w-xl space-y-6">
    <x-page-header :title="__('Nastavenia domácnosti')" :back="route('family.index')" />

    <flux:card class="space-y-4">
        <form wire:submit="save" class="space-y-4">
            <flux:input wire:model="name" :label="__('Názov domácnosti')" />
            <flux:select wire:model="timezone" :label="__('Časová zóna')" :description="__('Určuje „dnes“, „zajtra“ aj hranice týždňa.')">
                @foreach ($this->timezones as $tz)
                    <flux:select.option :value="$tz">{{ $tz }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" :disabled="! auth()->user()->can('manage', $this->household)">{{ __('Uložiť') }}</flux:button>
        </form>
    </flux:card>

    <flux:card class="space-y-2">
        <flux:heading size="lg" class="font-display">{{ __('Export údajov') }}</flux:heading>
        <flux:text class="text-sm">{{ __('ZIP s JSON (recepty, pôvodné texty, chute, plány, história) a všetkými obrázkami.') }}</flux:text>
        <flux:button :href="route('export')" icon="arrow-down-tray" size="sm">{{ __('Stiahnuť export') }}</flux:button>
    </flux:card>

    <flux:card class="space-y-2">
        <flux:heading size="lg" class="font-display">AI</flux:heading>
        @php($ai = app(App\Services\Ai\AiAvailability::class))
        <flux:text class="text-sm">
            {{ $ai->textConfigured() ? __('Text: nakonfigurované (:provider)', ['provider' => $ai->textProvider()]) : __('Text: nenakonfigurované – chýba API kľúč') }}<br>
            {{ $ai->imageConfigured() ? __('Obrázky: nakonfigurované (:provider)', ['provider' => $ai->imageProvider()]) : __('Obrázky: nenakonfigurované – chýba API kľúč') }}
        </flux:text>
    </flux:card>
</div>
