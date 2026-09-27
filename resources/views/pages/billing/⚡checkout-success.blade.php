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
    <x-page-header :title="__('Overenie platby')" :back="route('subscription.edit')" />

    @php($order = $this->order)
    @if ($order === null)
        <flux:callout icon="information-circle" variant="secondary">{{ __('Platbu overujeme. Stav objednávky nájdeš v Nastavenia → Predplatné.') }}</flux:callout>
    @elseif ($order->status === OrderStatus::Pending)
        <flux:callout icon="clock" variant="secondary" data-test="checkout-pending">
            <flux:callout.heading>{{ __('Platbu overujeme') }}</flux:callout.heading>
            <flux:callout.text>{{ $order->productName() }} · {{ App\Services\Billing\Catalog::formatCents($order->amount_cents, $order->currency) }}. {{ __('Potvrdenie od platobnej brány môže trvať niekoľko sekúnd; stránka sa obnoví sama.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($order->status->isSettled())
        @php($event = App\Services\Consent\AnalyticsEvents::sanitize($order->kind === App\Enums\OrderKind::Subscription ? 'subscription_started' : 'addon_purchased', ['offer' => (string) ($order->product_snapshot['code'] ?? '')]))
        @if ($event && ! session('analytics_sent_order_'.$order->id))
            @php(session(['analytics_sent_order_'.$order->id => true]))
            <script type="application/json" id="mr-analytics-event">@json($event)</script>
        @endif
        <flux:callout icon="check-circle" variant="success" data-test="checkout-paid">
            <flux:callout.heading>{{ __('Zaplatené') }}</flux:callout.heading>
            <flux:callout.text>{{ __(':product je aktívne.', ['product' => $order->productName()]) }} {{ __('Doklad nájdeš v správe platby.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="exclamation-triangle" variant="warning">
            <flux:callout.heading>{{ __('Platba nebola dokončená') }}</flux:callout.heading>
            <flux:callout.text>{{ $order->failure_reason ?? __('Nič sa neúčtovalo.') }} {{ __('Môžeš to skúsiť znova z cenníka.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:button :href="route('subscription.edit')" wire:navigate>{{ __('Predplatné a doklady') }}</flux:button>
</div>
