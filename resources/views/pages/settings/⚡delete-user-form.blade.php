<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Delete account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and all of its resources') }}</flux:subheading>
    </div>

    <flux:text class="text-sm">Pred vymazaním uvidíš dopad na domácnosť, predplatné a nevyužité balíky. Vymazanie je v nastaveniach súkromia.</flux:text>
    <flux:button variant="danger" :href="route('privacy.edit')" wire:navigate data-test="delete-user-button">
        {{ __('Delete account') }}
    </flux:button>
</section>
