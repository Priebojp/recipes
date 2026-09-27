<div class="space-y-4" data-test="nutrition-panel">
    @php($calculation = $this->calculation)

    @if ($reviewing)
        <flux:text class="text-sm">{{ __('Skontroluj, ku ktorej potravine z databázy sa každá surovina priradila, jej stav (surová / uvarená) a gramáž. Množstvo, ktoré sa nezje celé (olej na panvici, výpek), započítaj len sčasti.') }}</flux:text>

        @if ($error)
            <flux:callout icon="exclamation-circle" variant="danger" data-test="nutrition-error">{{ $error }}</flux:callout>
        @endif

        <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach ($this->review as $row)
                @php($line = $row['line'])
                @php($mapping = $row['mapping'])
                @php($record = $row['record'])
                <li class="space-y-2 py-3" wire:key="review-{{ $line->id }}" data-test="review-line-{{ $line->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <div class="font-medium">{{ $line->name }}</div>
                            <div class="text-xs text-zinc-500">{{ trim($line->displayAmount().' '.$line->unit) !== '' ? trim($line->displayAmount().' '.$line->unit) : __('bez množstva') }}@if ($line->note) · {{ $line->note }}@endif</div>
                        </div>
                        @if ($mapping !== null)
                            <flux:badge size="sm" :color="match ($mapping->status->value) { 'confirmed' => 'green', 'suggested' => 'blue', 'rejected' => 'zinc', default => 'amber' }">{{ $mapping->status->label() }}</flux:badge>
                        @endif
                    </div>

                    <div class="grid gap-2 sm:grid-cols-[1fr_11rem]">
                        @if ($row['candidates'] !== [])
                            <flux:select size="sm" wire:model.live="choices.{{ $line->id }}" :label="__('Potravina')" data-test="choice-{{ $line->id }}">
                                <flux:select.option value="">{{ __('— bez potraviny (nezapočítať) —') }}</flux:select.option>
                                @foreach ($row['candidates'] as $candidate)
                                    <flux:select.option value="{{ $candidate['record_id'] }}">{{ $candidate['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <div class="self-end text-sm text-zinc-500">{{ __('V slovníku nie je zhoda – surovina zostane mimo súčtu a výsledok bude čiastočný.') }}</div>
                        @endif

                        <flux:select size="sm" wire:model.live="shares.{{ $line->id }}" :label="__('Zje sa')" data-test="share-{{ $line->id }}">
                            @foreach ($shareOptions as $percent)
                                <flux:select.option value="{{ $percent }}">{{ $percent === 100 ? __('celé') : ($percent === 0 ? __('nič – nezapočítať') : __(':percent %', ['percent' => $percent])) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    @if ($record !== null)
                        @if ($mapping->grams !== null && ! ($editingGrams[$line->id] ?? false))
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-semibold" data-test="grams-{{ $line->id }}">{{ $formatter->weight((float) $mapping->grams) }} g</span>
                                <flux:badge size="sm" :color="$mapping->grams_origin === \App\Enums\FoodGramsOrigin::Estimated ? 'amber' : 'zinc'">{{ $mapping->grams_origin?->label() }}</flux:badge>
                                <span class="text-xs text-zinc-500">{{ $record->displayName() }} · {{ __('na 100 g: :kcal', ['kcal' => $formatter->value('energy_kcal', $record->nutrients()['energy_kcal'])]) }}</span>
                                <flux:button size="xs" variant="ghost" wire:click="$set('editingGrams.{{ $line->id }}', true)">{{ __('Upraviť gramáž') }}</flux:button>
                            </div>
                        @else
                            @if ($mapping->unresolved_reason)
                                <flux:text class="text-xs {{ $row['needs_amount'] ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-zinc-500' }}">{{ $mapping->unresolved_reason }}@if ($row['needs_amount']) {{ __('Bez gramáže sa energeticky významná surovina nedá započítať.') }}@endif</flux:text>
                            @endif
                            <div class="flex flex-wrap items-end gap-2">
                                <flux:input size="sm" type="text" inputmode="decimal" wire:model="gramsInput.{{ $line->id }}" :label="__('Gramáž (g)')" class="w-28" data-test="grams-input-{{ $line->id }}" />
                                <flux:select size="sm" wire:model="originInput.{{ $line->id }}" :label="__('Pôvod')" class="w-44">
                                    <flux:select.option value="user_entered">{{ __('zadané (odvážené, z obalu)') }}</flux:select.option>
                                    <flux:select.option value="estimated">{{ __('odhad') }}</flux:select.option>
                                </flux:select>
                                <flux:button size="sm" wire:click="saveGrams({{ $line->id }})" data-test="save-grams-{{ $line->id }}">{{ __('Uložiť') }}</flux:button>
                                @if ($mapping->grams !== null)
                                    <flux:button size="sm" variant="ghost" wire:click="$set('editingGrams.{{ $line->id }}', false)">{{ __('Zrušiť') }}</flux:button>
                                    @if ($mapping->grams_origin !== \App\Enums\FoodGramsOrigin::UnitConversion)
                                        <flux:button size="sm" variant="ghost" wire:click="useRecipeAmount({{ $line->id }})">{{ __('Podľa receptu') }}</flux:button>
                                    @endif
                                @endif
                            </div>
                            @error("gramsInput.$line->id") <flux:text class="text-xs text-red-600">{{ $message }}</flux:text> @enderror
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="flex flex-wrap items-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
            <flux:input type="text" inputmode="decimal" wire:model="finalWeight" :label="__('Konečná hmotnosť hotového jedla (g, voliteľné)')" :description="__('Odvážená jedlá hmotnosť po uvarení. Len s ňou sa zobrazí hodnota na 100 g – súčet surových množstiev sa nepoužije.')" class="w-full sm:w-72" data-test="final-weight" />
            <div class="flex gap-2">
                <flux:button variant="primary" icon="calculator" wire:click="compute" wire:loading.attr="disabled" data-test="nutrition-compute">{{ __('Vypočítať') }}</flux:button>
                <flux:button variant="ghost" wire:click="cancel">{{ __('Zrušiť') }}</flux:button>
            </div>
        </div>
    @elseif ($calculation === null)
        <flux:text class="text-sm">{{ __('Výživové hodnoty ešte nie sú vypočítané. Výpočet používa množstvá surovín a databázu potravín; nič sa neposiela AI a nespotrebúva použitia.') }}</flux:text>
        @if ($this->canEdit)
            <flux:button icon="calculator" wire:click="start" data-test="nutrition-start">{{ __('Vypočítať výživové hodnoty') }}</flux:button>
        @else
            <flux:text class="text-xs text-zinc-500">{{ __('Výpočet môže spustiť editor alebo vlastník domácnosti.') }}</flux:text>
        @endif
    @else
        @if ($calculation->isStale())
            <flux:callout icon="clock" variant="warning" data-test="nutrition-stale">
                <flux:callout.heading>{{ __('Výpočet je pre staršiu verziu receptu') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Suroviny alebo počet porcií sa odvtedy zmenili. Hodnoty nižšie platia pre pôvodnú verziu.') }}</flux:callout.text>
                @if ($this->canEdit)
                    <x-slot name="actions"><flux:button size="sm" wire:click="start" data-test="nutrition-recalculate">{{ __('Prepočítať') }}</flux:button></x-slot>
                @endif
            </flux:callout>
        @endif

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <flux:badge size="sm" :color="$calculation->isPartial() ? 'amber' : 'green'" :icon="$calculation->isPartial() ? 'exclamation-triangle' : 'check'" data-test="nutrition-completeness">{{ $calculation->completeness->label() }}</flux:badge>
            <span class="text-zinc-500">{{ __('Vypočítané :date', ['date' => $calculation->created_at?->translatedFormat('j. n. Y H:i')]) }}</span>
        </div>

        @php($columns = [
            ['title' => __('Celý recept'), 'sub' => $calculation->servings ? __(':servings porcie', ['servings' => $calculation->servings]) : null, 'values' => $calculation->totals],
            ['title' => __('Na porciu'), 'sub' => $calculation->per_serving === null ? __('recept nemá počet porcií') : null, 'values' => $calculation->per_serving],
            ['title' => __('Na 100 g'), 'sub' => $calculation->hasPer100g() ? __('pri :weight g hotového jedla', ['weight' => $formatter->weight((float) $calculation->final_weight_g)]) : __('len so zadanou konečnou hmotnosťou'), 'values' => $calculation->per_100g],
        ])
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($columns as $column)
                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-test="nutrition-column-{{ $loop->index }}">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $column['title'] }}</div>
                    @if ($column['sub'])<div class="text-xs text-zinc-500">{{ $column['sub'] }}</div>@endif
                    <div class="mt-2 font-display text-2xl font-bold">{{ $column['values'] === null ? $formatter::DASH : $formatter->value('energy_kcal', $column['values']['energy_kcal'] ?? null) }}</div>
                    <dl class="mt-2 space-y-0.5 text-sm">
                        @foreach (['protein_g', 'carbohydrate_g', 'fat_g', 'fiber_g'] as $nutrient)
                            <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ $formatter::label($nutrient) }}</dt><dd class="font-medium">{{ $column['values'] === null ? $formatter::DASH : $formatter->value($nutrient, $column['values'][$nutrient] ?? null) }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>

        @if ($calculation->missing !== [])
            <div class="text-sm" data-test="nutrition-missing">
                <div class="font-medium">{{ __('Chýba v súčte') }}</div>
                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-zinc-600 dark:text-zinc-300">
                    @foreach ($calculation->missing as $item)
                        <li><span class="font-medium">{{ $item['name'] }}</span> – {{ $item['reason'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($calculation->assumptions !== [])
            <div class="text-sm" data-test="nutrition-assumptions">
                <div class="font-medium">{{ __('Predpoklady') }}</div>
                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-zinc-600 dark:text-zinc-300">
                    @foreach ($calculation->assumptions as $assumption)
                        <li>{{ $assumption }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <flux:accordion transition>
            <flux:accordion.item :heading="__('Zložky a zdroje')">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                            <tr><th class="py-1 pr-2">{{ __('Surovina') }}</th><th class="py-1 pr-2">{{ __('Potravina (zdroj)') }}</th><th class="py-1 pr-2 text-right">{{ __('Gramáž') }}</th><th class="py-1 text-right">{{ __('kcal') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($calculation->components as $component)
                                <tr class="{{ ($component['included'] ?? false) ? '' : 'text-zinc-400' }}" data-test="component-{{ $component['line_id'] ?? $loop->index }}">
                                    <td class="py-1.5 pr-2 align-top"><div class="font-medium">{{ $component['name'] }}</div><div class="text-xs text-zinc-500">{{ $component['amount'] }}</div></td>
                                    <td class="py-1.5 pr-2 align-top">
                                        @if ($component['source'])
                                            <div>{{ $component['source']['name_sk'] ?? $component['source']['name'] }}</div>
                                            <div class="text-xs text-zinc-500">{{ $component['source']['name'] }} · {{ strtoupper(str_replace('_', ' ', $component['source']['provider'])) }} {{ $component['source']['external_id'] }} · {{ \App\Enums\FoodPreparationState::tryFrom($component['source']['preparation_state'] ?? '')?->label() }}</div>
                                        @else
                                            <span class="text-xs">{{ $component['unresolved_reason'] ?? __('bez potraviny') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 pr-2 text-right align-top whitespace-nowrap">
                                        @if ($component['grams'] !== null)
                                            {{ $formatter->weight((float) $component['grams']) }} g
                                            @if (($component['share'] ?? 1) < 1) <span class="text-xs">({{ (int) round($component['share'] * 100) }} %)</span>@endif
                                            <div class="text-xs text-zinc-500">{{ \App\Enums\FoodGramsOrigin::tryFrom($component['grams_origin'] ?? '')?->label() }}</div>
                                        @else
                                            {{ $formatter::DASH }}
                                        @endif
                                    </td>
                                    <td class="py-1.5 text-right align-top whitespace-nowrap">
                                        @if (($component['included'] ?? false) && ($component['nutrients_per_100g']['energy_kcal'] ?? null) !== null)
                                            {{ $formatter->energy($component['included_grams'] / 100 * $component['nutrients_per_100g']['energy_kcal']) }}
                                        @else
                                            {{ $formatter::DASH }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:accordion.item>
        </flux:accordion>

        @if ($this->canEdit)
            <div class="flex flex-wrap items-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                <flux:input type="text" inputmode="decimal" wire:model="finalWeight" :label="__('Konečná hmotnosť hotového jedla (g)')" class="w-full sm:w-64" data-test="final-weight" />
                <flux:button wire:click="compute" wire:loading.attr="disabled" data-test="nutrition-compute">{{ __('Prepočítať') }}</flux:button>
                <flux:button variant="ghost" icon="pencil-square" wire:click="start" data-test="nutrition-edit">{{ __('Upraviť priradenia') }}</flux:button>
            </div>
            @error('finalWeight') <flux:text class="text-xs text-red-600">{{ $message }}</flux:text> @enderror
            @if ($error)
                <flux:callout icon="exclamation-circle" variant="danger" data-test="nutrition-error">{{ $error }}</flux:callout>
            @endif
        @endif

        <flux:text class="text-xs text-zinc-500">{{ __('Orientačný výpočet z databázy potravín (USDA FoodData Central, CC0) podľa množstiev v recepte; odhady sú označené. Nie je to medicínske meranie ani záruka zloženia či alergénov.') }}</flux:text>
    @endif
</div>
