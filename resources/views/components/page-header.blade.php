@props(['title', 'back' => null, 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'mb-6 flex items-center gap-3']) }}>
    @if ($back)
        <flux:button :href="$back" wire:navigate variant="ghost" icon="chevron-left" size="sm" aria-label="Späť" />
    @endif
    <div class="min-w-0 flex-1">
        <h1 class="truncate font-display text-2xl font-bold leading-tight text-zinc-900 sm:text-3xl dark:text-white">{{ $title }}</h1>
        @if ($subtitle)
            <flux:text class="mt-0.5 text-sm">{{ $subtitle }}</flux:text>
        @endif
    </div>
    {{ $slot }}
</div>
