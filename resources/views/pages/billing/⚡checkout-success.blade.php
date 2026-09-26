<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Return page from Stripe Checkout. It only shows the order's state – the paid result is granted by webhooks.
 */
new #[Title('Overenie platby')] class extends Component {
    #[Url(as: 'session_id')]
    public string $sessionId = '';

    #[Computed]
    public function order(): ?Order
    {
        if ($this->sessionId === '') {
            return null;
        }

        return Order::query()->where('household_id', app(CurrentHousehold::class)->id())->where('stripe_checkout_session_id', $this->sessionId)->first();
    }

    public function refresh(): void
    {
        unset($this->order);
    }
}; ?>

<div class="mx-auto max-w-xl space-y-6" @if ($this->order?->status === OrderStatus::Pending) wire:poll.3s="refresh" @endif>
    <x-page-header title="Overenie platby" :back="route('subscription.edit')" />

    @php($order = $this->order)
    @if ($order === null)
        <flux:callout icon="information-circle" variant="secondary">Platbu overujeme. Stav objednávky nájdeš v Nastavenia → Predplatné.</flux:callout>
    @elseif ($order->status === OrderStatus::Pending)
        <flux:callout icon="clock" variant="secondary" data-test="checkout-pending">
            <flux:callout.heading>Platbu overujeme</flux:callout.heading>
            <flux:callout.text>{{ $order->productName() }} · {{ App\Services\Billing\Catalog::formatCents($order->amount_cents, $order->currency) }}. Potvrdenie od platobnej brány môže trvať niekoľko sekúnd; stránka sa obnoví sama.</flux:callout.text>
        </flux:callout>
    @elseif ($order->status->isSettled())
        <flux:callout icon="check-circle" variant="success" data-test="checkout-paid">
            <flux:callout.heading>Zaplatené</flux:callout.heading>
            <flux:callout.text>{{ $order->productName() }} je aktívne. Doklad nájdeš v správe platby.</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="exclamation-triangle" variant="warning">
            <flux:callout.heading>Platba nebola dokončená</flux:callout.heading>
            <flux:callout.text>{{ $order->failure_reason ?? 'Nič sa neúčtovalo.' }} Môžeš to skúsiť znova z cenníka.</flux:callout.text>
        </flux:callout>
    @endif

    <flux:button :href="route('subscription.edit')" wire:navigate>Predplatné a doklady</flux:button>
</div>
