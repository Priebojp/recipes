{{-- Shared account menu: used by the avatar in the desktop header and the "Viac" tab of the mobile bottom navigation. --}}
<flux:menu class="min-w-56">
    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
        <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" color="auto" />
        <div class="grid flex-1 text-start text-sm leading-tight">
            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
        </div>
    </div>

    <flux:menu.separator />

    <flux:menu.group :heading="__('Domácnosť')">
        <flux:menu.item :href="route('plan.history')" icon="clock" wire:navigate>{{ __('História varenia') }}</flux:menu.item>
        <flux:menu.item :href="route('diary.index')" icon="clipboard-document-list" wire:navigate data-test="diary-link">{{ __('Denník „Zjedol som“') }}</flux:menu.item>
        <flux:menu.item :href="route('household.edit')" icon="home" wire:navigate>{{ __('Nastavenia domácnosti') }}</flux:menu.item>
        <flux:menu.item :href="route('subscription.edit')" icon="credit-card" wire:navigate>{{ __('Predplatné a AI použitia') }}</flux:menu.item>
        <flux:menu.item :href="route('home')" icon="globe-alt" wire:navigate>{{ __('Verejné recepty') }}</flux:menu.item>
    </flux:menu.group>

    <flux:menu.separator />

    <flux:menu.group :heading="__('Vzhľad')" x-data>
        <flux:menu.item icon="sun" x-on:click="$flux.appearance = 'light'">{{ __('Svetlý') }}</flux:menu.item>
        <flux:menu.item icon="moon" x-on:click="$flux.appearance = 'dark'">{{ __('Tmavý') }}</flux:menu.item>
        <flux:menu.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">{{ __('Podľa systému') }}</flux:menu.item>
    </flux:menu.group>

    <flux:menu.separator />

    <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>{{ __('Účet a bezpečnosť') }}</flux:menu.item>
    <flux:menu.item :href="route('privacy.edit')" icon="lock-closed" wire:navigate>{{ __('Súkromie a podmienky') }}</flux:menu.item>

    @if (auth()->user()->isPlatformAdmin())
        <flux:menu.item :href="route('admin.index')" icon="shield-check" wire:navigate data-test="admin-link">{{ __('Administrácia') }}</flux:menu.item>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
            {{ __('Odhlásiť sa') }}
        </flux:menu.item>
    </form>
</flux:menu>
