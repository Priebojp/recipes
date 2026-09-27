<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="min-h-screen bg-paper text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        @php
            $nav = [
                __('Prehľad') => [
                    ['route' => 'admin.index', 'label' => __('Prehľad'), 'icon' => 'chart-bar', 'match' => 'admin.index'],
                    ['route' => 'admin.households', 'label' => __('Domácnosti'), 'icon' => 'home-modern', 'match' => 'admin.households*'],
                ],
                __('Financie') => [
                    ['route' => 'admin.subscriptions', 'label' => __('Predplatné'), 'icon' => 'arrow-path', 'match' => 'admin.subscriptions'],
                    ['route' => 'admin.orders', 'label' => __('Objednávky'), 'icon' => 'shopping-bag', 'match' => 'admin.orders*'],
                    ['route' => 'admin.refunds', 'label' => __('Refundácie'), 'icon' => 'receipt-refund', 'match' => 'admin.refunds'],
                    ['route' => 'admin.usage', 'label' => __('AI použitia'), 'icon' => 'ticket', 'match' => 'admin.usage'],
                    ['route' => 'admin.catalog', 'label' => __('Katalóg'), 'icon' => 'tag', 'match' => 'admin.catalog'],
                    ['route' => 'admin.stripe-events', 'label' => __('Stripe udalosti'), 'icon' => 'bolt', 'match' => 'admin.stripe-events'],
                ],
                __('AI') => [
                    ['route' => 'admin.ai', 'label' => __('Použitie a náklady'), 'icon' => 'cpu-chip', 'match' => 'admin.ai'],
                    ['route' => 'admin.ai.settings', 'label' => __('Nastavenia'), 'icon' => 'adjustments-horizontal', 'match' => 'admin.ai.settings'],
                    ['route' => 'admin.ai.rates', 'label' => __('Cenník AI'), 'icon' => 'currency-dollar', 'match' => 'admin.ai.rates'],
                ],
                __('Výživa') => [
                    ['route' => 'admin.food', 'label' => __('Potraviny'), 'icon' => 'cake', 'match' => 'admin.food'],
                ],
                __('Právne a súkromie') => [
                    ['route' => 'admin.legal', 'label' => __('Právne dokumenty'), 'icon' => 'scale', 'match' => 'admin.legal*'],
                    ['route' => 'admin.services', 'label' => __('Služby a cookies'), 'icon' => 'squares-2x2', 'match' => 'admin.services'],
                    ['route' => 'admin.privacy', 'label' => __('Súkromie a odstúpenia'), 'icon' => 'lock-closed', 'match' => 'admin.privacy'],
                ],
                __('Dohľad') => [
                    ['route' => 'admin.audit', 'label' => __('Audit'), 'icon' => 'document-magnifying-glass', 'match' => 'admin.audit'],
                    ['route' => 'admin.launch', 'label' => __('Launch checklist'), 'icon' => 'rocket-launch', 'match' => 'admin.launch'],
                ],
            ];
        @endphp

        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200/70 bg-paper dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('admin.index') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($nav as $heading => $items)
                    <flux:sidebar.group :heading="$heading" class="mt-2">
                        @foreach ($items as $item)
                            <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['match'])" wire:navigate>
                                {{ $item['label'] }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="arrow-uturn-left" :href="route('cook.index')" wire:navigate>{{ __('Späť do aplikácie') }}</flux:sidebar.item>
            </flux:sidebar.nav>

            <div class="px-2 pb-2 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Prihlásený administrátor: :email', ['email' => auth()->user()->email]) }}
            </div>
        </flux:sidebar>

        <flux:header class="sticky top-0 z-30 h-14 items-center gap-3 border-b border-zinc-200/70 bg-paper/90 !px-4 backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-900/90">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:heading class="truncate font-display text-lg">{{ isset($title) ? __($title) : __('Administrácia') }}</flux:heading>
        </flux:header>

        <flux:main container class="max-w-7xl pb-16">
            <div class="mb-4 hidden items-center gap-2 lg:flex">
                <flux:badge color="amber" size="sm" icon="shield-check">{{ __('Administrácia platformy') }}</flux:badge>
                <flux:text class="text-xs">{{ __('Neobsahuje obsah receptov ani mená členov domácností.') }}</flux:text>
            </div>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
