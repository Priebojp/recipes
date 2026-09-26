{{-- Shared account menu: used by the desktop sidebar and the "Viac" tab of the mobile bottom navigation. --}}
<flux:menu class="min-w-56">
    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
        <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" color="auto" />
        <div class="grid flex-1 text-start text-sm leading-tight">
            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
        </div>
    </div>

    <flux:menu.separator />

    <flux:menu.group heading="Domácnosť">
        <flux:menu.item :href="route('plan.history')" icon="clock" wire:navigate>História varenia</flux:menu.item>
        <flux:menu.item :href="route('household.edit')" icon="home" wire:navigate>Nastavenia domácnosti</flux:menu.item>
    </flux:menu.group>

    <flux:menu.separator />

    <flux:menu.group heading="Vzhľad" x-data>
        <flux:menu.item icon="sun" x-on:click="$flux.appearance = 'light'">Svetlý</flux:menu.item>
        <flux:menu.item icon="moon" x-on:click="$flux.appearance = 'dark'">Tmavý</flux:menu.item>
        <flux:menu.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">Podľa systému</flux:menu.item>
    </flux:menu.group>

    <flux:menu.separator />

    <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>Účet a bezpečnosť</flux:menu.item>

    @if (auth()->user()->isPlatformAdmin())
        <flux:menu.item :href="route('admin.index')" icon="shield-check" wire:navigate data-test="admin-link">Administrácia</flux:menu.item>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
            Odhlásiť sa
        </flux:menu.item>
    </form>
</flux:menu>
