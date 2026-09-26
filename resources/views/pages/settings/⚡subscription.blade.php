<?php

use App\Enums\OrderStatus;
use App\Models\Household;
use App\Models\Order;
use App\Services\Billing\Catalog;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Billing\PlanStatus;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Predplatné')] class extends Component {
    public string $notice = '';

    public function mount(): void
    {
        $this->notice = (string) session('status', '');
    }

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return auth()->user()->can('manage', $this->household);
    }

    /** @return array{is_plus: bool, grace: bool, current: ?\App\Models\PaidEntitlement, paid_through: ?\Carbon\CarbonInterface, subscription: ?\Laravel\Cashier\Subscription} */
    #[Computed]
    public function status(): array
    {
        $plans = app(PlanStatus::class);
        $household = $this->household;

        return [
            'is_plus' => $plans->isPlus($household),
            'grace' => $plans->inRenewalGrace($household),
            'current' => $plans->current($household)?->load('planVersion'),
            'paid_through' => $plans->paidThrough($household),
            'subscription' => $plans->subscription($household),
        ];
    }

    #[Computed]
    public function orders(): Collection
    {
        return Order::query()->where('household_id', $this->household->id)->whereNot('status', OrderStatus::Canceled)->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function addons(): Collection
    {
        return app(Catalog::class)->addons();
    }

    #[Computed]
    public function paymentsReady(): bool
    {
        return app(\App\Services\Billing\CheckoutService::class)->isReady();
    }

    #[Computed]
    public function plans(): Collection
    {
        return app(Catalog::class)->plans();
    }

    public function cancelRenewal(StripeGateway $gateway): void
    {
        $this->authorize('manage', $this->household);
        $subscription = $this->status['subscription'];
        if ($subscription === null || $subscription->canceled()) {
            return;
        }

        $gateway->cancelRenewal($subscription);
        $this->notice = 'Obnovovanie je zrušené. Plus platí do konca zaplateného obdobia; nič ďalšie sa neúčtuje.';
        unset($this->status);
    }

    public function resumeRenewal(StripeGateway $gateway): void
    {
        $this->authorize('manage', $this->household);
        $subscription = $this->status['subscription'];
        if ($subscription === null || ! $subscription->onGracePeriod()) {
            return;
        }

        $gateway->resumeRenewal($subscription);
        $this->notice = 'Obnovovanie je znova zapnuté.';
        unset($this->status);
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout heading="Predplatné" subheading="Plán domácnosti, zaplatené obdobie, doklady a doplnkové balíky.">
        <div class="my-6 space-y-6">
            @if ($notice)
                <flux:callout icon="information-circle" variant="secondary">{{ $notice }}</flux:callout>
            @endif

            @php($status = $this->status)
            @php($tz = $this->household->timezone)
            <flux:card class="space-y-3" data-test="plan-status">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg" class="font-display">{{ $status['is_plus'] ? 'Plus' : 'Free' }}</flux:heading>
                    @if ($status['grace'])
                        <flux:badge color="amber" size="sm">obnova zlyhala</flux:badge>
                    @elseif ($status['subscription']?->onGracePeriod())
                        <flux:badge color="zinc" size="sm">obnovovanie zrušené</flux:badge>
                    @elseif ($status['is_plus'])
                        <flux:badge color="green" size="sm">aktívne</flux:badge>
                    @endif
                </div>

                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    @if ($status['current'])
                        <dt class="text-zinc-500">Plán</dt>
                        <dd>{{ $status['current']->planVersion->name }} ({{ $status['current']->planVersion->interval->label() }}, {{ Catalog::formatCents($status['current']->planVersion->final_price_cents, $status['current']->planVersion->currency) }})</dd>
                    @endif
                    @if ($status['paid_through'])
                        <dt class="text-zinc-500">Zaplatené do</dt>
                        <dd>{{ $status['paid_through']->timezone($tz)->translatedFormat('j. n. Y H:i') }}</dd>
                    @endif
                    @if ($status['subscription'] && ! $status['subscription']->canceled() && $status['paid_through'] && $status['current'])
                        <dt class="text-zinc-500">Ďalšia platba</dt>
                        <dd>{{ Catalog::formatCents($status['current']->planVersion->final_price_cents, $status['current']->planVersion->currency) }} · {{ $status['paid_through']->timezone($tz)->translatedFormat('j. n. Y') }} (automatické obnovenie)</dd>
                    @endif
                    @if ($status['grace'])
                        <dt class="text-zinc-500">Stav</dt>
                        <dd>Obnova platby zlyhala. Plus funkcie zostávajú niekoľko dní, nové mesačné AI použitia sa nepridávajú. Aktualizuj platobnú metódu v správe platby.</dd>
                    @endif
                </dl>

                @if ($this->isOwner)
                    <div class="flex flex-wrap gap-2">
                        @if ($status['subscription'] && ! $status['subscription']->ended())
                            @if ($status['subscription']->onGracePeriod())
                                <flux:button size="sm" wire:click="resumeRenewal" data-test="resume-renewal">Obnovovať znova</flux:button>
                            @elseif (! $status['subscription']->canceled())
                                <flux:button size="sm" variant="ghost" wire:click="cancelRenewal" wire:confirm="Zrušiť automatické obnovovanie? Plus zostane aktívne do konca zaplateného obdobia." data-test="cancel-renewal">Zrušiť obnovovanie</flux:button>
                            @endif
                        @endif
                        @if ($this->household->billingAccount?->stripe_id)
                            <flux:button size="sm" :href="route('billing.portal')" icon="credit-card" data-test="billing-portal">Správa platby a doklady</flux:button>
                        @endif
                        @unless ($status['subscription'] && ! $status['subscription']->ended())
                            <flux:button size="sm" variant="primary" :href="route('pricing')" wire:navigate>Získať Plus</flux:button>
                        @endunless
                    </div>
                @else
                    <flux:text class="text-sm">Predplatné a platby spravuje vlastník domácnosti.</flux:text>
                @endif
            </flux:card>

            @if ($this->isOwner && $this->addons->isNotEmpty())
                <flux:card class="space-y-3">
                    <flux:heading size="lg" class="font-display">Doplnkové balíky</flux:heading>
                    <flux:text class="text-sm">Jednorazovo, bez obnovovania a bez expirácie počas prevádzky služby. Nezakladajú Plus.</flux:text>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($this->addons as $addon)
                            <div wire:key="addon-{{ $addon->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <div class="font-medium">{{ $addon->name }}</div>
                                <div class="text-sm text-zinc-500">{{ $addon->unit_count }} × {{ $addon->unit_kind->label() }} · {{ Catalog::formatCents($addon->final_price_cents, $addon->currency) }}</div>
                                <flux:button :href="route('checkout.review', ['addon' => $addon->code])" wire:navigate size="sm" class="mt-2" :disabled="! $addon->stripe_price_id || ! $this->paymentsReady" data-test="buy-addon-{{ $addon->code }}">Pokračovať k objednávke</flux:button>
                            </div>
                        @endforeach
                    </div>
                    @error('addon') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                </flux:card>
            @endif

            @if ($this->orders->isNotEmpty())
                <flux:card class="space-y-2">
                    <flux:heading size="lg" class="font-display">Objednávky</flux:heading>
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($this->orders as $order)
                                <tr wire:key="order-{{ $order->id }}" class="border-t border-zinc-200 dark:border-zinc-700">
                                    <td class="py-1.5 pe-2">{{ $order->created_at->timezone($tz)->translatedFormat('j. n. Y') }}</td>
                                    <td class="py-1.5 pe-2">{{ $order->productName() }}</td>
                                    <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($order->amount_cents, $order->currency) }}</td>
                                    <td class="py-1.5 text-right"><flux:badge size="sm" :color="match ($order->status->value) { 'paid' => 'green', 'pending' => 'amber', 'refunded', 'partially_refunded' => 'blue', default => 'zinc' }">{{ $order->status->label() }}</flux:badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <flux:text class="text-xs">Odstúpenie od zmluvy (do {{ config('recipes.legal.withdrawal_days') }} dní od nákupu) je iná vec než zrušenie obnovovania: <a href="{{ route('legal.withdrawal.form') }}" wire:navigate class="underline" data-test="withdrawal-link">online formulár odstúpenia</a>. Reklamácie: <a href="{{ route('legal.show', ['slug' => 'odstupenie-od-zmluvy']) }}" wire:navigate class="underline">podmienky a kontakt</a>.</flux:text>
                </flux:card>
            @endif

            <flux:text class="text-sm"><a href="{{ route('usage.index') }}" wire:navigate class="underline">Zostatok AI použití</a> · <a href="{{ route('pricing') }}" wire:navigate class="underline">Cenník</a></flux:text>
        </div>
    </x-pages::settings.layout>
</section>
