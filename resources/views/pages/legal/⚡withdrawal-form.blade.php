<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WithdrawalRequest;
use App\Services\Privacy\WithdrawalService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Online withdrawal (specification chapter 9): identify the order → confirm the intent → immediate receipt by e-mail.
 * Works without an account; a logged-in owner picks from their paid orders.
 */
new #[Layout('layouts::public')] #[Title('Odstúpenie od zmluvy – formulár')] class extends Component {
    public string $email = '';

    public string $order_reference = '';

    public string $message = '';

    public bool $confirm = false;

    public ?string $submittedReference = null;

    public function mount(): void
    {
        $this->email = (string) (auth()->user()?->email ?? '');
    }

    /** @return Collection<int, Order> paid orders of the logged-in user's households */
    #[Computed]
    public function orders(): Collection
    {
        $user = auth()->user();
        if ($user === null) {
            return collect();
        }

        return Order::query()
            ->whereIn('household_id', $user->households()->pluck('households.id'))
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function submit(WithdrawalService $withdrawals): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:200'],
            'order_reference' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Potvrď, že chceš odstúpiť od zmluvy.']);

        $request = $withdrawals->submit($this->email, $this->order_reference ?: null, $this->message ?: null, auth()->user());
        $this->submittedReference = $request->reference;
        $this->reset('order_reference', 'message', 'confirm');
    }
}; ?>

<div class="mx-auto max-w-2xl space-y-6">
    <flux:heading size="xl" level="1" class="font-display">Odstúpenie od zmluvy</flux:heading>
    <flux:text>Týmto formulárom odstúpiš od zmluvy o platenom programe alebo balíku. Je to iná vec než <strong>zrušenie obnovovania</strong> predplatného, ktoré urobíš v Nastavenia → Predplatné bez odstúpenia. Formulár funguje aj bez prihlásenia – stačí e-mail, ktorým si platil(a), a číslo objednávky.</flux:text>

    @if ($submittedReference)
        <flux:callout icon="check-circle" variant="success" data-test="withdrawal-received">
            <flux:callout.heading>Odstúpenie prijaté pod číslom {{ $submittedReference }}</flux:callout.heading>
            <flux:callout.text>Potvrdenie prijatia sme odoslali na {{ $email }}. O výsledku a refundácii ťa budeme informovať e-mailom.</flux:callout.text>
        </flux:callout>
    @else
        <flux:card>
            <form wire:submit="submit" class="space-y-4">
                <flux:input wire:model="email" type="email" label="E-mail použitý pri nákupe" required data-test="withdrawal-email" />

                @if ($this->orders->isNotEmpty())
                    <flux:radio.group wire:model="order_reference" label="Objednávka" data-test="withdrawal-orders">
                        @foreach ($this->orders as $order)
                            <flux:radio :value="(string) $order->id" :label="'#'.$order->id.' · '.$order->productName().' · '.App\Services\Billing\Catalog::formatCents($order->amount_cents, $order->currency).' · '.$order->created_at->format('j. n. Y')" />
                        @endforeach
                    </flux:radio.group>
                @else
                    <flux:input wire:model="order_reference" label="Číslo objednávky" description="Z potvrdzovacieho e-mailu alebo z Nastavenia → Predplatné (napr. #12). Ak ho nemáš, opíš nákup v správe." data-test="withdrawal-order" />
                @endif

                <flux:textarea wire:model="message" label="Správa (nepovinné)" rows="3" />

                <flux:checkbox wire:model="confirm" label="Potvrdzujem, že odstupujem od zmluvy uzavretej so službou {{ config('app.name') }}." data-test="withdrawal-confirm" />

                <flux:button type="submit" variant="primary" data-test="withdrawal-submit">Odoslať odstúpenie</flux:button>
            </form>
        </flux:card>
        <flux:text class="text-xs">Po odoslaní dostaneš ihneď potvrdenie prijatia na trvalom médiu (e-mail). Refundácia prebieha rovnakým spôsobom, akým si platil(a), spravidla do 14 dní. Podrobnosti: <a href="{{ route('legal.show', ['slug' => 'odstupenie-od-zmluvy']) }}" wire:navigate class="underline">Odstúpenie od zmluvy a reklamácie</a>.</flux:text>
    @endif
</div>
