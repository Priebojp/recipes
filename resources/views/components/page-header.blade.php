@props(['title', 'back' => null])

<div {{ $attributes->merge(['class' => 'mb-4 flex items-center gap-3']) }}>
    @if ($back)
        <flux:button :href="$back" wire:navigate variant="ghost" icon="chevron-left" size="sm" aria-label="Späť" />
    @endif
    <flux:heading size="xl" class="flex-1 truncate">{{ $title }}</flux:heading>
    {{ $slot }}
</div>
