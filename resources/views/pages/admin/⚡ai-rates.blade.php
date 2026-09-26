<?php

use App\Models\AiCostRate;
use App\Services\Admin\AdminAuditor;
use App\Services\Ai\AiSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Versioned list prices used for cost estimates. Append-only: a new price is a new row with its own effective_from.
 */
new #[Layout('layouts::admin')] #[Title('Cenník AI')] class extends Component {
    public string $provider = 'openai';

    public string $model = '';

    public string $modality = AiCostRate::MODALITY_TEXT;

    public string $quality = '';

    public string $size = '';

    public string $input_usd = '';

    public string $cached_input_usd = '';

    public string $output_usd = '';

    public string $per_unit_usd = '';

    public string $effective_from = '';

    public string $source = '';

    public string $note = '';

    public function mount(): void
    {
        $this->effective_from = CarbonImmutable::now(config('recipes.default_timezone'))->toDateString();
    }

    #[Computed]
    public function rates(): Collection
    {
        return AiCostRate::query()->orderBy('modality')->orderBy('model')->orderByDesc('effective_from')->orderBy('quality')->orderBy('size')->get();
    }

    public function save(): void
    {
        $this->authorize('platform-admin');

        $validated = $this->validate([
            'provider' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'modality' => ['required', 'in:text,image'],
            'quality' => ['nullable', 'in:,'.implode(',', AiSettings::IMAGE_QUALITIES)],
            'size' => ['nullable', 'string', 'max:20', 'regex:/^(\d{3,4}x\d{3,4})?$/'],
            'input_usd' => ['nullable', 'string', 'max:20'],
            'cached_input_usd' => ['nullable', 'string', 'max:20'],
            'output_usd' => ['nullable', 'string', 'max:20'],
            'per_unit_usd' => ['nullable', 'string', 'max:20'],
            'effective_from' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $money = [];
        foreach (['input_usd', 'cached_input_usd', 'output_usd', 'per_unit_usd'] as $field) {
            $raw = trim((string) ($validated[$field] ?? ''));
            $money[$field] = Money::parseUsdToMicro($raw);
            if ($raw !== '' && $money[$field] === null) {
                $this->addError($field, 'Zadaj sumu v USD, napr. 0,053.');

                return;
            }
        }

        $isImage = $validated['modality'] === AiCostRate::MODALITY_IMAGE;
        if (! $isImage && $money['input_usd'] === null && $money['output_usd'] === null) {
            $this->addError('input_usd', 'Textová sadzba potrebuje cenu za vstupné a/alebo výstupné tokeny.');

            return;
        }
        if ($isImage && $money['per_unit_usd'] === null && $money['output_usd'] === null) {
            $this->addError('per_unit_usd', 'Obrázková sadzba potrebuje cenu za obrázok alebo za výstupné tokeny.');

            return;
        }

        $rate = AiCostRate::create([
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'modality' => $validated['modality'],
            'quality' => $isImage && ($validated['quality'] ?? '') !== '' ? $validated['quality'] : null,
            'size' => $isImage && ($validated['size'] ?? '') !== '' ? $validated['size'] : null,
            'currency' => 'USD',
            'input_per_million' => $money['input_usd'],
            'cached_input_per_million' => $money['cached_input_usd'],
            'output_per_million' => $money['output_usd'],
            'per_unit' => $isImage ? $money['per_unit_usd'] : null,
            'effective_from' => CarbonImmutable::parse($validated['effective_from'], config('recipes.default_timezone'))->startOfDay()->utc(),
            'source' => ($validated['source'] ?? '') !== '' ? $validated['source'] : null,
            'note' => ($validated['note'] ?? '') !== '' ? $validated['note'] : null,
            'created_by' => auth()->id(),
        ]);

        app(AdminAuditor::class)->record('ai.rate.created', $rate, [], $rate->only([
            'provider', 'model', 'modality', 'quality', 'size', 'input_per_million', 'cached_input_per_million', 'output_per_million', 'per_unit',
        ]) + ['effective_from' => $rate->effective_from->toIso8601String()]);

        $this->reset(['model', 'quality', 'size', 'input_usd', 'cached_input_usd', 'output_usd', 'per_unit_usd', 'source', 'note']);
        unset($this->rates);
        Flux::toast(variant: 'success', text: 'Sadzba pridaná. Staršie úlohy ostávajú ocenené pôvodnou sadzbou.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Cenník AI" subtitle="Verzované cenníkové ceny poskytovateľa v USD. Nová cena = nový riadok s dátumom účinnosti; existujúce úlohy sa neprepočítavajú." :back="route('admin.ai')" />

    <flux:card class="space-y-3 overflow-x-auto">
        <flux:heading size="lg" class="font-display">Platné sadzby</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr>
                    <th class="py-1 pe-2">Model</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2">Kvalita · rozmer</th>
                    <th class="py-1 pe-2 text-right">Vstup / 1M</th><th class="py-1 pe-2 text-right">Cache / 1M</th><th class="py-1 pe-2 text-right">Výstup / 1M</th><th class="py-1 pe-2 text-right">Za obrázok</th>
                    <th class="py-1 pe-2">Účinnosť od</th><th class="py-1">Zdroj</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->rates as $rate)
                    <tr>
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $rate->provider }} / {{ $rate->model }}</td>
                        <td class="py-1.5 pe-2">{{ $rate->modality === 'image' ? 'Obrázok' : 'Text' }}</td>
                        <td class="py-1.5 pe-2">{{ $rate->quality ?? '–' }} · {{ $rate->size ?? '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $rate->input_per_million !== null ? Money::microUsd($rate->input_per_million, 2) : '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $rate->cached_input_per_million !== null ? Money::microUsd($rate->cached_input_per_million, 2) : '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $rate->output_per_million !== null ? Money::microUsd($rate->output_per_million, 2) : '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $rate->per_unit !== null ? Money::microUsd($rate->per_unit, 3) : '–' }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $rate->effective_from->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}</td>
                        <td class="py-1.5 max-w-xs truncate text-xs text-zinc-500" title="{{ $rate->note }}">
                            @if ($rate->source)<a href="{{ $rate->source }}" class="underline" target="_blank" rel="noopener noreferrer">odkaz</a>@endif
                            {{ $rate->note }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-3 text-zinc-500">Žiadne sadzby – spusti <code>php artisan db:seed --class=AiCostRateSeeder</code> alebo pridaj sadzbu nižšie.</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    <flux:card class="space-y-4">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg" class="font-display">Pridať sadzbu</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="provider" label="Poskytovateľ" placeholder="openai" />
                <flux:input wire:model="model" label="Model" placeholder="gpt-6-luna" />
                <flux:select wire:model.live="modality" label="Druh">
                    <flux:select.option value="text">Text (tokeny)</flux:select.option>
                    <flux:select.option value="image">Obrázok</flux:select.option>
                </flux:select>
            </div>
            @if ($modality === 'image')
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:select wire:model="quality" label="Kvalita">
                        <flux:select.option value="">ľubovoľná</flux:select.option>
                        @foreach (AiSettings::IMAGE_QUALITIES as $q)
                            <flux:select.option :value="$q">{{ $q }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="size" label="Rozmer (px)" placeholder="1024x1024" />
                    <flux:input wire:model="per_unit_usd" label="USD za obrázok" placeholder="0,053" />
                </div>
            @endif
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="input_usd" label="USD za 1M vstupných tokenov" placeholder="0,10" />
                <flux:input wire:model="cached_input_usd" label="USD za 1M cache vstupných" placeholder="voliteľné" />
                <flux:input wire:model="output_usd" label="USD za 1M výstupných tokenov" placeholder="0,50" />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="effective_from" type="date" label="Účinnosť od" />
                <flux:input wire:model="source" label="Zdroj (URL cenníka)" placeholder="https://…" />
                <flux:input wire:model="note" label="Poznámka" />
            </div>
            <flux:button type="submit" variant="primary">Pridať sadzbu</flux:button>
        </form>
    </flux:card>
</div>
