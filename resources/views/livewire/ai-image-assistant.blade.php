<div class="space-y-2" @if ($this->job && $this->job->status->isActive()) wire:poll.4s="refreshStatus" @endif>
    <flux:button size="sm" variant="ghost" icon="sparkles" wire:click="$toggle('open')" data-test="ai-image-toggle">{{ __('Vygenerovať obrázok pomocou AI…') }}</flux:button>

    @if ($open || $this->job)
        <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            @if ($this->unavailable && ! $this->job)
                <flux:callout icon="information-circle" variant="secondary">{{ $this->unavailable }}</flux:callout>
            @endif

            @if ($job = $this->job)
                @if ($job->status->isActive())
                    <div class="flex items-center gap-2 text-sm text-zinc-500"><flux:icon name="arrow-path" class="size-4 animate-spin" /> {{ __('Generujem obrázok… recept môžeš ďalej používať.') }}</div>
                @elseif ($job->status->value === 'reconciling')
                    <flux:callout icon="clock" variant="warning">
                        <flux:callout.heading>{{ __('Výsledok sa overuje') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Spojenie s AI vypršalo a nevieme, či obrázok vznikol. Použitie zostáva rezervované, kým to overíme – nebude odpočítané dvakrát. Existujúca fotografia zostáva.') }}</flux:callout.text>
                        <x-slot name="actions"><flux:button size="sm" wire:click="discard">{{ __('Zavrieť') }}</flux:button></x-slot>
                    </flux:callout>
                @elseif ($job->status->value === 'failed')
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        <flux:callout.heading>{{ __('Generovanie zlyhalo') }}</flux:callout.heading>
                        <flux:callout.text>{{ $job->error }} {{ __('Existujúca fotografia zostáva.') }}</flux:callout.text>
                        <x-slot name="actions"><flux:button size="sm" wire:click="discard">{{ __('Zavrieť') }}</flux:button></x-slot>
                    </flux:callout>
                @elseif ($job->result_media_id)
                    <div class="space-y-2">
                        <img src="{{ route('media.show', [$job->result_media_id, 'card']) }}" class="aspect-[4/3] w-full max-w-sm rounded-lg object-cover" alt="{{ __('AI ilustrácia jedla') }}" />
                        <flux:text class="text-xs text-zinc-500">{{ __('AI ilustrácia jedla – nie fotografia skutočne uvareného receptu. Z obrázka sa neodvodzujú suroviny ani vhodnosť.') }}</flux:text>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" variant="primary" wire:click="approve" data-test="ai-image-approve">{{ __('Použiť ako hlavnú fotografiu') }}</flux:button>
                            <flux:button size="sm" wire:click="generate(true)">{{ __('Vygenerovať ďalší variant') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="discard">{{ __('Zahodiť') }}</flux:button>
                        </div>
                    </div>
                @endif
            @else
                @php($preview = $this->preview)
                <flux:textarea wire:model.live.debounce.500ms="description" :label="__('Stručný opis výsledku')" rows="2" :placeholder="__('napr. Kuracie kúsky na paprike so smotanovou omáčkou')" />
                <flux:select wire:model.live="mode" :label="__('Servírovanie')">
                    @foreach ($modes as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($preview['needs_description'])
                    <flux:callout icon="question-mark-circle" variant="secondary">{{ $preview['summary'] }}</flux:callout>
                @else
                    <flux:callout icon="eye" variant="secondary">
                        <flux:callout.heading>{{ __('Vygeneruje sa') }}</flux:callout.heading>
                        <flux:callout.text>{{ $preview['summary'] }}@if ($preview['auto_suggested']) <span class="text-zinc-500">{{ __('(nádoba navrhnutá automaticky – potvrď alebo zmeň)') }}</span>@endif</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($error)
                    <flux:callout icon="exclamation-circle" variant="danger">{{ $error }}</flux:callout>
                @endif

                <flux:button size="sm" variant="primary" icon="sparkles" wire:click="generate" :disabled="$preview['needs_description'] || (bool) $this->unavailable" data-test="ai-image-generate">{{ __('Vygenerovať obrázok') }}</flux:button>
                @if ($balance = $this->balance)
                    <flux:text class="text-xs text-zinc-500" data-test="ai-image-balance">
                        {{ __('Spotrebuje 1 použitie (:unit) · zostáva :available', ['unit' => $balance->kind->unitLabel(), 'available' => $balance->available()]) }}
                        @if ($balance->includedTotal > 0) – {{ $balance->includedSourceLabel }}: {{ $balance->includedAvailable }}/{{ $balance->includedTotal }}@endif
                        @if ($balance->purchasedAvailable > 0), {{ __('dokúpené: :count', ['count' => $balance->purchasedAvailable]) }}@endif
                    </flux:text>
                @endif
            @endif
        </div>
    @endif
</div>
