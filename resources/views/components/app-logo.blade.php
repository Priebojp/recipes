@props([
    'sidebar' => false,
])

@php($logoClass = 'flex aspect-square size-9 items-center justify-center rounded-xl bg-accent text-accent-foreground shadow-sm')

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name')" {{ $attributes->class('font-display text-lg font-semibold') }}>
        <x-slot name="logo" :class="$logoClass">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name')" {{ $attributes->class('font-display text-lg font-semibold') }}>
        <x-slot name="logo" :class="$logoClass">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
