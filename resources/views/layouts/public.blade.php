<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="sticky top-0 z-30 border-b border-zinc-200/70 bg-paper/85 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/85">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
                <x-app-logo href="{{ route('home') }}" wire:navigate />

                <flux:spacer />

                <nav class="flex items-center gap-2">
                    @auth
                        <flux:button :href="route('cook.index')" wire:navigate variant="primary" icon="sparkles" size="sm">Otvoriť aplikáciu</flux:button>
                    @else
                        <flux:button :href="route('login')" wire:navigate variant="ghost" size="sm">Prihlásiť sa</flux:button>
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" wire:navigate variant="primary" size="sm">Vytvoriť účet</flux:button>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 sm:py-12">
            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-6xl px-4 pb-10 pt-6 text-center text-sm text-zinc-500 sm:px-6">
            {{ config('app.name') }} · rodinná kuchárka
        </footer>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
