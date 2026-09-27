<div class="space-y-3">
    @foreach ($this->people as $person)
        @php($current = $this->preferences[$person->id] ?? '')
        @php($excluded = array_key_exists($person->id, $this->exclusions))
        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <x-person-avatar :person="$person" />
                <span class="flex-1 truncate font-medium">{{ $person->name }}</span>
                @if ($current === '')
                    <flux:badge size="sm" color="zinc">{{ __('Nehodnotené') }}</flux:badge>
                @endif
                @if ($excluded)
                    <flux:badge size="sm" color="red">{{ __('Neponúkať') }}</flux:badge>
                @endif
            </div>

            <div class="mt-2 flex flex-wrap gap-2">
                <flux:button size="xs" :variant="$current === '' ? 'primary' : 'filled'" wire:click="set({{ $person->id }}, '')">{{ __('Nehodnotené') }}</flux:button>
                @foreach ($options as $option)
                    <flux:button size="xs" :variant="$current === $option->value ? 'primary' : 'filled'" wire:click="set({{ $person->id }}, '{{ $option->value }}')" data-test="pref-{{ $person->id }}-{{ $option->value }}">{{ $option->label() }}</flux:button>
                @endforeach
            </div>

            <div class="mt-2 text-sm">
                @if ($excluded)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-zinc-500">{{ $this->exclusions[$person->id] !== '' ? __('Pevná výluka: :reason', ['reason' => $this->exclusions[$person->id]]) : __('Pevná výluka') }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="removeExclusion({{ $person->id }})">{{ __('Zrušiť výluku') }}</flux:button>
                    </div>
                @elseif ($excludeFormFor === $person->id)
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <flux:input size="sm" wire:model="reasons.{{ $person->id }}" :placeholder="__('Dôvod (voliteľné), napr. alergia')" />
                        <flux:button size="sm" variant="danger" wire:click="exclude({{ $person->id }})" data-test="exclude-{{ $person->id }}">{{ __('Neponúkať') }}</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="$set('excludeFormFor', null)">{{ __('Zrušiť') }}</flux:button>
                    </div>
                @else
                    <flux:button size="xs" variant="ghost" wire:click="$set('excludeFormFor', {{ $person->id }})">{{ __('Neponúkať tomuto človeku…') }}</flux:button>
                @endif
            </div>
        </div>
    @endforeach
</div>
