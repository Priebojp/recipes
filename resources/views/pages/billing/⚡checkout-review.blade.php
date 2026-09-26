<?php

use App\Enums\LegalDocumentType;
use App\Enums\PlanInterval;
use App\Models\AddonVersion;
use App\Models\LegalDocumentVersion;
use App\Models\PlanVersion;
use App\Services\Billing\Catalog;
use App\Services\Billing\CheckoutService;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pre-payment summary (specification chapter 9): seller identity, contents, final amount, interval, automatic
 * renewal, how to cancel, withdrawal, the exact terms version – and a button that states the obligation to pay.
 */
new #[Title('Zhrnutie objednávky')] class extends Component {
    #[Url]
    public string $plan = '';

    #[Url]
    public string $addon = '';

    public function mount(): void
    {
        if ($this->offer === null) {
            $this->redirectRoute('pricing', navigate: true);
        }
    }

    #[Computed]
    public function offer(): PlanVersion|AddonVersion|null
    {
        $catalog = app(Catalog::class);

        return $this->plan !== '' ? $catalog->plan($this->plan) : ($this->addon !== '' ? $catalog->addon($this->addon) : null);
    }

    #[Computed]
    public function isOwner(): bool
    {
        $current = app(CurrentHousehold::class);

        return $current->has() && auth()->user()->can('manage', $current->get());
    }

    #[Computed]
    public function ready(): bool
    {
        return app(CheckoutService::class)->isReady();
    }

    #[Computed]
    public function terms(): ?LegalDocumentVersion
    {
        return app(LegalDocuments::class)->current(LegalDocumentType::Terms);
    }

    #[Computed]
    public function operator(): OperatorIdentity
    {
        return app(OperatorIdentity::class);
    }
}; ?>

<div class="mx-auto max-w-2xl space-y-6">
    <x-page-header title="Zhrnutie objednávky" :back="route('pricing')" />

    @php($offer = $this->offer)
    @if ($offer === null)
        <flux:callout icon="information-circle" variant="secondary">Ponuka nie je dostupná.</flux:callout>
    @else
        @php($isPlan = $offer instanceof PlanVersion)
        @if (! $this->ready)
            <flux:callout icon="exclamation-triangle" variant="warning" data-test="checkout-not-ready">
                <flux:callout.heading>Platby ešte nie sú zapnuté</flux:callout.heading>
                <flux:callout.text>Chýba identifikácia prevádzkovateľa alebo publikované obchodné podmienky. Bezplatné funkcie fungujú ďalej.</flux:callout.text>
            </flux:callout>
        @elseif (! $this->isOwner)
            <flux:callout icon="information-circle" variant="secondary">Objednávku môže odoslať iba vlastník domácnosti.</flux:callout>
        @endif

        <flux:card class="space-y-3" data-test="checkout-summary">
            <flux:heading size="lg" class="font-display">{{ $offer->name }}</flux:heading>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-zinc-500">Obsah</dt>
                <dd>
                    @if ($isPlan)
                        {{ $offer->text_uses_per_period }} textových AI použití a {{ $offer->image_uses_per_period }} obrázkov Standard za mesačné obdobie{{ $offer->interval === PlanInterval::Year ? ' (pri ročnej platbe dopĺňané mesačne)' : '' }}; Plus funkcie pre jednu domácnosť.
                    @else
                        {{ $offer->unit_count }} × {{ $offer->unit_kind->label() }}, jednorazovo; použiteľné aj bez Plus, bez expirácie počas prevádzky služby.
                    @endif
                </dd>
                <dt class="text-zinc-500">Konečná cena</dt>
                <dd class="font-semibold">{{ Catalog::formatCents($offer->final_price_cents, $offer->currency) }}@if ($isPlan) {{ $offer->interval->label() }}@endif</dd>
                @if ($isPlan)
                    <dt class="text-zinc-500">Obnovovanie</dt>
                    <dd>Automaticky {{ $offer->interval->label() }} rovnakou sumou, kým obnovovanie nevypneš v Nastavenia → Predplatné (bez kontaktu s podporou). Plus zostane do konca zaplateného obdobia.@if ($offer->interval === PlanInterval::Year) {{ Catalog::formatCents($offer->final_price_cents, $offer->currency) }} sa účtuje raz ročne; zodpovedá {{ Catalog::formatCents($offer->monthlyEquivalentCents(), $offer->currency) }} mesačne.@endif</dd>
                @else
                    <dt class="text-zinc-500">Obnovovanie</dt>
                    <dd>Žiadne – jednorazová platba.</dd>
                @endif
                <dt class="text-zinc-500">Odstúpenie</dt>
                <dd>Do {{ config('recipes.legal.withdrawal_days') }} dní <a href="{{ route('legal.show', ['slug' => 'odstupenie-od-zmluvy']) }}" wire:navigate class="underline">online formulárom</a>, aj bez prihlásenia.</dd>
                <dt class="text-zinc-500">Predávajúci</dt>
                <dd>{{ $this->operator->identityLine() ?: '–' }}@if ($this->operator->get('support_email') !== ''), {{ $this->operator->get('support_email') }}@endif</dd>
                <dt class="text-zinc-500">Platba</dt>
                <dd>Kartou cez Stripe Checkout; doklad v správe platby.</dd>
            </dl>
        </flux:card>

        @if ($this->ready && $this->isOwner)
            <flux:card>
                <form method="POST" action="{{ $isPlan ? route('checkout.plan') : route('checkout.addon') }}" class="space-y-4" data-test="checkout-form">
                    @csrf
                    <input type="hidden" name="{{ $isPlan ? 'plan' : 'addon' }}" value="{{ $offer->code }}">
                    @if ($this->terms)
                        <input type="hidden" name="terms_version" value="{{ $this->terms->version }}">
                        <flux:field variant="inline">
                            <flux:checkbox name="terms" value="1" data-test="checkout-terms" />
                            <flux:label>Prijímam <a href="{{ route('legal.show', ['slug' => 'vop', 'version' => $this->terms->version]) }}" target="_blank" rel="noopener" class="underline">obchodné podmienky, verzia {{ $this->terms->version }}</a> (účinné od {{ $this->terms->effective_at?->format('j. n. Y') }}). Ich znenie dostanem e-mailom spolu s potvrdením objednávky.</flux:label>
                        </flux:field>
                        @error('terms') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                        @error('terms_version') <flux:text class="text-sm text-red-600">Podmienky sa medzitým zmenili – načítaj stránku znova.</flux:text> @enderror
                    @endif
                    <flux:field variant="inline">
                        <flux:checkbox name="early_performance" value="1" data-test="checkout-early-performance" />
                        <flux:label>Žiadam, aby sa služba začala poskytovať ihneď, pred uplynutím lehoty na odstúpenie. Beriem na vedomie, že aj tak môžem odstúpiť podľa podmienok odstúpenia. (Samostatná voľba, nezávislá od marketingu a cookies.)</flux:label>
                    </flux:field>
                    @error('plan') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                    @error('addon') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                    <flux:button type="submit" variant="primary" data-test="checkout-submit">Objednať s povinnosťou platby</flux:button>
                </form>
            </flux:card>
        @endif
    @endif
</div>
