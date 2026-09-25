<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @php
            $nav = [
                ['route' => 'cook.index', 'label' => 'Čo variť', 'icon' => 'sparkles', 'match' => 'cook.*'],
                ['route' => 'recipes.index', 'label' => 'Recepty', 'icon' => 'book-open', 'match' => 'recipes.*'],
                ['route' => 'plan.index', 'label' => 'Plán', 'icon' => 'calendar-days', 'match' => 'plan.*'],
                ['route' => 'family.index', 'label' => 'Rodina', 'icon' => 'users', 'match' => 'family.*'],
            ];
        @endphp

        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('cook.index') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Domácnosť')" class="grid">
                    @foreach ($nav as $item)
                        <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['match'])" wire:navigate>
                            {{ $item['label'] }}
                        </flux:sidebar.item>
                    @endforeach
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="clock" :href="route('plan.history')" :current="request()->routeIs('plan.history')" wire:navigate>
                    História varenia
                </flux:sidebar.item>
                <flux:sidebar.item icon="cog-6-tooth" :href="route('household.edit')" :current="request()->routeIs('household.edit')" wire:navigate>
                    Nastavenia domácnosti
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile header -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:heading class="truncate">{{ $title ?? config('app.name') }}</flux:heading>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('household.edit')" icon="home" wire:navigate>
                            Domácnosť
                        </flux:menu.item>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <div class="pb-20 lg:pb-0">
            {{ $slot }}
        </div>

        <!-- Mobile bottom navigation -->
        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-zinc-200 bg-white/95 backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95" aria-label="Hlavná navigácia">
            @foreach ($nav as $item)
                @php($active = request()->routeIs($item['match']))
                <a href="{{ route($item['route']) }}" wire:navigate
                   class="flex flex-col items-center gap-1 py-2 text-xs {{ $active ? 'text-accent font-semibold' : 'text-zinc-500 dark:text-zinc-400' }}"
                   @if($active) aria-current="page" @endif>
                    <flux:icon :name="$item['icon']" class="size-6" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
