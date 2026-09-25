<div>
<flux:modal wire:model="open" class="w-full md:w-[28rem]">
    @if ($this->recipe)
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $saved ? 'Uvarené' : 'Uvaril som' }}</flux:heading>
                <flux:text class="mt-1">{{ $this->recipe->title }}</flux:text>
            </div>

            @if ($saved)
                <flux:callout icon="check-circle" variant="success">
                    Zapísané do histórie varenia. Omyl opravíš v histórii tlačidlom „Opraviť omyl“.
                </flux:callout>

                <div>
                    <flux:heading size="sm">Chutilo?</flux:heading>
                    <flux:text class="mb-2 text-sm">Voliteľné – rýchlo uprav chute jednotlivých stravníkov.</flux:text>
                    <div class="space-y-2">
                        @foreach ($this->people->whereIn('id', $personIds) as $person)
                            <div class="flex flex-wrap items-center gap-2">
                                <x-person-avatar :person="$person" size="size-6" />
                                <span class="w-24 truncate text-sm">{{ $person->name }}</span>
                                @foreach ($preferenceOptions as $option)
                                    <flux:button size="xs" :variant="($tastes[$person->id] ?? null) === $option->value ? 'primary' : 'filled'"
                                        wire:click="setTaste({{ $person->id }}, '{{ $option->value }}')">{{ $option->label() }}</flux:button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <flux:button variant="primary" wire:click="close" class="w-full" data-test="cooked-done">Hotovo</flux:button>
            @else
                <form wire:submit="save" class="space-y-4">
                    <flux:input type="date" wire:model="cookedOn" label="Skutočný dátum" />

                    <flux:fieldset>
                        <flux:legend>Pre koho sa varilo</flux:legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->people as $person)
                                <label class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm {{ in_array($person->id, $personIds) ? 'border-accent bg-accent/10' : 'border-zinc-300 dark:border-zinc-600' }}">
                                    <input type="checkbox" wire:model.live="personIds" value="{{ $person->id }}" class="sr-only" />
                                    <x-person-avatar :person="$person" size="size-5" />
                                    {{ $person->name }}
                                </label>
                            @endforeach
                        </div>
                    </flux:fieldset>

                    <flux:input type="number" min="1" wire:model="servings" label="Porcie" />
                    <flux:textarea wire:model="note" label="Poznámka" rows="2" placeholder="napr. menej soli nabudúce" />

                    @if ($error)
                        <flux:callout icon="exclamation-circle" variant="danger">{{ $error }}</flux:callout>
                    @endif

                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" data-test="cooked-save">Potvrdiť uvarenie</flux:button>
                        <flux:button wire:click="close" variant="ghost">Zavrieť</flux:button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</flux:modal>
</div>
