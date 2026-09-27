<div>
<flux:modal wire:model="open" class="w-full md:w-[28rem]">
    @if ($this->recipe)
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" class="font-display">{{ $saved ? __('Naplánované') : __('Chcem variť') }}</flux:heading>
                <flux:text class="mt-1">{{ $this->recipe->title }}</flux:text>
            </div>

            @if ($saved)
                <flux:callout icon="check-circle" variant="success">
                    {{ __('Jedlo je v pláne. Uvarenie potvrdíš neskôr v Pláne – samotné naplánovanie sa do histórie nepočíta.') }}
                </flux:callout>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <flux:button variant="primary" wire:click="done" class="w-full" data-test="plan-done">{{ __('Hotovo') }}</flux:button>
                    @if ($sessionId)
                        <flux:button wire:click="next" class="w-full" data-test="plan-next">{{ __('Vybrať ďalšie jedlo') }}</flux:button>
                    @endif
                    <flux:button :href="route('plan.index')" wire:navigate variant="ghost" class="w-full">{{ __('Otvoriť plán') }}</flux:button>
                </div>
            @else
                <form wire:submit="save" class="space-y-5">
                    <flux:fieldset>
                        <flux:legend>{{ __('Pre koho') }}</flux:legend>
                        <div class="flex flex-wrap gap-2">
                            @forelse ($this->people as $person)
                                <label class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition {{ in_array($person->id, $personIds) ? 'border-accent bg-accent/10 font-medium text-accent-content' : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-600' }}">
                                    <input type="checkbox" wire:model.live="personIds" value="{{ $person->id }}" class="sr-only" />
                                    <x-person-avatar :person="$person" size="size-5" />
                                    {{ $person->name }}
                                </label>
                            @empty
                                <flux:text>{{ __('Najprv pridaj stravníka v sekcii Rodina.') }}</flux:text>
                            @endforelse
                        </div>
                    </flux:fieldset>

                    <flux:radio.group wire:model="mealType" :label="__('Typ jedla')" variant="segmented" size="sm">
                        <flux:radio value="any" :label="__('Čokoľvek')" />
                        @foreach ($mealTypes as $type)
                            <flux:radio :value="$type->value" :label="$type->label()" />
                        @endforeach
                    </flux:radio.group>

                    <flux:radio.group wire:model.live="term" :label="__('Termín')">
                        <flux:radio value="today" :label="__('Dnes')" />
                        <flux:radio value="tomorrow" :label="__('Zajtra')" />
                        <flux:radio value="date" :label="__('Konkrétny dátum')" />
                        <flux:radio value="this_week" :label="__('Tento týždeň – bez dňa')" />
                        <flux:radio value="next_week" :label="__('Budúci týždeň – bez dňa')" />
                        <flux:radio value="unknown" :label="__('Niekedy')" />
                    </flux:radio.group>

                    @if ($term === 'date')
                        <flux:date-picker wire:model.live="date" :label="__('Dátum')" with-today />
                    @endif

                    <flux:input type="number" min="1" wire:model="servings" :label="__('Porcie')" :description="__('Návrh: 1 osoba = 1 porcia. Uprav podľa potreby.')" />

                    @if ($this->collisions->isNotEmpty())
                        <flux:callout icon="exclamation-triangle" variant="warning">
                            <flux:callout.heading>{{ __('Už je v pláne') }}</flux:callout.heading>
                            <flux:callout.text>
                                @foreach ($this->collisions as $collision)
                                    <div>{{ $collision->termLabel() }}@if($collision->meal_type) · {{ $collision->meal_type->label() }}@endif</div>
                                @endforeach
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <flux:button size="xs" :href="route('plan.index')" wire:navigate>{{ __('Otvoriť existujúci plán') }}</flux:button>
                                    <flux:checkbox wire:model="forceDuplicate" :label="__('Vedome pridať ďalší')" />
                                </div>
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($error)
                        <flux:callout icon="exclamation-circle" variant="danger">{{ $error }}</flux:callout>
                    @endif

                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary" class="w-full" data-test="plan-save">{{ __('Uložiť do plánu') }}</flux:button>
                        <flux:button wire:click="close" variant="ghost">{{ __('Zavrieť') }}</flux:button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</flux:modal>
</div>
