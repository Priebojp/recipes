<div class="space-y-6" data-test="meal-diary">
    @if ($justLogged)
        {{-- Allow-listed, property-less event: no meal, grams, kcal or photo ever leaves the app (chapter 10). --}}
        <script type="application/json" id="mr-analytics-event">@json(['name' => 'meal_logged', 'properties' => []])</script>
    @endif

    @php($basis = $this->basis)
    @php($preview = $this->preview)

    <flux:card class="flex flex-wrap items-center justify-between gap-3" data-test="diary-day">
        <div class="flex items-center gap-1">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousDay" :aria-label="__('Predchádzajúci deň')" data-test="diary-prev" />
            <div class="min-w-40 text-center">
                <div class="font-display text-lg font-semibold" data-test="diary-day-label">{{ $this->day->translatedFormat('l j. n. Y') }}</div>
                @if ($this->day->toDateString() === $today)<div class="text-xs text-zinc-500">{{ __('dnes') }}</div>@endif
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextDay" :aria-label="__('Nasledujúci deň')" data-test="diary-next" />
            @if ($this->day->toDateString() !== $today)
                <flux:button size="sm" variant="ghost" wire:click="today" data-test="diary-today">{{ __('Dnes') }}</flux:button>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" variant="primary" icon="plus" wire:click="openForm('recipe')" data-test="diary-add-recipe">{{ __('Z receptu') }}</flux:button>
            <flux:button size="sm" icon="camera" wire:click="openForm('analysis')" data-test="diary-add-analysis">{{ __('Z fotky') }}</flux:button>
            <flux:button size="sm" icon="pencil" wire:click="openForm('manual')" data-test="diary-add-manual">{{ __('Ručne') }}</flux:button>
        </div>
    </flux:card>

    @if ($error)
        <flux:callout icon="exclamation-circle" variant="danger" data-test="diary-error">{{ $error }}</flux:callout>
    @endif

    @if ($formOpen)
        <flux:card class="space-y-4" data-test="diary-form">
            <flux:heading size="lg" class="font-display">{{ __('Zapísať, čo som zjedol(a)') }}</flux:heading>

            <flux:radio.group wire:model.live="source" :label="__('Zdroj')" variant="segmented" size="sm" data-test="diary-source">
                @foreach ($sources as $option)
                    <flux:radio :value="$option->value" :label="ucfirst($option->label())" />
                @endforeach
            </flux:radio.group>

            @if ($source === 'recipe')
                <flux:select wire:model.live="recipeId" :label="__('Recept')" :placeholder="__('Vyber recept s vypočítanými hodnotami')" data-test="diary-recipe">
                    @foreach ($this->recipes as $recipe)
                        <flux:select.option value="{{ $recipe->id }}">{{ $recipe->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->recipes->isEmpty())
                    <flux:text class="text-sm">{{ __('Žiadny recept ešte nemá výživové hodnoty – vypočítaj ich na stránke receptu (bez AI, zadarmo), alebo zapíš jedlo ručne.') }}</flux:text>
                @endif
                @if ($this->calculation?->isStale())
                    <flux:callout icon="clock" variant="warning" data-test="diary-stale">
                        {{ __('Výpočet receptu je pre staršiu verziu (suroviny alebo porcie sa odvtedy zmenili). Môžeš zapísať s touto poznámkou, alebo najprv prepočítať.') }}
                        <x-slot name="actions"><flux:button size="sm" :href="route('recipes.show', $recipeId).'#vyziva'" wire:navigate>{{ __('Prepočítať na recepte') }}</flux:button></x-slot>
                    </flux:callout>
                @endif
                @if ($recipeId !== null && $this->calculation === null)
                    <flux:callout icon="information-circle" variant="secondary" data-test="diary-no-calculation">{{ __('Recept nemá výpočet – zapíše sa bez kalórií, alebo ho najprv vypočítaj na stránke receptu.') }}</flux:callout>
                @endif
            @elseif ($source === 'analysis')
                <flux:select wire:model.live="analysisId" :label="__('Potvrdené jedlo z fotky')" :placeholder="__('Vyber fotku')" data-test="diary-analysis">
                    @foreach ($this->analyses as $analysis)
                        <flux:select.option value="{{ $analysis->id }}">{{ $analysis->dish_name ?? __('Jedlo z fotky') }} · {{ $analysis->confirmed_at?->timezone($timezone)->format('j. n. H:i') }}{{ $analysis->nutrition === null ? ' · '.__('bez kalórií') : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->analyses->isEmpty())
                    <flux:text class="text-sm">{{ __('Zatiaľ nemáš potvrdené jedlo z fotky.') }} <a href="{{ route('meals.analyze') }}" wire:navigate class="underline">{{ __('Odfotiť jedlo') }}</a></flux:text>
                @endif
            @else
                <flux:input wire:model="title" :label="__('Názov jedla')" :placeholder="__('napr. pizza Margherita v reštaurácii')" maxlength="200" data-test="diary-title" />
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input type="date" wire:model="eatenDate" :label="__('Dátum')" max="{{ $today }}" data-test="diary-date" />
                <flux:input type="time" wire:model="eatenTime" :label="__('Čas')" data-test="diary-time" />
            </div>

            @if ($source === 'manual')
                <flux:checkbox wire:model.live="withNutrition" :label="__('Zadať hodnoty (etiketa alebo odhad)')" data-test="diary-with-values" />
                @if ($withNutrition)
                    <div class="grid gap-3 sm:grid-cols-3" data-test="diary-manual">
                        @foreach (['energy_kcal', 'protein_g', 'carbohydrate_g', 'fat_g', 'fiber_g'] as $nutrient)
                            <flux:input size="sm" type="text" inputmode="decimal" wire:model="manual.{{ $nutrient }}" :label="$formatter::label($nutrient).' ('.$formatter::unit($nutrient).')'" data-test="diary-manual-{{ $nutrient }}" />
                        @endforeach
                        <flux:select size="sm" wire:model="manualOrigin" :label="__('Odkiaľ sú hodnoty')" :placeholder="__('vyber')" data-test="diary-manual-origin">
                            @foreach ($origins as $origin)
                                <flux:select.option value="{{ $origin->value }}">{{ $origin->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:text class="text-xs text-zinc-500">{{ __('Hodnoty za to, čo si naozaj zjedol(a). Nevyplnená hodnota zostane neznáma – nikdy sa nedoplní nulou. Bez zadania sa jedlo uloží bez kalórií.') }}</flux:text>
                @endif
            @else
                @if ($basis !== null)
                    @include('livewire.meal-diary.portion', ['basis' => $basis, 'preview' => $preview, 'prefix' => 'diary'])
                @endif
                @if ($source === 'recipe' && $recipeId !== null)
                    <flux:checkbox wire:model.live="withNutrition" :label="__('Zapísať s vypočítanými hodnotami')" data-test="diary-with-nutrition" />
                @endif
            @endif

            <flux:textarea wire:model="note" :label="__('Poznámka (voliteľné)')" rows="2" maxlength="500" data-test="diary-note" />

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled" data-test="diary-save">{{ __('Zapísať') }}</flux:button>
                <flux:button variant="ghost" wire:click="closeForm" data-test="diary-cancel">{{ __('Zrušiť') }}</flux:button>
            </div>
        </flux:card>
    @endif

    @php($sum = $this->dayTotals)
    <flux:card class="space-y-2" data-test="diary-sum">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="lg" class="font-display">{{ __('Súčet dňa') }}</flux:heading>
            @if ($sum['entries'] > 0)
                <flux:badge size="sm" :color="$sum['partial'] ? 'amber' : 'green'" :icon="$sum['partial'] ? 'exclamation-triangle' : 'check'" data-test="diary-sum-completeness">{{ $sum['partial'] ? __('Čiastočný súčet') : __('Kompletný súčet') }}</flux:badge>
            @endif
        </div>
        @if ($sum['entries'] === 0)
            <flux:text class="text-sm">{{ __('V tento deň nie je zapísané nič.') }}</flux:text>
        @elseif ($sum['totals'] === null)
            <flux:text class="text-sm" data-test="diary-sum-none">{{ __('Záznamy bez kalórií (:count).', ['count' => $sum['without_values']]) }}</flux:text>
        @else
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <div class="font-display text-2xl font-bold" data-test="diary-sum-kcal">{{ $formatter->value('energy_kcal', $sum['totals']['energy_kcal'] ?? null) }}</div>
                <dl class="flex flex-wrap gap-x-3 text-sm text-zinc-600 dark:text-zinc-300">
                    @foreach (['protein_g', 'carbohydrate_g', 'fat_g', 'fiber_g'] as $nutrient)
                        <div><dt class="inline text-zinc-500">{{ $formatter::label($nutrient) }}</dt> <dd class="inline font-medium">{{ $formatter->value($nutrient, $sum['totals'][$nutrient] ?? null) }}</dd></div>
                    @endforeach
                </dl>
            </div>
            @if ($sum['without_values'] > 0)
                <flux:text class="text-xs text-zinc-500" data-test="diary-sum-without">{{ __('Bez kalórií: :count záznam(y) – nie sú v súčte.', ['count' => $sum['without_values']]) }}</flux:text>
            @endif
        @endif
    </flux:card>

    @if ($this->entries->isNotEmpty())
        <div class="space-y-3" data-test="diary-entries">
            @foreach ($this->entries as $entry)
                @php($snapshot = $entry->snapshot)
                <flux:card class="space-y-3" wire:key="entry-{{ $entry->id }}" data-test="diary-entry-{{ $entry->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-zinc-500">{{ $entry->eatenAtLocal()->format('H:i') }}</span>
                                <span class="font-medium" data-test="diary-entry-title">{{ $entry->title_snapshot }}</span>
                                <flux:badge size="sm" color="zinc">{{ $entry->source->label() }}</flux:badge>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                @if ($snapshot === null || ! $snapshot->hasNutrition())
                                    <span class="text-zinc-500" data-test="diary-entry-none">{{ __('bez kalórií') }}</span>
                                @else
                                    <span class="font-display text-lg font-bold" data-test="diary-entry-kcal">{{ $formatter->value('energy_kcal', $snapshot->totals['energy_kcal'] ?? null) }}</span>
                                    <flux:badge size="sm" :color="$snapshot->isPartial() ? 'amber' : 'green'" data-test="diary-entry-completeness">{{ $snapshot->completeness?->label() }}</flux:badge>
                                    @if ($snapshot->portion_mode === \App\Enums\PortionMode::Fraction && (float) $snapshot->portion_fraction !== 1.0)
                                        <span class="text-xs text-zinc-500">{{ __(':percent % porcie', ['percent' => (int) round((float) $snapshot->portion_fraction * 100)]) }}</span>
                                    @elseif ($snapshot->portion_mode === \App\Enums\PortionMode::Grams)
                                        <span class="text-xs text-zinc-500">{{ $formatter->weight((float) $snapshot->grams) }} g</span>
                                    @elseif ($snapshot->portion_mode === \App\Enums\PortionMode::PerComponent)
                                        <span class="text-xs text-zinc-500">{{ __('po zložkách') }}</span>
                                    @endif
                                    @if ($snapshot->manual_origin)
                                        <span class="text-xs text-zinc-500">{{ __('zdroj: :origin', ['origin' => $snapshot->manual_origin->label()]) }}</span>
                                    @endif
                                    @if ($snapshot->revision > 1)
                                        <span class="text-xs text-zinc-500" data-test="diary-entry-revision">{{ __('oprava č. :n', ['n' => $snapshot->revision - 1]) }}</span>
                                    @endif
                                @endif
                            </div>
                            @if ($entry->note)<div class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $entry->note }}</div>@endif
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <flux:button size="xs" variant="ghost" :icon="$expandedId === $entry->id ? 'chevron-up' : 'chevron-down'" wire:click="toggle({{ $entry->id }})" data-test="diary-toggle-{{ $entry->id }}">{{ __('Detail') }}</flux:button>
                            @if ($snapshot?->hasNutrition())
                                <flux:button size="xs" variant="ghost" icon="adjustments-horizontal" wire:click="startAdjust({{ $entry->id }})" data-test="diary-adjust-{{ $entry->id }}">{{ __('Opraviť podiel') }}</flux:button>
                            @endif
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="remove({{ $entry->id }})" wire:confirm="{{ __('Zmazať tento záznam z denníka?') }}" data-test="diary-remove-{{ $entry->id }}">{{ __('Zmazať') }}</flux:button>
                        </div>
                    </div>

                    @if ($adjustingId === $entry->id)
                        <div class="space-y-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-test="diary-adjust-form">
                            <flux:text class="text-sm">{{ __('Oprava je čistá matematika nad pôvodnou snímkou – recept, databáza ani AI sa nepoužijú. Stará snímka zostane v histórii.') }}</flux:text>
                            @include('livewire.meal-diary.portion', ['basis' => $basis, 'preview' => $preview, 'prefix' => 'adjust'])
                            <div class="flex gap-2">
                                <flux:button size="sm" variant="primary" wire:click="saveAdjust" wire:loading.attr="disabled" data-test="adjust-save">{{ __('Uložiť opravu') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancelAdjust">{{ __('Zrušiť') }}</flux:button>
                            </div>
                        </div>
                    @endif

                    @if ($expandedId === $entry->id && $snapshot !== null)
                        <div class="space-y-3 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-700" data-test="diary-detail-{{ $entry->id }}">
                            @if ($snapshot->hasNutrition())
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3">
                                    @foreach ($nutrients as $nutrient)
                                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ $formatter::label($nutrient) }}</dt><dd class="font-medium">{{ $formatter->value($nutrient, $snapshot->totals[$nutrient] ?? null) }}</dd></div>
                                    @endforeach
                                </dl>
                            @else
                                <flux:text class="text-sm">{{ __('Uložené bez kalórií.') }}</flux:text>
                            @endif

                            @if ($snapshot->components !== [])
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="text-left text-xs uppercase tracking-wide text-zinc-500"><tr><th class="py-1 pr-2">{{ __('Zložka') }}</th><th class="py-1 pr-2">{{ __('Potravina (zdroj)') }}</th><th class="py-1 text-right">{{ __('Zjedené') }}</th></tr></thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                            @foreach ($snapshot->components as $component)
                                                <tr class="{{ ($component['included'] ?? false) ? '' : 'text-zinc-400' }}" data-test="diary-component-{{ $entry->id }}-{{ $component['index'] }}">
                                                    <td class="py-1 pr-2 align-top">{{ $component['name'] }}</td>
                                                    <td class="py-1 pr-2 align-top">
                                                        @if ($component['source'])
                                                            <div>{{ $component['source']['name_sk'] ?? $component['source']['name'] }}</div>
                                                            <div class="text-xs text-zinc-500">{{ strtoupper(str_replace('_', ' ', $component['source']['provider'])) }} {{ $component['source']['external_id'] }}</div>
                                                        @else
                                                            <span class="text-xs">{{ $component['unresolved_reason'] ?? __('bez potraviny') }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="py-1 text-right align-top whitespace-nowrap">
                                                        @if ($component['eaten_grams'] !== null)
                                                            {{ $formatter->weight((float) $component['eaten_grams']) }} g
                                                            @if (($component['share_eaten'] ?? 1) < 1)<span class="text-xs">({{ (int) round($component['share_eaten'] * 100) }} %)</span>@endif
                                                            <div class="text-xs text-zinc-500">{{ \App\Enums\FoodGramsOrigin::tryFrom($component['grams_origin'] ?? '')?->label() }}</div>
                                                        @else
                                                            {{ $formatter::DASH }}
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if ($snapshot->missing !== [])
                                <div data-test="diary-missing-{{ $entry->id }}">
                                    <div class="font-medium">{{ __('Chýba v súčte') }}</div>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-zinc-600 dark:text-zinc-300">
                                        @foreach ($snapshot->missing as $missing)<li><span class="font-medium">{{ $missing['name'] }}</span> – {{ $missing['reason'] }}</li>@endforeach
                                    </ul>
                                </div>
                            @endif
                            @if ($snapshot->assumptions !== [])
                                <div data-test="diary-assumptions-{{ $entry->id }}">
                                    <div class="font-medium">{{ __('Predpoklady') }}</div>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-zinc-600 dark:text-zinc-300">
                                        @foreach ($snapshot->assumptions as $assumption)<li>{{ $assumption }}</li>@endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="text-xs text-zinc-500">
                                {{ __('Snímka č. :revision z :date', ['revision' => $snapshot->revision, 'date' => $snapshot->created_at->timezone($timezone)->format('j. n. Y H:i')]) }}
                                @if ($entry->source === \App\Enums\ConsumptionSource::Recipe && $entry->recipe_id) · <a href="{{ route('recipes.show', $entry->recipe_id) }}" wire:navigate class="underline">{{ __('recept') }}</a> @endif
                                @if ($entry->source === \App\Enums\ConsumptionSource::Analysis && $entry->meal_analysis_id) · <a href="{{ route('meals.analyze', ['analyza' => $entry->meal_analysis_id]) }}" wire:navigate class="underline">{{ __('fotka') }}</a> @endif
                                @if ($entry->snapshots->count() > 1) · {{ __('starších snímok: :count', ['count' => $entry->snapshots->count() - 1]) }} @endif
                            </div>
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:text class="text-xs text-zinc-500">{{ __('Denník je súkromný: vidíš ho iba ty, nie domácnosť. Hodnoty sú orientačný výpočet z databázy potravín podľa toho, čo si zapísal(a); nie sú medicínskym meraním. Bez cieľov, diét a trendov.') }}</flux:text>
</div>
