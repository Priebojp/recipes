<?php

use App\Enums\FoodPreparationState;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;
use App\Models\IngredientFoodMapping;
use App\Services\Admin\AdminAuditor;
use App\Services\Food\FoodCatalog;
use App\Services\Food\FoodMappingService;
use App\Services\Food\FoodSourceUnavailableException;
use App\Services\Food\FoodUnit;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Curation of the shared food catalogue (v2.1 stage 9): USDA key status, last sync, ingredient names nobody could
 * map, aliases and per-food unit conversions. Every change of a gram weight or a name in the shared catalogue is
 * audited; personal calculations keep their own snapshots and are never rewritten from here.
 */
new #[Layout('layouts::admin')] #[Title('Potraviny a priradenia')] class extends Component {
    public string $query = '';

    /** @var list<array{external_id: string, name: string, category: string|null, stored_id: int|null}> */
    public array $searchResults = [];

    public ?string $searchError = null;

    public string $filter = '';

    public bool $warningsOnly = false;

    public int $selectedId = 0;

    public string $name_sk = '';

    public string $preparation_state = 'raw';

    public bool $is_curated = false;

    public string $aliasName = '';

    public string $aliasLocale = 'sk';

    public string $aliasState = 'raw';

    public string $conversionUnit = FoodUnit::PIECE;

    public string $conversionGrams = '';

    public string $conversionSource = FoodUnitConversion::SOURCE_MANUAL;

    public string $conversionNote = '';

    /** @return array{configured: bool, provider: string, last_sync: array<string, mixed>|null, records: int, curated: int, warnings: int, unsynced: int, aliases: int, conversions: int, unresolved: int} */
    #[Computed]
    public function status(): array
    {
        $catalog = app(FoodCatalog::class);

        return [
            'configured' => $catalog->isSourceConfigured(),
            'provider' => $catalog->provider(),
            'last_sync' => $catalog->lastSync(),
            'records' => FoodSourceRecord::query()->count(),
            'curated' => FoodSourceRecord::query()->where('is_curated', true)->count(),
            'warnings' => FoodSourceRecord::query()->whereNotNull('sync_warning')->count(),
            'unsynced' => FoodSourceRecord::query()->whereNull('fetched_at')->count(),
            'aliases' => FoodAlias::query()->count(),
            'conversions' => FoodUnitConversion::query()->count(),
            'unresolved' => IngredientFoodMapping::query()->whereIn('status', ['unresolved', 'rejected'])->count(),
        ];
    }

    /** @return list<array{name: string, count: int, reasons: list<string>}> */
    #[Computed]
    public function unresolved(): array
    {
        return app(FoodMappingService::class)->unresolvedNames(30);
    }

    /** @return Collection<int, FoodSourceRecord> */
    #[Computed]
    public function records(): Collection
    {
        $filter = trim($this->filter);

        return FoodSourceRecord::query()
            ->withCount(['aliases', 'conversions'])
            ->when($this->warningsOnly, fn ($q) => $q->whereNotNull('sync_warning'))
            ->when($filter !== '', function ($q) use ($filter) {
                $normalized = FoodAlias::normalize($filter);
                $q->where(function ($q) use ($filter, $normalized) {
                    $q->where('name', 'like', '%'.$filter.'%')
                        ->orWhere('name_sk', 'like', '%'.$filter.'%')
                        ->orWhere('external_id', $filter)
                        ->orWhereHas('aliases', fn ($a) => $a->where('normalized', 'like', '%'.$normalized.'%'));
                });
            })
            ->orderByRaw('name_sk is null, name_sk, name')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function selected(): ?FoodSourceRecord
    {
        return $this->selectedId > 0 ? FoodSourceRecord::query()->with(['aliases' => fn ($q) => $q->orderBy('alias'), 'conversions' => fn ($q) => $q->orderBy('unit')])->find($this->selectedId) : null;
    }

    public function search(): void
    {
        $this->authorize('platform-admin');
        $this->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);

        $this->searchResults = [];
        $this->searchError = null;

        try {
            foreach (app(FoodCatalog::class)->search($this->query, 12) as $result) {
                $this->searchResults[] = [
                    'external_id' => $result['hit']->externalId,
                    'name' => $result['hit']->name,
                    'category' => $result['hit']->category,
                    'stored_id' => $result['record']?->id,
                ];
            }
        } catch (FoodSourceUnavailableException $e) {
            $this->searchError = $e->getMessage();
        }
    }

    public function import(string $externalId): void
    {
        $this->authorize('platform-admin');

        try {
            $record = app(FoodCatalog::class)->import($externalId);
        } catch (FoodSourceUnavailableException|\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        app(AdminAuditor::class)->record('food.record.imported', $record, [], ['provider' => $record->provider, 'external_id' => $record->external_id, 'name' => $record->name]);
        $this->select($record->id);
        unset($this->records, $this->status);
        $this->search();
        Flux::toast(variant: 'success', text: 'Potravina „'.$record->name.'“ je v katalógu. Doplň slovenský názov a aliasy.');
    }

    public function refresh(): void
    {
        $this->authorize('platform-admin');
        $record = $this->selected;
        if ($record === null) {
            return;
        }

        $before = $record->only(FoodSourceRecord::NUTRIENTS);
        try {
            $outcome = app(FoodCatalog::class)->refresh($record);
        } catch (FoodSourceUnavailableException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $record->refresh();
        app(AdminAuditor::class)->record('food.record.refreshed', $record, $before, $record->only(FoodSourceRecord::NUTRIENTS) + ['outcome' => $outcome]);
        unset($this->selected, $this->records, $this->status);
        Flux::toast(variant: $outcome === 'updated' ? 'success' : 'warning', text: match ($outcome) {
            'updated' => 'Hodnoty obnovené zo zdroja. Existujúce priradenia a výpočty sa nemenia.',
            'warning' => 'Obnovené, ale názov v zdroji sa líši od očakávaného – skontroluj priradenie.',
            default => 'Záznam v zdroji už neexistuje; hodnoty ostávajú zo snímky.',
        });
    }

    public function select(int $id): void
    {
        $record = FoodSourceRecord::query()->find($id);
        if ($record === null) {
            $this->selectedId = 0;

            return;
        }

        $this->selectedId = $record->id;
        $this->name_sk = (string) $record->name_sk;
        $this->preparation_state = $record->preparation_state->value;
        $this->is_curated = $record->is_curated;
        $this->aliasState = $record->preparation_state->value;
        $this->resetValidation();
        $this->reset(['aliasName', 'conversionGrams', 'conversionNote']);
        unset($this->selected);
    }

    public function close(): void
    {
        $this->selectedId = 0;
        unset($this->selected);
    }

    public function saveRecord(): void
    {
        $this->authorize('platform-admin');
        $record = $this->selected;
        if ($record === null) {
            return;
        }

        $validated = $this->validate([
            'name_sk' => ['nullable', 'string', 'max:200'],
            'preparation_state' => ['required', 'in:'.implode(',', array_column(FoodPreparationState::cases(), 'value'))],
            'is_curated' => ['boolean'],
        ]);

        $before = $record->only(['name_sk', 'preparation_state', 'is_curated', 'sync_warning']);
        $record->update([
            'name_sk' => trim((string) $validated['name_sk']) !== '' ? trim($validated['name_sk']) : null,
            'preparation_state' => FoodPreparationState::from($validated['preparation_state']),
            'is_curated' => (bool) $validated['is_curated'],
            'sync_warning' => null, // the curator looked at it
        ]);

        app(AdminAuditor::class)->record('food.record.updated', $record, $before, $record->only(['name_sk', 'preparation_state', 'is_curated', 'sync_warning']));
        unset($this->selected, $this->records, $this->status);
        Flux::toast(variant: 'success', text: 'Záznam uložený.');
    }

    public function addAlias(): void
    {
        $this->authorize('platform-admin');
        $record = $this->selected;
        if ($record === null) {
            return;
        }

        $validated = $this->validate([
            'aliasName' => ['required', 'string', 'min:2', 'max:100'],
            'aliasLocale' => ['required', 'in:'.implode(',', FoodAlias::LOCALES)],
            'aliasState' => ['required', 'in:'.implode(',', array_column(FoodPreparationState::cases(), 'value'))],
        ]);

        $normalized = FoodAlias::normalize($validated['aliasName']);
        if ($normalized === '') {
            $this->addError('aliasName', 'Zadaj názov suroviny.');

            return;
        }
        if ($record->aliases()->where('normalized', $normalized)->exists()) {
            $this->addError('aliasName', 'Tento alias už pri potravine je.');

            return;
        }

        $alias = $record->aliases()->create([
            'alias' => trim($validated['aliasName']),
            'normalized' => $normalized,
            'locale' => $validated['aliasLocale'],
            'preparation_state' => FoodPreparationState::from($validated['aliasState']),
            'curated_by' => auth()->id(),
            'curated_at' => now(),
        ]);

        app(AdminAuditor::class)->record('food.alias.created', $alias, [], ['record' => $record->displayName(), 'alias' => $alias->alias, 'locale' => $alias->locale, 'preparation_state' => $alias->preparation_state->value]);
        $this->reset('aliasName');
        unset($this->selected, $this->records, $this->status);
        Flux::toast(variant: 'success', text: 'Alias pridaný.');
    }

    public function removeAlias(int $id): void
    {
        $this->authorize('platform-admin');
        $alias = FoodAlias::query()->where('food_source_record_id', $this->selectedId)->find($id);
        if ($alias === null) {
            return;
        }

        app(AdminAuditor::class)->record('food.alias.deleted', $alias, ['alias' => $alias->alias, 'locale' => $alias->locale, 'record_id' => $alias->food_source_record_id], []);
        $alias->delete();
        unset($this->selected, $this->records, $this->status);
    }

    public function addConversion(): void
    {
        $this->authorize('platform-admin');
        $record = $this->selected;
        if ($record === null) {
            return;
        }

        $validated = $this->validate([
            'conversionUnit' => ['required', 'in:'.implode(',', FoodUnit::CONVERTIBLE)],
            'conversionGrams' => ['required', 'string', 'max:20'],
            'conversionSource' => ['required', 'in:'.FoodUnitConversion::SOURCE_MANUAL.','.FoodUnitConversion::SOURCE_LABEL],
            'conversionNote' => ['nullable', 'string', 'max:200'],
        ]);

        $grams = (float) str_replace([',', ' '], ['.', ''], $validated['conversionGrams']);
        if (! is_numeric(str_replace([',', ' '], ['.', ''], $validated['conversionGrams'])) || $grams <= 0 || $grams > 100000) {
            $this->addError('conversionGrams', 'Zadaj gramáž jednej jednotky, napr. 12,5.');

            return;
        }

        $existing = $record->conversions()->where('unit', $validated['conversionUnit'])->first();
        $before = $existing?->only(['unit', 'grams', 'source', 'note']) ?? [];
        $conversion = $record->conversions()->updateOrCreate(
            ['unit' => $validated['conversionUnit']],
            [
                'grams' => round($grams, 2),
                'source' => $validated['conversionSource'],
                'note' => trim((string) ($validated['conversionNote'] ?? '')) !== '' ? trim($validated['conversionNote']) : null,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ],
        );

        app(AdminAuditor::class)->record($existing === null ? 'food.conversion.created' : 'food.conversion.updated', $conversion, $before, $conversion->only(['unit', 'grams', 'source', 'note']) + ['record' => $record->displayName()]);
        $this->reset(['conversionGrams', 'conversionNote']);
        unset($this->selected, $this->records, $this->status);
        Flux::toast(variant: 'success', text: $existing === null ? 'Prevod pridaný.' : 'Prevod upravený; už uložené výpočty sa neprepočítavajú.');
    }

    public function removeConversion(int $id): void
    {
        $this->authorize('platform-admin');
        $conversion = FoodUnitConversion::query()->where('food_source_record_id', $this->selectedId)->find($id);
        if ($conversion === null) {
            return;
        }

        app(AdminAuditor::class)->record('food.conversion.deleted', $conversion, $conversion->only(['unit', 'grams', 'source', 'note']) + ['record_id' => $conversion->food_source_record_id], []);
        $conversion->delete();
        unset($this->selected, $this->records, $this->status);
    }

    public function prefill(string $name): void
    {
        $this->filter = $name;
        $this->query = $name;
        unset($this->records);
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Potraviny a priradenia" subtitle="Zdieľaný katalóg potravín (USDA FoodData Central, CC0), slovník surovín a prevody jednotiek. Zmeny gramáží sú auditované; osobné výpočty majú vlastné snímky a neprepisujú sa." />

    @php($s = $this->status)

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="food-status">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Kľúč USDA</flux:text>
            <flux:heading size="lg" class="font-display {{ $s['configured'] ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400' }}">{{ $s['configured'] ? 'nastavený' : 'chýba' }}</flux:heading>
            <flux:text class="text-xs">{{ $s['configured'] ? 'USDA_FDC_API_KEY je v .env (hodnota sa nezobrazuje).' : 'Bez USDA_FDC_API_KEY funguje uložený slovník; vyhľadávanie a sync sú vypnuté.' }}</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Posledný sync</flux:text>
            @if ($s['last_sync'])
                <flux:heading size="lg" class="font-display">{{ \Carbon\CarbonImmutable::parse($s['last_sync']['at'])->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}</flux:heading>
                <flux:text class="text-xs">{{ $s['last_sync']['checked'] }} skontrolovaných · {{ $s['last_sync']['warnings'] }} upozornení · {{ $s['last_sync']['missing'] }} chýba @if (! empty($s['last_sync']['error'])) · <span class="text-red-600">zastavené</span> @endif</flux:text>
            @else
                <flux:heading size="lg" class="font-display">nikdy</flux:heading>
                <flux:text class="text-xs">Spusti <code>php artisan app:food-sync</code> po nasadení seedu.</flux:text>
            @endif
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Katalóg</flux:text>
            <flux:heading size="lg" class="font-display">{{ $s['records'] }} potravín</flux:heading>
            <flux:text class="text-xs">{{ $s['curated'] }} kurátorovaných · {{ $s['aliases'] }} aliasov · {{ $s['conversions'] }} prevodov @if ($s['unsynced'] > 0) · <span class="text-amber-700 dark:text-amber-400">{{ $s['unsynced'] }} bez hodnôt</span> @endif</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Vyžaduje pozornosť</flux:text>
            <flux:heading size="lg" class="font-display {{ $s['warnings'] + $s['unresolved'] > 0 ? 'text-amber-700 dark:text-amber-400' : '' }}">{{ $s['warnings'] + $s['unresolved'] }}</flux:heading>
            <flux:text class="text-xs">{{ $s['warnings'] }} záznamov so zmeneným názvom v zdroji · {{ $s['unresolved'] }} nepriradených ingrediencií</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">Nepriradené suroviny</flux:heading>
            <flux:text class="text-sm">Názvy z receptov, pre ktoré slovník nemá zhodu alebo chýba prevod. Iba názvy – bez receptov a domácností.</flux:text>
            @if ($this->unresolved === [])
                <flux:text class="text-sm text-zinc-500">Zatiaľ nič.</flux:text>
            @else
                <table class="w-full text-sm" data-test="unresolved-names">
                    <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Surovina</th><th class="py-1 pe-2 text-right">×</th><th class="py-1">Dôvod</th><th></th></tr></thead>
                    <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                        @foreach ($this->unresolved as $row)
                            <tr>
                                <td class="py-1.5 pe-2 font-medium">{{ $row['name'] }}</td>
                                <td class="py-1.5 pe-2 text-right tabular-nums">{{ $row['count'] }}</td>
                                <td class="py-1.5 text-xs text-zinc-500">{{ implode(' · ', $row['reasons']) }}</td>
                                <td class="py-1.5 text-right"><flux:button size="xs" variant="ghost" wire:click="prefill({{ json_encode($row['name']) }})">hľadať</flux:button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">Vyhľadať v USDA</flux:heading>
            <form wire:submit="search" class="flex items-end gap-2">
                <flux:input wire:model="query" label="Potravina (anglicky funguje najlepšie)" placeholder="onions raw" class="flex-1" />
                <flux:button type="submit" variant="primary" icon="magnifying-glass" :disabled="! $s['configured']">Hľadať</flux:button>
            </form>
            @unless ($s['configured'])
                <flux:text class="text-xs text-amber-700 dark:text-amber-400">Vyhľadávanie je vypnuté – chýba USDA_FDC_API_KEY.</flux:text>
            @endunless
            @if ($searchError)
                <flux:callout variant="warning" icon="exclamation-triangle"><flux:callout.text>{{ $searchError }}</flux:callout.text></flux:callout>
            @endif
            @if ($searchResults !== [])
                <ul class="divide-y divide-zinc-200/70 text-sm dark:divide-zinc-800" data-test="search-results">
                    @foreach ($searchResults as $hit)
                        <li class="flex items-center justify-between gap-2 py-1.5">
                            <div class="min-w-0">
                                <div class="truncate">{{ $hit['name'] }}</div>
                                <div class="text-xs text-zinc-500">FDC {{ $hit['external_id'] }}@if ($hit['category']) · {{ $hit['category'] }}@endif</div>
                            </div>
                            @if ($hit['stored_id'])
                                <flux:button size="xs" variant="ghost" wire:click="select({{ $hit['stored_id'] }})">v katalógu</flux:button>
                            @else
                                <flux:button size="xs" wire:click="import('{{ $hit['external_id'] }}')" icon="plus">Pridať</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-[3fr_2fr]">
        <flux:card class="space-y-3 overflow-x-auto">
            <div class="flex flex-wrap items-end gap-3">
                <flux:heading size="lg" class="font-display flex-1">Katalóg</flux:heading>
                <flux:input wire:model.live.debounce.400ms="filter" placeholder="filter: názov, alias, FDC ID" size="sm" class="w-64" />
                <flux:checkbox wire:model.live="warningsOnly" label="iba s upozornením" />
            </div>
            <table class="w-full text-sm" data-test="food-records">
                <thead class="text-left text-xs uppercase text-zinc-500">
                    <tr><th class="py-1 pe-2">Potravina</th><th class="py-1 pe-2">Stav</th><th class="py-1 pe-2 text-right">kcal/100 g</th><th class="py-1 pe-2 text-right">B / S / T</th><th class="py-1 pe-2 text-right">Aliasy</th><th class="py-1"></th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->records as $record)
                        <tr class="{{ $record->id === $selectedId ? 'bg-accent/10' : '' }}">
                            <td class="py-1.5 pe-2">
                                <div class="font-medium">{{ $record->displayName() }}</div>
                                <div class="truncate text-xs text-zinc-500" title="{{ $record->name }}">{{ $record->name }} · FDC {{ $record->external_id }}</div>
                                @if ($record->sync_warning)<div class="text-xs text-amber-700 dark:text-amber-400">{{ $record->sync_warning }}</div>@endif
                            </td>
                            <td class="py-1.5 pe-2 text-xs">{{ $record->preparation_state->label() }}@unless ($record->is_curated) <span class="text-zinc-500">· nekurátorované</span>@endunless</td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ $record->energy_kcal !== null ? number_format((float) $record->energy_kcal, 0, ',', ' ') : '–' }}</td>
                            <td class="py-1.5 pe-2 text-right text-xs tabular-nums">{{ $record->protein_g !== null ? number_format((float) $record->protein_g, 1, ',', ' ') : '–' }} / {{ $record->carbohydrate_g !== null ? number_format((float) $record->carbohydrate_g, 1, ',', ' ') : '–' }} / {{ $record->fat_g !== null ? number_format((float) $record->fat_g, 1, ',', ' ') : '–' }}</td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ $record->aliases_count }}@if ($record->conversions_count > 0) <span class="text-xs text-zinc-500">· {{ $record->conversions_count }} prev.</span>@endif</td>
                            <td class="py-1.5 text-right"><flux:button size="xs" variant="ghost" wire:click="select({{ $record->id }})">upraviť</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-zinc-500">Katalóg je prázdny – spusti <code>php artisan db:seed --class=FoodAliasSeeder</code> a potom <code>php artisan app:food-sync</code>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>

        <div>
            @if ($this->selected)
                @php($r = $this->selected)
                <flux:card class="space-y-5" data-test="food-detail">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <flux:heading size="lg" class="font-display">{{ $r->displayName() }}</flux:heading>
                            <flux:text class="text-xs">{{ $r->name }} · {{ $r->provider }} {{ $r->external_id }} · {{ $r->license }} · na {{ $r->basis }}@if ($r->fetched_at) · načítané {{ $r->fetched_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}@else · <span class="text-amber-700 dark:text-amber-400">bez hodnôt (čaká na sync)</span>@endif</flux:text>
                        </div>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="close" aria-label="Zavrieť" />
                    </div>

                    <dl class="grid grid-cols-3 gap-2 text-sm sm:grid-cols-6">
                        @foreach (['energy_kcal' => 'kcal', 'energy_kj' => 'kJ', 'protein_g' => 'bielk. g', 'carbohydrate_g' => 'sach. g', 'fat_g' => 'tuky g', 'fiber_g' => 'vlákn. g'] as $key => $label)
                            <div><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="tabular-nums">{{ $r->{$key} !== null ? number_format((float) $r->{$key}, 1, ',', ' ') : '–' }}</dd></div>
                        @endforeach
                    </dl>
                    @if ($r->carbohydrate_method)<flux:text class="text-xs">Sacharidy: {{ $r->carbohydrate_method === 'by_difference' ? 'dopočtom (by difference)' : 'súčtom' }} – nemiešať s inou metodikou.</flux:text>@endif

                    <form wire:submit="saveRecord" class="space-y-3">
                        <flux:input wire:model="name_sk" label="Slovenský názov" placeholder="Cibuľa" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:select wire:model="preparation_state" label="Stav suroviny">
                                @foreach (FoodPreparationState::cases() as $state)
                                    <flux:select.option :value="$state->value">{{ $state->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:checkbox wire:model="is_curated" label="Skontrolované kurátorom" class="self-end" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <flux:button type="submit" variant="primary" size="sm">Uložiť</flux:button>
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refresh" :disabled="! $s['configured']">Obnoviť zo zdroja</flux:button>
                        </div>
                    </form>

                    <div class="space-y-2">
                        <flux:heading size="sm">Aliasy (SK/CZ názvy)</flux:heading>
                        <ul class="flex flex-wrap gap-1.5" data-test="aliases">
                            @forelse ($r->aliases as $alias)
                                <li class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">
                                    {{ $alias->alias }} <span class="text-zinc-500">{{ $alias->locale }} · {{ $alias->preparation_state->label() }}</span>
                                    <button type="button" class="ms-1 text-zinc-500 hover:text-red-600" wire:click="removeAlias({{ $alias->id }})" wire:confirm="Odstrániť alias „{{ $alias->alias }}“?" aria-label="Odstrániť">×</button>
                                </li>
                            @empty
                                <li class="text-xs text-zinc-500">Bez aliasov – matcher túto potravinu nenavrhne.</li>
                            @endforelse
                        </ul>
                        <form wire:submit="addAlias" class="grid items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_auto]">
                            <flux:input wire:model="aliasName" label="Nový alias" placeholder="cibuľa" size="sm" />
                            <flux:select wire:model="aliasLocale" label="Jazyk" size="sm">
                                <flux:select.option value="sk">sk</flux:select.option>
                                <flux:select.option value="cs">cs</flux:select.option>
                            </flux:select>
                            <flux:select wire:model="aliasState" label="Stav" size="sm">
                                @foreach (FoodPreparationState::cases() as $state)
                                    <flux:select.option :value="$state->value">{{ $state->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button type="submit" size="sm" icon="plus">Pridať</flux:button>
                        </form>
                    </div>

                    <div class="space-y-2">
                        <flux:heading size="sm">Prevody jednotiek (gramy jednej jednotky)</flux:heading>
                        <flux:text class="text-xs">Bez prevodu ostáva množstvo v tejto jednotke nepriradené – žiadny všeobecný predpoklad. „ml“ = hustota (g na 1 ml).</flux:text>
                        <table class="w-full text-sm" data-test="conversions">
                            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                                @forelse ($r->conversions as $conversion)
                                    <tr>
                                        <td class="py-1 pe-2 font-medium">1 {{ $conversion->unit }}</td>
                                        <td class="py-1 pe-2 tabular-nums">{{ number_format((float) $conversion->grams, 2, ',', ' ') }} g</td>
                                        <td class="py-1 pe-2 text-xs text-zinc-500">{{ $conversion->sourceLabel() }}@if ($conversion->note) · {{ $conversion->note }}@endif</td>
                                        <td class="py-1 text-right"><button type="button" class="text-xs text-zinc-500 hover:text-red-600" wire:click="removeConversion({{ $conversion->id }})" wire:confirm="Odstrániť prevod 1 {{ $conversion->unit }} = {{ $conversion->grams }} g?">odstrániť</button></td>
                                    </tr>
                                @empty
                                    <tr><td class="py-1 text-xs text-zinc-500">Žiadne prevody – iba gramy a kilogramy z receptu sa prevedú.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <form wire:submit="addConversion" class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_2fr_auto]">
                            <flux:select wire:model="conversionUnit" label="Jednotka" size="sm">
                                @foreach (FoodUnit::CONVERTIBLE as $unit)
                                    <flux:select.option :value="$unit">{{ $unit }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="conversionGrams" label="Gramov" placeholder="12,5" size="sm" />
                            <flux:select wire:model="conversionSource" label="Zdroj" size="sm">
                                <flux:select.option :value="FoodUnitConversion::SOURCE_MANUAL">ručne</flux:select.option>
                                <flux:select.option :value="FoodUnitConversion::SOURCE_LABEL">etiketa</flux:select.option>
                            </flux:select>
                            <flux:input wire:model="conversionNote" label="Poznámka" placeholder="stredná cibuľa" size="sm" />
                            <flux:button type="submit" size="sm" icon="plus">Uložiť</flux:button>
                        </form>
                    </div>
                </flux:card>
            @else
                <flux:card>
                    <flux:text class="text-sm">Vyber potravinu z katalógu alebo ju pridaj z vyhľadávania. Aliasy určujú, čo matcher navrhne pre ingredienciu receptu; prevody určujú, ako sa „2 ks“ alebo „1 PL“ premenia na gramy.</flux:text>
                </flux:card>
            @endif
        </div>
    </div>
</div>
