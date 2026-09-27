@php($analysis = $this->analysis)
@php($job = $this->job)
<div class="space-y-6" data-test="meal-analyzer" @if ($job && $job->status->isActive()) wire:poll.3s="refreshStatus" @endif>
    @if ($error)
        <flux:callout icon="exclamation-circle" variant="danger" data-test="meal-error">{{ $error }}</flux:callout>
    @endif

    @if ($analysis === null)
        <flux:card class="space-y-4" data-test="meal-upload">
            @unless ($this->noticeAccepted)
                <flux:callout icon="shield-check" variant="secondary" data-test="meal-notice">
                    <flux:callout.heading>{{ __('Kam ide fotka') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Fotka sa po zmenšení a odstránení metadát (vrátane polohy) odošle poskytovateľovi AI (OpenAI) na rozpoznanie zložiek. Neposiela sa tvoje meno, rodina ani žiadne iné údaje. Pracovná fotka sa u nás zmaže :hours hodín po vyhodnotení, ak si ju výslovne neponecháš pri zázname; nedokončený návrh po :days dňoch.', ['hours' => $photoTtlHours, 'days' => $draftTtlDays]) }}
                        {{ __('Výsledok je orientačný odhad, nie medicínske meranie ani informácia o alergénoch.') }}
                    </flux:callout.text>
                    <x-slot name="actions"><flux:button size="sm" variant="primary" wire:click="acceptNotice" data-test="meal-accept-notice">{{ __('Rozumiem, pokračovať') }}</flux:button></x-slot>
                </flux:callout>
            @else
                @if ($this->unavailable)
                    <flux:callout icon="information-circle" variant="secondary" data-test="meal-unavailable">{{ $this->unavailable }}</flux:callout>
                @endif

                <flux:file-upload wire:model="photo" :label="__('Fotka jedla')" accept="image/jpeg,image/png,image/webp" capture="environment" data-test="meal-photo-input">
                    <flux:file-upload.dropzone inline :heading="__('Odfoť jedlo alebo vyber fotku')" :text="__('JPG, PNG alebo WebP do 10 MB. Jedna fotka = jedna analýza.')" />
                </flux:file-upload>
                <div wire:loading wire:target="photo" class="text-sm text-zinc-500">{{ __('Nahrávam…') }}</div>
                @error('photo')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror
                @if ($photo)
                    <flux:text class="text-sm" data-test="meal-photo-ready">{{ __('Fotka je pripravená: :name', ['name' => $photo->getClientOriginalName()]) }}</flux:text>
                @endif

                <flux:input wire:model="note" :label="__('Poznámka (voliteľné)')" :placeholder="__('napr. kuracie na smotane s ryžou')" :description="__('Krátky popis pomôže rozpoznaniu. Názov reštaurácie ani mená netreba.')" maxlength="500" data-test="meal-note" />

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button variant="primary" icon="sparkles" wire:click="analyze" wire:loading.attr="disabled" :disabled="(bool) $this->unavailable" data-test="meal-analyze">{{ __('Rozpoznať jedlo') }}</flux:button>
                    @if ($balance = $this->balance)
                        <flux:text class="text-xs text-zinc-500" data-test="meal-balance">
                            {{ __('Spotrebuje 1 použitie (:unit) · zostáva :available', ['unit' => $balance->kind->unitLabel(), 'available' => $balance->available()]) }}
                            @if ($balance->includedTotal > 0) – {{ $balance->includedSourceLabel }}: {{ $balance->includedAvailable }}/{{ $balance->includedTotal }}@endif
                            @if ($balance->purchasedAvailable > 0), {{ __('dokúpené: :count', ['count' => $balance->purchasedAvailable]) }}@endif
                        </flux:text>
                    @endif
                </div>
                <flux:text class="text-xs text-zinc-500">{{ __('Použitie sa odpočíta až po doručení rozpoznaného návrhu. Fotka bez jedla alebo nepoužiteľná fotka sa neúčtuje.') }} {{ __('Ak ide o tvoj recept, výživové hodnoty nájdeš priamo na recepte – bez AI a bez použití.') }}</flux:text>
            @endunless
        </flux:card>
    @else
        <flux:card class="space-y-4" data-test="meal-analysis-{{ $analysis->id }}">
            <div class="flex flex-col gap-4 sm:flex-row">
                @if ($analysis->hasPhoto())
                    <img src="{{ route('meals.photo', $analysis) }}" alt="" class="aspect-square w-full rounded-xl object-cover sm:w-48" data-test="meal-photo" />
                @else
                    <div class="flex aspect-square w-full items-center justify-center rounded-xl bg-zinc-100 text-xs text-zinc-500 sm:w-48 dark:bg-zinc-800" data-test="meal-photo-removed">{{ __('Fotka bola odstránená') }}</div>
                @endif
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg" class="font-display" data-test="meal-dish">{{ $analysis->dish_name ?? __('Jedlo z fotky') }}</flux:heading>
                        <flux:badge size="sm" :color="match ($analysis->status->value) { 'confirmed' => 'green', 'needs_review' => 'blue', 'unusable' => 'zinc', 'analyzing' => 'amber', default => 'zinc' }" data-test="meal-status">{{ $analysis->status->label() }}</flux:badge>
                    </div>
                    @if ($analysis->note)<flux:text class="text-sm">{{ __('Poznámka: :note', ['note' => $analysis->note]) }}</flux:text>@endif
                    <flux:text class="text-xs text-zinc-500">{{ __('Nahraté :date', ['date' => $analysis->created_at?->timezone(app(\App\Support\CurrentHousehold::class)->timezone())->translatedFormat('j. n. Y H:i')]) }}</flux:text>

                    @if ($job && $job->status->isActive())
                        <div class="flex items-center gap-2 text-sm text-zinc-500" data-test="meal-working"><flux:icon name="arrow-path" class="size-4 animate-spin" /> {{ __('AI rozpoznáva zložky… stránku môžeš nechať otvorenú.') }}</div>
                    @elseif ($job && $job->status === \App\Enums\AiJobStatus::Reconciling)
                        <flux:callout icon="clock" variant="warning" data-test="meal-reconciling">
                            <flux:callout.heading>{{ __('Výsledok sa overuje') }}</flux:callout.heading>
                            <flux:callout.text>{{ __('Spojenie s AI vypršalo a nevieme, či návrh vznikol. Použitie zostáva rezervované, kým to overíme – nebude odpočítané dvakrát.') }}</flux:callout.text>
                        </flux:callout>
                    @elseif ($analysis->status === \App\Enums\MealAnalysisStatus::Uploaded && $job && $job->status === \App\Enums\AiJobStatus::Failed)
                        <flux:callout icon="exclamation-circle" variant="danger" data-test="meal-failed">
                            <flux:callout.heading>{{ __('Rozpoznanie zlyhalo') }}</flux:callout.heading>
                            <flux:callout.text>{{ __('Použitie bolo vrátené. Môžeš to skúsiť znova (nové použitie) alebo fotku zahodiť.') }}</flux:callout.text>
                            <x-slot name="actions">
                                <flux:button size="sm" wire:click="retry" data-test="meal-retry">{{ __('Skúsiť znova') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="discard">{{ __('Zahodiť') }}</flux:button>
                            </x-slot>
                        </flux:callout>
                    @endif
                </div>
            </div>

            @if ($analysis->isUnusable())
                <flux:callout icon="eye-slash" variant="secondary" data-test="meal-unusable">
                    <flux:callout.heading>{{ __('Nedokážem určiť') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ $analysis->ai_status?->label() ? ucfirst($analysis->ai_status->label()).'. ' : '' }}{{ __('Bez zložiek sa nič nepočíta a použitie nebolo odpočítané.') }}
                        @foreach ($analysis->limitations ?? [] as $limitation) <span class="block">{{ $limitation }}</span> @endforeach
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="startNew" data-test="meal-new">{{ __('Nahrať inú fotku (nová analýza)') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="discard">{{ __('Zahodiť') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

            @if ($analysis->status === \App\Enums\MealAnalysisStatus::NeedsReview && ! ($job && $job->status->isActive()))
                <flux:text class="text-sm">{{ __('AI navrhla viditeľné zložky. Skontroluj názvy, priradenú potravinu a gramáž – odhad AI ostáva odhadom, kým ho nepotvrdíš alebo neodvážiš. Ručné úpravy a prepočet sú bez AI a bez použití.') }}</flux:text>

                @if (($analysis->questions ?? []) !== [])
                    <div class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/40" data-test="meal-questions">
                        <div class="text-sm font-medium">{{ __('AI sa pýta') }}</div>
                        <ul class="list-disc space-y-0.5 pl-5 text-sm">
                            @foreach ($analysis->questions as $question)<li>{{ $question }}</li>@endforeach
                        </ul>
                        @if ($analysis->clarification_count < $maxClarifications)
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <flux:input wire:model="answer" :label="__('Odpoveď')" class="flex-1" maxlength="500" data-test="meal-answer" />
                                <flux:button size="sm" wire:click="clarify" wire:loading.attr="disabled" data-test="meal-clarify">{{ __('Doplniť a prepracovať návrh') }}</flux:button>
                            </div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Doplnenie s AI je bez ďalšieho odpočtu (zostáva :left z :max). Prepracovaný návrh nahradí zoznam zložiek.', ['left' => $maxClarifications - $analysis->clarification_count, 'max' => $maxClarifications]) }}</flux:text>
                        @else
                            <flux:text class="text-xs text-zinc-500">{{ __('Ďalšie doplnenie s AI už nie je možné – zložky uprav ručne.') }}</flux:text>
                        @endif
                    </div>
                @endif

                <ul class="divide-y divide-zinc-100 dark:divide-zinc-700" data-test="meal-items">
                    @foreach ($this->rows as $row)
                        @php($item = $row['item'])
                        @php($record = $item->effectiveRecord())
                        <li class="space-y-2 py-3 {{ $item->included ? '' : 'opacity-60' }}" wire:key="item-{{ $item->id }}" data-test="meal-item-{{ $item->id }}">
                            <div class="flex flex-wrap items-end gap-2">
                                <flux:input size="sm" wire:model="labels.{{ $item->id }}" wire:keydown.enter="saveLabel({{ $item->id }})" :label="__('Zložka')" class="w-full sm:w-56" maxlength="120" data-test="label-{{ $item->id }}" />
                                <flux:button size="sm" variant="ghost" wire:click="saveLabel({{ $item->id }})" data-test="save-label-{{ $item->id }}">{{ __('Premenovať') }}</flux:button>
                                @if ($item->is_unknown)
                                    <flux:badge size="sm" color="zinc" data-test="unknown-{{ $item->id }}">{{ __('neznáma zložka') }}</flux:badge>
                                @endif
                                @unless ($item->included)
                                    <flux:badge size="sm" color="zinc">{{ __('nezapočítané') }}</flux:badge>
                                @endunless
                            </div>
                            @if ($item->visible_evidence || $item->portion_basis)
                                <flux:text class="text-xs text-zinc-500">{{ $item->visible_evidence }}@if ($item->visible_evidence && $item->portion_basis) · @endif{{ $item->portion_basis }}</flux:text>
                            @endif
                            @if (($item->assumptions ?? []) !== [])
                                <flux:text class="text-xs text-zinc-500">{{ __('Predpoklady AI: :list', ['list' => implode('; ', $item->assumptions)]) }}</flux:text>
                            @endif

                            <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                @if ($row['candidates'] !== [] && ! $item->is_unknown)
                                    <flux:select size="sm" wire:model.live="choices.{{ $item->id }}" :label="__('Potravina z databázy')" data-test="choice-{{ $item->id }}">
                                        <flux:select.option value="">{{ __('— bez potraviny (nezapočítať) —') }}</flux:select.option>
                                        @foreach ($row['candidates'] as $candidate)
                                            <flux:select.option value="{{ $candidate['record_id'] }}">{{ $candidate['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @elseif (! $item->is_unknown)
                                    <div class="self-end text-sm text-zinc-500" data-test="no-match-{{ $item->id }}">{{ __('V slovníku potravín nie je zhoda – premenuj zložku alebo ju nechaj mimo súčtu (výsledok bude čiastočný).') }}</div>
                                @else
                                    <div class="self-end text-sm text-zinc-500">{{ __('Neznáma zložka sa nezapočíta a výsledok bude čiastočný – nikdy nie nula.') }}</div>
                                @endif
                                @if ($record !== null)
                                    <flux:text class="self-end text-xs text-zinc-500">{{ __('na 100 g: :kcal', ['kcal' => $formatter->value('energy_kcal', $record->nutrients()['energy_kcal'])]) }}</flux:text>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-end gap-2">
                                <flux:input size="sm" type="text" inputmode="decimal" wire:model="gramsInput.{{ $item->id }}" :label="__('Gramáž (g)')" class="w-28" data-test="grams-input-{{ $item->id }}" />
                                <flux:select size="sm" wire:model="originInput.{{ $item->id }}" :label="__('Pôvod')" class="w-48" data-test="origin-{{ $item->id }}">
                                    <flux:select.option value="confirmed">{{ __('potvrdené (odhad sedí)') }}</flux:select.option>
                                    <flux:select.option value="measured">{{ __('odvážené') }}</flux:select.option>
                                    <flux:select.option value="estimated">{{ __('môj odhad') }}</flux:select.option>
                                </flux:select>
                                <flux:button size="sm" wire:click="saveGrams({{ $item->id }})" data-test="save-grams-{{ $item->id }}">{{ __('Uložiť gramáž') }}</flux:button>
                                @if ($item->grams !== null)
                                    <span class="text-sm" data-test="grams-{{ $item->id }}">{{ $formatter->weight((float) $item->grams) }} g</span>
                                    <flux:badge size="sm" :color="$item->isEstimate() ? 'amber' : 'green'" data-test="grams-origin-{{ $item->id }}">{{ $item->grams_origin?->label() }}</flux:badge>
                                    @if ($item->estimated_grams !== null && ! $item->isEstimate())
                                        <span class="text-xs text-zinc-500">{{ __('odhad AI: :grams g', ['grams' => $formatter->weight((float) $item->estimated_grams)]) }}</span>
                                    @endif
                                @else
                                    <span class="text-sm text-zinc-500" data-test="grams-{{ $item->id }}">{{ __('bez gramáže') }}</span>
                                @endif
                            </div>
                            @error("gramsInput.$item->id") <flux:text class="text-xs text-red-600">{{ $message }}</flux:text> @enderror

                            <div class="flex flex-wrap gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="toggleIncluded({{ $item->id }})" data-test="toggle-{{ $item->id }}">{{ $item->included ? __('Nezapočítať') : __('Započítať') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="markUnknown({{ $item->id }})" data-test="mark-unknown-{{ $item->id }}">{{ $item->is_unknown ? __('Určiť zložku') : __('Neznáma zložka') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeItem({{ $item->id }})" data-test="remove-{{ $item->id }}">{{ __('Odobrať') }}</flux:button>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-wrap items-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                    <flux:input size="sm" wire:model="newLabel" :label="__('Pridať zložku')" :placeholder="__('napr. olej na vyprážanie')" class="w-full sm:w-56" maxlength="120" data-test="new-label" />
                    <flux:input size="sm" type="text" inputmode="decimal" wire:model="newGrams" :label="__('Gramáž (g, voliteľné)')" class="w-32" data-test="new-grams" />
                    <flux:button size="sm" icon="plus" wire:click="addItem" data-test="add-item">{{ __('Pridať') }}</flux:button>
                </div>
                @error('newLabel') <flux:text class="text-xs text-red-600">{{ $message }}</flux:text> @enderror
                @error('newGrams') <flux:text class="text-xs text-red-600">{{ $message }}</flux:text> @enderror

                @if ($preview = $this->preview)
                    <div class="flex flex-wrap items-center gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60" data-test="meal-preview">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Predbežný súčet') }}</div>
                            <div class="font-display text-2xl font-bold">{{ $formatter->value('energy_kcal', $preview->totals['energy_kcal'] ?? null) }}</div>
                        </div>
                        <flux:badge size="sm" :color="$preview->isPartial() ? 'amber' : 'green'" data-test="meal-preview-completeness">{{ $preview->completeness->label() }}</flux:badge>
                        @if ($preview->missing !== [])
                            <flux:text class="text-xs text-zinc-500">{{ __('Chýba: :list', ['list' => collect($preview->missing)->pluck('name')->implode(', ')]) }}</flux:text>
                        @endif
                    </div>
                @endif

                @if (($analysis->limitations ?? []) !== [])
                    <flux:text class="text-xs text-zinc-500">{{ __('Z fotky nevidno: :list', ['list' => implode('; ', $analysis->limitations)]) }}</flux:text>
                @endif

                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <flux:button variant="primary" icon="check" wire:click="confirm" wire:loading.attr="disabled" data-test="meal-confirm">{{ __('Potvrdiť a vypočítať') }}</flux:button>
                    <flux:button wire:click="confirm(false)" wire:loading.attr="disabled" data-test="meal-confirm-without">{{ __('Uložiť bez kalórií') }}</flux:button>
                    <flux:checkbox wire:model="keepPhoto" :label="__('Ponechať fotku pri zázname')" data-test="meal-keep-photo" />
                    <flux:button variant="ghost" wire:click="discard" data-test="meal-discard">{{ __('Zahodiť') }}</flux:button>
                </div>
                <flux:text class="text-xs text-zinc-500">{{ __('Bez ponechania sa pracovná fotka zmaže :hours h po vyhodnotení. Zmena fotky = nová analýza s novým odpočtom.', ['hours' => $photoTtlHours]) }}</flux:text>
            @endif

            @if ($analysis->isConfirmed())
                @php($nutrition = $analysis->nutrition)
                @if ($nutrition === null)
                    <flux:callout icon="check-circle" variant="secondary" data-test="meal-no-nutrition">{{ __('Uložené bez kalórií – zložky sú zaznamenané, výpočet sa nerobil.') }}</flux:callout>
                @else
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <flux:badge size="sm" :color="$analysis->isPartial() ? 'amber' : 'green'" :icon="$analysis->isPartial() ? 'exclamation-triangle' : 'check'" data-test="meal-completeness">{{ \App\Enums\NutritionCompleteness::from($nutrition['completeness'])->label() }}</flux:badge>
                        <span class="text-zinc-500">{{ __('Potvrdené :date', ['date' => $analysis->confirmed_at?->timezone(app(\App\Support\CurrentHousehold::class)->timezone())->translatedFormat('j. n. Y H:i')]) }}</span>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-3 sm:w-72 dark:border-zinc-700" data-test="meal-result">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Celé jedlo na fotke') }}</div>
                        <div class="mt-2 font-display text-2xl font-bold" data-test="meal-kcal">{{ $formatter->value('energy_kcal', $nutrition['totals']['energy_kcal'] ?? null) }}</div>
                        <dl class="mt-2 space-y-0.5 text-sm">
                            @foreach (['protein_g', 'carbohydrate_g', 'fat_g', 'fiber_g'] as $nutrient)
                                <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ $formatter::label($nutrient) }}</dt><dd class="font-medium">{{ $formatter->value($nutrient, $nutrition['totals'][$nutrient] ?? null) }}</dd></div>
                            @endforeach
                        </dl>
                    </div>
                    @if (($nutrition['missing'] ?? []) !== [])
                        <div class="text-sm" data-test="meal-missing">
                            <div class="font-medium">{{ __('Chýba v súčte') }}</div>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-zinc-600 dark:text-zinc-300">
                                @foreach ($nutrition['missing'] as $missing)<li><span class="font-medium">{{ $missing['name'] }}</span> – {{ $missing['reason'] }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    @if (($nutrition['assumptions'] ?? []) !== [])
                        <div class="text-sm" data-test="meal-assumptions">
                            <div class="font-medium">{{ __('Predpoklady') }}</div>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-zinc-600 dark:text-zinc-300">
                                @foreach ($nutrition['assumptions'] as $assumption)<li>{{ $assumption }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    <flux:accordion transition>
                        <flux:accordion.item :heading="__('Zložky a zdroje')">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs uppercase tracking-wide text-zinc-500"><tr><th class="py-1 pr-2">{{ __('Zložka') }}</th><th class="py-1 pr-2">{{ __('Potravina (zdroj)') }}</th><th class="py-1 text-right">{{ __('Gramáž') }}</th></tr></thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    @foreach ($nutrition['components'] as $component)
                                        <tr class="{{ ($component['included'] ?? false) ? '' : 'text-zinc-400' }}" data-test="meal-component-{{ $component['line_id'] ?? $loop->index }}">
                                            <td class="py-1.5 pr-2 align-top">{{ $component['name'] }}</td>
                                            <td class="py-1.5 pr-2 align-top">
                                                @if ($component['source'])
                                                    <div>{{ $component['source']['name_sk'] ?? $component['source']['name'] }}</div>
                                                    <div class="text-xs text-zinc-500">{{ $component['source']['name'] }} · {{ strtoupper(str_replace('_', ' ', $component['source']['provider'])) }} {{ $component['source']['external_id'] }}</div>
                                                @else
                                                    <span class="text-xs">{{ $component['unresolved_reason'] ?? __('bez potraviny') }}</span>
                                                @endif
                                            </td>
                                            <td class="py-1.5 text-right align-top whitespace-nowrap">
                                                @if ($component['grams'] !== null)
                                                    {{ $formatter->weight((float) $component['grams']) }} g
                                                    <div class="text-xs text-zinc-500">{{ \App\Enums\FoodGramsOrigin::tryFrom($component['grams_origin'] ?? '')?->label() }}</div>
                                                @else
                                                    {{ $formatter::DASH }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </flux:accordion.item>
                    </flux:accordion>
                @endif

                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <flux:button variant="primary" icon="clipboard-document-list" :href="route('diary.index', ['analyza' => $analysis->id])" wire:navigate data-test="meal-log">{{ __('Zjedol som') }}</flux:button>
                    <flux:button variant="ghost" icon="pencil-square" wire:click="edit" data-test="meal-edit">{{ __('Upraviť zložky') }}</flux:button>
                    <flux:button variant="ghost" icon="camera" wire:click="startNew" data-test="meal-new">{{ __('Nová fotka') }}</flux:button>
                    <flux:button variant="ghost" wire:click="discard" data-test="meal-discard">{{ __('Zahodiť') }}</flux:button>
                </div>
                <flux:text class="text-xs text-zinc-500">{{ __('Orientačný výpočet z databázy potravín (USDA FoodData Central, CC0) podľa potvrdených zložiek a gramáží; odhady sú označené. Nie je to medicínske meranie ani záruka zloženia či alergénov. „Zjedol som“ zapíše do súkromného denníka, koľko si z jedla naozaj zjedol(a).') }}</flux:text>
            @endif
        </flux:card>
    @endif

    @if ($this->recent->isNotEmpty())
        <flux:card class="space-y-2" data-test="meal-recent">
            <flux:heading size="lg" class="font-display">{{ __('Moje posledné fotky') }}</flux:heading>
            <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-700">
                @foreach ($this->recent as $recent)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="recent-{{ $recent->id }}">
                        <button type="button" wire:click="open({{ $recent->id }})" class="text-left font-medium underline-offset-2 hover:underline" data-test="open-{{ $recent->id }}">{{ $recent->dish_name ?? __('Jedlo z fotky') }} <span class="text-xs font-normal text-zinc-500">· {{ $recent->created_at?->timezone(app(\App\Support\CurrentHousehold::class)->timezone())->translatedFormat('j. n. H:i') }}</span></button>
                        <div class="flex items-center gap-2">
                            @if ($recent->isConfirmed() && $recent->nutrition !== null)
                                <span class="text-xs text-zinc-500">{{ $formatter->value('energy_kcal', $recent->nutrition['totals']['energy_kcal'] ?? null) }}</span>
                            @endif
                            <flux:badge size="sm" :color="match ($recent->status->value) { 'confirmed' => 'green', 'needs_review' => 'blue', 'analyzing' => 'amber', default => 'zinc' }">{{ $recent->status->label() }}</flux:badge>
                        </div>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif
</div>
