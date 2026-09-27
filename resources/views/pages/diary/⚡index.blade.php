<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Denník')] class extends Component {
    //
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('Denník „Zjedol som“')" :subtitle="__('Súkromný zápis toho, čo si naozaj zjedol(a) – z receptu, z fotky alebo ručne. Uvarenie sa sem nezapisuje samo.')" />

    <livewire:meal-diary />
</div>
