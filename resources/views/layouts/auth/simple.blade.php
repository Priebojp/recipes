<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper antialiased dark:bg-zinc-900">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="mb-1 flex size-12 items-center justify-center rounded-2xl bg-accent text-accent-foreground shadow-sm">
                        <x-app-logo-icon class="size-7" />
                    </span>
                    <span class="font-display text-xl font-semibold">{{ config('app.name') }}</span>
                </a>
                <flux:card class="flex flex-col gap-6">
                    {{ $slot }}
                </flux:card>
            </div>
            @include('partials.footer', ['class' => 'pb-0 pt-2'])
        </div>
        @include('partials.consent')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
