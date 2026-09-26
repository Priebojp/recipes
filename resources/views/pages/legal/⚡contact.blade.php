<?php

use App\Services\Legal\OperatorIdentity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Kontakt a reklamácie')] class extends Component {
    #[Computed]
    public function operator(): OperatorIdentity
    {
        return app(OperatorIdentity::class);
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <flux:heading size="xl" level="1" class="font-display">Kontakt a reklamácie</flux:heading>

    @php($o = $this->operator)
    @if ($o->get('business_name') === '')
        <flux:callout icon="clock" variant="secondary" data-test="contact-preparing">
            <flux:callout.heading>Údaje prevádzkovateľa sa dopĺňajú</flux:callout.heading>
            <flux:callout.text>Identifikácia predávajúceho bude doplnená pred spustením platieb. Do vtedy fungujú iba bezplatné funkcie.</flux:callout.text>
        </flux:callout>
    @else
        <flux:card class="space-y-2" data-test="contact-identity">
            <flux:heading class="font-display">Prevádzkovateľ</flux:heading>
            <flux:text>{{ $o->identityLine() }}</flux:text>
            @if ($o->get('legal_form') !== '')<flux:text class="text-sm">Právna forma: {{ $o->get('legal_form') }}</flux:text>@endif
            @if ($o->get('phone') !== '')<flux:text class="text-sm">Telefón: {{ $o->get('phone') }}</flux:text>@endif
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <flux:heading class="font-display">Kam písať</flux:heading>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            @if ($o->get('support_email') !== '')<dt class="text-zinc-500">Podpora</dt><dd><a href="mailto:{{ $o->get('support_email') }}" class="underline">{{ $o->get('support_email') }}</a></dd>@endif
            @if ($o->get('complaints_email') !== '')<dt class="text-zinc-500">Reklamácie a odstúpenie</dt><dd><a href="mailto:{{ $o->get('complaints_email') }}" class="underline">{{ $o->get('complaints_email') }}</a></dd>@endif
            @if ($o->get('privacy_email') !== '')<dt class="text-zinc-500">Osobné údaje</dt><dd><a href="mailto:{{ $o->get('privacy_email') }}" class="underline">{{ $o->get('privacy_email') }}</a></dd>@endif
        </dl>
        <flux:text class="text-sm">Odstúpenie od zmluvy nevyžaduje e-mail ani telefonát: <a href="{{ route('legal.withdrawal.form') }}" wire:navigate class="underline">online formulár</a>. Reklamáciu vybavíme do 30 dní.</flux:text>
    </flux:card>

    @if ($o->get('ars_body') !== '')
        <flux:card class="space-y-2">
            <flux:heading class="font-display">Alternatívne riešenie sporov</flux:heading>
            <flux:text class="text-sm">{{ $o->get('ars_body') }}</flux:text>
        </flux:card>
    @endif
</div>
