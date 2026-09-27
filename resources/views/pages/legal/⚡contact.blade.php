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
    <flux:heading size="xl" level="1" class="font-display">{{ __('Kontakt a reklamácie') }}</flux:heading>

    @php($o = $this->operator)
    @if ($o->get('business_name') === '')
        <flux:callout icon="clock" variant="secondary" data-test="contact-preparing">
            <flux:callout.heading>{{ __('Údaje prevádzkovateľa sa dopĺňajú') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Identifikácia predávajúceho bude doplnená pred spustením platieb. Do vtedy fungujú iba bezplatné funkcie.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:card class="space-y-2" data-test="contact-identity">
            <flux:heading class="font-display">{{ __('Prevádzkovateľ') }}</flux:heading>
            <flux:text>{{ $o->identityLine() }}</flux:text>
            @if ($o->get('legal_form') !== '')<flux:text class="text-sm">{{ __('Právna forma: :form', ['form' => $o->get('legal_form')]) }}</flux:text>@endif
            @if ($o->get('phone') !== '')<flux:text class="text-sm">{{ __('Telefón: :phone', ['phone' => $o->get('phone')]) }}</flux:text>@endif
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <flux:heading class="font-display">{{ __('Kam písať') }}</flux:heading>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            @if ($o->get('support_email') !== '')<dt class="text-zinc-500">{{ __('Podpora') }}</dt><dd><a href="mailto:{{ $o->get('support_email') }}" class="underline">{{ $o->get('support_email') }}</a></dd>@endif
            @if ($o->get('complaints_email') !== '')<dt class="text-zinc-500">{{ __('Reklamácie a odstúpenie') }}</dt><dd><a href="mailto:{{ $o->get('complaints_email') }}" class="underline">{{ $o->get('complaints_email') }}</a></dd>@endif
            @if ($o->get('privacy_email') !== '')<dt class="text-zinc-500">{{ __('Osobné údaje') }}</dt><dd><a href="mailto:{{ $o->get('privacy_email') }}" class="underline">{{ $o->get('privacy_email') }}</a></dd>@endif
        </dl>
        <flux:text class="text-sm">{!! __('Odstúpenie od zmluvy nevyžaduje e-mail ani telefonát: :link.', ['link' => '<a href="'.route('legal.withdrawal.form').'" wire:navigate class="underline">'.__('online formulár').'</a>']) !!} {{ __('Reklamáciu vybavíme do 30 dní.') }}</flux:text>
    </flux:card>

    @if ($o->get('ars_body') !== '')
        <flux:card class="space-y-2">
            <flux:heading class="font-display">{{ __('Alternatívne riešenie sporov') }}</flux:heading>
            <flux:text class="text-sm">{{ $o->get('ars_body') }}</flux:text>
        </flux:card>
    @endif
</div>
