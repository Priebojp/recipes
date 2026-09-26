<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('subscription.edit')" wire:navigate>Predplatné</flux:navlist.item>
            <flux:navlist.item :href="route('usage.index')" wire:navigate>AI použitia</flux:navlist.item>
            <flux:navlist.item :href="route('privacy.edit')" wire:navigate>Súkromie</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        @if (session('admin_two_factor_required'))
            <flux:callout variant="warning" icon="shield-exclamation" class="mb-4">
                <flux:callout.heading>Administrácia vyžaduje dvojfaktorové overenie</flux:callout.heading>
                <flux:callout.text>Zapni a potvrď dvojfaktorové overenie nižšie; potom sa /admin otvorí.</flux:callout.text>
            </flux:callout>
        @endif

        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
