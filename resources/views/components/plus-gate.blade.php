{{-- Locked state of a Plus feature: what it does, who can order it, and that nothing else is restricted. --}}
@props(['feature', 'household'])

@php($isOwner = auth()->user()->can('manage', $household))

<flux:callout icon="sparkles" variant="secondary" data-test="plus-gate">
    <flux:callout.heading>{{ $feature->label() }} je súčasťou programu Plus</flux:callout.heading>
    <flux:callout.text>
        <p>{{ $feature->description() }}</p>
        <p class="mt-2">Recepty, fotografie, ručný plán a história fungujú bez predplatného ďalej. Kúpa balíkov AI použití Plus nezakladá.</p>
        @unless ($isOwner)
            <p class="mt-2">Predplatné môže objednať iba vlastník domácnosti.</p>
        @endunless
    </flux:callout.text>
    <x-slot name="actions">
        <flux:button :href="route('pricing')" wire:navigate size="sm" variant="primary">Pozrieť Plus a cenník</flux:button>
        @if ($isOwner)
            <flux:button :href="route('subscription.edit')" wire:navigate size="sm" variant="ghost">Predplatné</flux:button>
        @endif
    </x-slot>
</flux:callout>
