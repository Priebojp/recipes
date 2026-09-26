<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        @php
            $nav = [
                ['route' => 'cook.index', 'label' => 'Čo variť', 'icon' => 'sparkles', 'match' => 'cook.*'],
                ['route' => 'recipes.index', 'label' => 'Recepty', 'icon' => 'book-open', 'match' => 'recipes.*'],
                ['route' => 'plan.index', 'label' => 'Plán', 'icon' => 'calendar-days', 'match' => 'plan.*'],
                ['route' => 'family.index', 'label' => 'Rodina', 'icon' => 'users', 'match' => 'family.*'],
            ];
        @endphp

        {{-- Desktop: the sidebar is the only menu. --}}
        <flux:sidebar sticky collapsible="mobile" class="max-lg:hidden border-e border-zinc-200/70 bg-paper dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('cook.index') }}" wire:navigate />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($nav as $item)
                    <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['match'])" wire:navigate>
                        {{ $item['label'] }}
                    </flux:sidebar.item>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="plus" :href="route('recipes.create')" :current="request()->routeIs('recipes.create')" wire:navigate>
                    Nový recept
                </flux:sidebar.item>
                <flux:sidebar.item icon="globe-alt" :href="route('home')" wire:navigate>
                    Verejné recepty
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:dropdown position="top" align="start" class="max-lg:hidden">
                <flux:sidebar.profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                    data-test="sidebar-menu-button"
                />
                <x-user-menu />
            </flux:dropdown>
        </flux:sidebar>

        {{-- Mobile: a slim title bar; all navigation lives in the bottom bar. --}}
        <flux:header class="sticky top-0 z-30 h-14 items-center gap-3 border-b border-zinc-200/70 bg-paper/90 !px-4 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-900/90">
            <a href="{{ route('cook.index') }}" wire:navigate class="flex size-8 items-center justify-center rounded-lg bg-accent text-accent-foreground" aria-label="{{ config('app.name') }}">
                <x-app-logo-icon class="size-4" />
            </a>
            <flux:heading class="truncate font-display text-lg">{{ $title ?? config('app.name') }}</flux:heading>
        </flux:header>

        {{ $slot }}

        @include('partials.consent')

        {{-- Mobile bottom navigation: four sections plus the account menu. --}}
        <nav class="pb-safe fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200/70 bg-paper/95 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-900/95" aria-label="Hlavná navigácia">
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
                    <button type="button" class="flex w-full flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ request()->routeIs('household.edit', 'plan.history', 'profile.edit', 'security.edit', 'appearance.edit') ? 'text-accent' : 'text-zinc-500 dark:text-zinc-400' }}" data-test="mobile-menu-button">
                        <span class="flex h-7 w-12 items-center justify-center">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" size="xs" color="auto" />
                        </span>
                        <span>Viac</span>
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
