{{-- How much was eaten: shared by the new-entry form and the correction of an existing entry. Expects $basis (ConsumptionBasis|null), $preview (ConsumptionResult|null), $formatter, $fractionOptions, $shareOptions, $prefix (data-test prefix). --}}
<div class="space-y-3" data-test="{{ $prefix }}-portion">
    <flux:select size="sm" wire:model.live="portionMode" :label="__('Koľko si zjedol(a)')" data-test="{{ $prefix }}-mode">
        <flux:select.option value="fraction">{{ __('Podiel porcie') }}</flux:select.option>
        <flux:select.option value="grams">{{ __('Odvážené gramy') }}</flux:select.option>
        @if ($basis?->hasIncludedComponents())
            <flux:select.option value="per_component">{{ __('Po zložkách (napr. ryžu nie, mäso áno)') }}</flux:select.option>
        @endif
    </flux:select>

    @if ($portionMode === 'fraction')
        <flux:select size="sm" wire:model.live="fraction" :label="__('Podiel')" data-test="{{ $prefix }}-fraction">
            @foreach ($fractionOptions as $percent)
                <flux:select.option value="{{ $percent }}">{{ $percent === 100 ? __('celá porcia') : ($percent > 100 ? __(':percent % (viac ako porcia)', ['percent' => $percent]) : __(':percent % porcie', ['percent' => $percent])) }}</flux:select.option>
            @endforeach
        </flux:select>
    @elseif ($portionMode === 'grams')
        @php($unit = $basis === null ? null : app(\App\Services\Diary\ConsumptionCalculator::class)->unitGrams($basis))
        <flux:input size="sm" type="text" inputmode="decimal" wire:model.live.debounce.500ms="gramsEaten" :label="__('Zjedené gramy')" class="w-36" data-test="{{ $prefix }}-grams"
            :description="$unit && $unit['grams'] ? ($unit['origin'] === 'measured' ? __('Porcia má :grams g (odvážené hotové jedlo).', ['grams' => $formatter->weight($unit['grams'])]) : __('Porcia má cca :grams g – súčet započítaných surovín, nie odvážené jedlo.', ['grams' => $formatter->weight($unit['grams'])])) : __('Zdroj nemá hmotnosť – zvoľ podiel porcie.')" />
    @elseif ($portionMode === 'per_component' && $basis !== null)
        <div class="space-y-2" data-test="{{ $prefix }}-components">
            @foreach (array_values($basis->components) as $index => $component)
                @if (($component['included'] ?? false) && ($component['included_grams'] ?? null) !== null)
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="{{ $prefix }}-share-{{ $index }}">
                        <div>
                            <span class="font-medium">{{ $component['name'] }}</span>
                            <span class="text-xs text-zinc-500">· {{ $formatter->weight((float) $component['included_grams'] / $basis->divisor) }} g {{ __('v porcii') }}</span>
                        </div>
                        <flux:select size="sm" wire:model.live="componentShares.{{ $index }}" class="w-40" data-test="{{ $prefix }}-share-{{ $index }}">
                            @foreach ($shareOptions as $percent)
                                <flux:select.option value="{{ $percent }}">{{ $percent === 100 ? __('celé') : ($percent === 0 ? __('nič') : __(':percent %', ['percent' => $percent])) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if ($preview !== null)
        <div class="flex flex-wrap items-center gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60" data-test="{{ $prefix }}-preview">
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Zjedené') }}</div>
                <div class="font-display text-xl font-bold" data-test="{{ $prefix }}-preview-kcal">{{ $formatter->value('energy_kcal', $preview->totals['energy_kcal'] ?? null) }}</div>
            </div>
            <flux:badge size="sm" :color="$preview->isPartial() ? 'amber' : 'green'" data-test="{{ $prefix }}-preview-completeness">{{ $preview->completeness->label() }}</flux:badge>
            <div class="text-xs text-zinc-500">
                @foreach (['protein_g', 'carbohydrate_g', 'fat_g'] as $nutrient) {{ $formatter::label($nutrient) }} {{ $formatter->value($nutrient, $preview->totals[$nutrient] ?? null) }}{{ $loop->last ? '' : ' · ' }} @endforeach
                @if ($preview->eatenGrams > 0) · {{ __(':grams g', ['grams' => $formatter->weight($preview->eatenGrams)]) }} @endif
            </div>
        </div>
    @endif
</div>
