<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Skontroluj jedlo')] class extends Component {
    //
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('Skontroluj jedlo')" :subtitle="__('Odfoť jedlo, AI navrhne viditeľné zložky, ty ich skontroluješ. Kalórie počíta databáza potravín, nie AI.')" />

    <livewire:meal-photo-analyzer />
</div>
