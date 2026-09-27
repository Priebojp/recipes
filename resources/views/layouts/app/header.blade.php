<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        @php
            $nav = [
                ['route' => 'cook.index', 'label' => __('Čo variť'), 'icon' => 'sparkles', 'match' => 'cook.*'],
                ['route' => 'recipes.index', 'label' => __('Recepty'), 'icon' => 'book-open', 'match' => 'recipes.*'],
                ['route' => 'plan.index', 'label' => __('Plán'), 'icon' => 'calendar-days', 'match' => 'plan.*'],
                ['route' => 'family.index', 'label' => __('Rodina'), 'icon' => 'users', 'match' => 'family.*'],
            ];
            $accountActive = request()->routeIs('household.edit', 'plan.history', 'profile.edit', 'security.edit', 'appearance.edit', 'subscription.edit', 'usage.index', 'privacy.edit');
        @endphp

        {{-- Desktop: one slim top bar with the same pill tabs as the phone bottom bar; the account menu sits under the avatar. --}}
        <header class="sticky top-0 z-30 hidden [grid-area:header] border-b border-zinc-200/70 bg-paper/90 backdrop-blur lg:block">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-6 lg:px-8">
                <a href="{{ route('cook.index') }}" wire:navigate class="me-3 flex shrink-0 items-center gap-2.5" aria-label="{{ config('app.name') }}">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-accent text-accent-foreground shadow-sm">
                        <x-app-logo-icon class="size-5" />
                    </span>
                    <span class="font-display text-lg font-semibold text-zinc-900 dark:text-white">{{ config('app.name') }}</span>
                </a>

                <nav class="flex flex-1 items-center justify-center gap-1" aria-label="{{ __('Hlavná navigácia') }}">
                    @foreach ($nav as $item)
                        @php($active = request()->routeIs($item['match']))
                        <a href="{{ route($item['route']) }}" wire:navigate
                           class="flex items-center gap-2 whitespace-nowrap rounded-full px-3.5 py-2 text-sm font-medium transition {{ $active ? 'bg-accent/10 text-accent' : 'text-zinc-600 hover:bg-zinc-900/5 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' }}"
                           @if($active) aria-current="page" @endif>
                            <flux:icon :name="$item['icon']" :variant="$active ? 'solid' : 'outline'" class="size-5" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <flux:button :href="route('recipes.create')" wire:navigate variant="filled" icon="plus" size="sm" class="shrink-0 rounded-full" data-test="header-new-recipe">
                    {{ __('Nový recept') }}
                </flux:button>

                <flux:dropdown position="bottom" align="end">
                    <button type="button"
                            class="flex shrink-0 items-center rounded-full p-0.5 transition ring-offset-2 ring-offset-paper focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent {{ $accountActive ? 'ring-2 ring-accent' : 'hover:bg-zinc-900/5 dark:hover:bg-white/5' }}"
                            aria-label="{{ __('Účet a nastavenia') }}" data-test="desktop-menu-button">
                        <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" size="sm" color="auto" />
                    </button>
                    <x-user-menu />
                </flux:dropdown>
            </div>
        </header>

        {{-- Mobile: a slim title bar; all navigation lives in the bottom bar. --}}
        <flux:header class="sticky top-0 z-30 h-14 items-center gap-3 border-b border-zinc-200/70 bg-paper/90 !px-4 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-900/90">
            <a href="{{ route('cook.index') }}" wire:navigate class="flex size-8 items-center justify-center rounded-lg bg-accent text-accent-foreground" aria-label="{{ config('app.name') }}">
                <x-app-logo-icon class="size-4" />
            </a>
            <flux:heading class="truncate font-display text-lg">{{ isset($title) ? __($title) : config('app.name') }}</flux:heading>
        </flux:header>

        {{ $slot }}

        @include('partials.consent')

        {{-- Mobile bottom navigation: four sections plus the account menu. --}}
        <nav class="pb-safe fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200/70 bg-paper/95 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-900/95" aria-label="{{ __('Hlavná navigácia') }}">
            <div class="grid grid-cols-5">
                @foreach ($nav as $item)
                    @php($active = request()->routeIs($item['match']))
                    <a href="{{ route($item['route']) }}" wire:navigate
                       class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $active ? 'text-accent' : 'text-zinc-500 dark:text-zinc-400' }}"
                       @if($active) aria-current="page" @endif>
                        <span class="flex h-7 w-12 items-center justify-center rounded-full {{ $active ? 'bg-accent/10' : '' }}">
                            <flux:icon :name="$item['icon']" :variant="$active ? 'solid' : 'outline'" class="size-5" />
                        </span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach

                <flux:dropdown position="top" align="end">
                    <button type="button" class="flex w-full flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $accountActive ? 'text-accent' : 'text-zinc-500 dark:text-zinc-400' }}" data-test="mobile-menu-button">
                        <span class="flex h-7 w-12 items-center justify-center">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" size="xs" color="auto" />
                        </span>
                        <span>{{ __('Viac') }}</span>
                    </button>
                    <x-user-menu />
                </flux:dropdown>
            </div>
        </nav>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
