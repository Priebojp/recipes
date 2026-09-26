<?php

use App\Enums\PlanInterval;
use App\Services\Billing\Catalog;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Cenník')] class extends Component {
    #[Url]
    public string $interval = 'month';

    public function updatedInterval(): void
    {
        if (! in_array($this->interval, ['month', 'year'], true)) {
            $this->interval = 'month';
        }
    }

    #[Computed]
    public function plans(): Collection
    {
        return app(Catalog::class)->plans();
    }

    #[Computed]
    public function addons(): Collection
    {
        return app(Catalog::class)->addons();
    }

    #[Computed]
    public function canBuy(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        $current = app(CurrentHousehold::class);

        return $current->has() && $user->can('manage', $current->get());
    }
}; ?>

<div class="space-y-10">
    <div class="space-y-2 text-center">
        <flux:heading size="xl" level="1" class="font-display">Cenník</flux:heading>
        <flux:text>Cena je za domácnosť, nie za člena. Recepty, fotografie a história zostávajú dostupné aj bez predplatného.</flux:text>
    </div>

    <div class="flex justify-center">
        <flux:radio.group wire:model.live="interval" variant="segmented" size="sm" data-test="pricing-interval">
            <flux:radio value="month" label="Mesačne" />
            <flux:radio value="year" label="Ročne" />
        </flux:radio.group>
    </div>

    @php($plan = $this->plans->first(fn ($p) => $p->interval->value === $interval))

    <div class="grid gap-6 md:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="lg" class="font-display">Free</flux:heading>
            <div class="text-3xl font-semibold">0 €</div>
            <ul class="space-y-1 text-sm">
                <li>Vlastné recepty a fotografie</li>
                <li>Rodinné profily, chute, náhodný výber</li>
                <li>Ručný plán a história</li>
                <li>Export vlastných dát</li>
                <li>3 skúšobné AI textové operácie a 1 obrázok</li>
            </ul>
        </flux:card>

        <flux:card class="space-y-4 border-accent" data-test="pricing-plus">
            <flux:heading size="lg" class="font-display">Plus</flux:heading>
            @if ($plan)
                <div>
                    <div class="text-3xl font-semibold">{{ Catalog::formatCents($plan->final_price_cents, $plan->currency) }} <span class="text-base font-normal text-zinc-500">{{ $plan->interval->label() }}</span></div>
                    @if ($plan->interval === PlanInterval::Year)
                        <flux:text class="text-sm">{{ Catalog::formatCents($plan->final_price_cents, $plan->currency) }} účtovaných raz ročne; zodpovedá {{ Catalog::formatCents($plan->monthlyEquivalentCents(), $plan->currency) }} mesačne.</flux:text>
                    @endif
                    <flux:text class="text-sm">Predplatné sa automaticky obnovuje {{ $plan->interval->label() }}; obnovovanie zrušíš kedykoľvek v nastaveniach bez kontaktu s podporou. Cena je konečná pre spotrebiteľa.</flux:text>
                </div>
                <ul class="space-y-1 text-sm">
                    <li>Všetko z Free</li>
                    <li>{{ $plan->text_uses_per_period }} AI textových operácií za mesačné obdobie</li>
                    <li>{{ $plan->image_uses_per_period }} AI obrázkov Standard za mesačné obdobie</li>
                    @foreach ($plan->features ?? [] as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
                @if ($this->canBuy)
                    <form method="POST" action="{{ route('checkout.plan') }}">
                        @csrf
                        <input type="hidden" name="plan" value="{{ $plan->code }}">
                        <flux:button type="submit" variant="primary" :disabled="! $plan->stripe_price_id" data-test="buy-plus">Objednať s povinnosťou platby</flux:button>
                        @unless ($plan->stripe_price_id)
                            <flux:text class="mt-2 text-xs">Platby ešte nie sú zapnuté.</flux:text>
                        @endunless
                    </form>
                @elseif (auth()->check())
                    <flux:text class="text-sm">Predplatné môže objednať iba vlastník domácnosti.</flux:text>
                @else
                    <flux:button :href="route('login')" wire:navigate variant="primary">Prihlás sa a objednaj</flux:button>
                @endif
            @else
                <flux:text>Ponuka sa pripravuje.</flux:text>
            @endif
        </flux:card>
    </div>

    @if ($this->addons->isNotEmpty())
        <div class="space-y-3">
            <flux:heading size="lg" class="font-display">Doplnkové balíky – jednorazovo, bez obnovovania</flux:heading>
            <flux:text class="text-sm">Kúpa balíka nezakladá Plus. Zakúpené použitia možno čerpať aj bez predplatného; nič sa nedokupuje automaticky.</flux:text>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($this->addons as $addon)
                    <flux:card wire:key="addon-{{ $addon->id }}" class="space-y-2">
                        <flux:heading class="font-display">{{ $addon->name }}</flux:heading>
                        <div class="text-xl font-semibold">{{ Catalog::formatCents($addon->final_price_cents, $addon->currency) }}</div>
                        <flux:text class="text-sm">{{ $addon->unit_count }} × {{ $addon->unit_kind->label() }}</flux:text>
                        @if ($this->canBuy)
                            <form method="POST" action="{{ route('checkout.addon') }}">
                                @csrf
                                <input type="hidden" name="addon" value="{{ $addon->code }}">
                                <flux:button type="submit" size="sm" :disabled="! $addon->stripe_price_id">Kúpiť s povinnosťou platby</flux:button>
                            </form>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        </div>
    @endif
</div>
