<?php

use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Models\Household;
use App\Models\UsageGrant;
use App\Services\Usage\UsageProvisioner;
use App\Services\Usage\UsageBalance;
use App\Services\Usage\UsageLedger;
use Illuminate\Support\Collection;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI použitia')] class extends Component {
    public function mount(UsageProvisioner $provisioner): void
    {
        $provisioner->ensureFor($this->household);
    }

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function enforced(): bool
    {
        return app(UsageLedger::class)->enforced();
    }

    /** @return array<string, UsageBalance> */
    #[Computed]
    public function balances(): array
    {
        $ledger = app(UsageLedger::class);
        $granted = UsageGrant::query()->where('household_id', $this->household->id)->distinct()->pluck('kind')->map(fn ($k) => $k instanceof UsageKind ? $k->value : (string) $k)->all();
        $result = [];
        foreach (UsageKind::cases() as $kind) {
            // Kinds the household never held (e.g. Economy images before the v2.1 offer) would only show empty cards.
            if (! in_array($kind->value, $granted, true) && ! in_array($kind, [UsageKind::Text, UsageKind::ImageStandard], true)) {
                continue;
            }
            $result[$kind->value] = $ledger->balance($this->household, $kind);
        }

        return $result;
    }

    /** Purchased and compensation grants with something left, oldest first (the order they are spent in). */
    #[Computed]
    public function purchased(): Collection
    {
        return UsageGrant::query()
            ->where('household_id', $this->household->id)
            ->whereIn('source', [UsageGrantSource::Addon, UsageGrantSource::Compensation])
            ->validAt(now())
            ->inConsumptionOrder()
            ->get()
            ->filter(fn (UsageGrant $g) => $g->available() > 0);
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout heading="AI použitia" subheading="Koľko AI operácií má tvoja domácnosť k dispozícii a odkiaľ pochádzajú.">
        <div class="my-6 space-y-6">
            @if (! $this->enforced)
                <flux:callout icon="information-circle" variant="secondary">AI použitia sa v tejto inštalácii zatiaľ neúčtujú; platia len denné limity.</flux:callout>
            @else
                @foreach ($this->balances as $balance)
                    <flux:card class="space-y-2" data-test="usage-{{ $balance->kind->value }}">
                        <flux:heading size="lg" class="font-display">{{ ucfirst($balance->kind->label()) }}</flux:heading>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            <dt class="text-zinc-500">{{ $balance->includedSourceLabel ? ucfirst($balance->includedSourceLabel) : 'Zahrnuté' }}</dt>
                            <dd>
                                @if ($balance->includedTotal > 0)
                                    {{ $balance->includedAvailable }}/{{ $balance->includedTotal }}
                                    @if ($balance->includedExpiresAt)
                                        <span class="text-zinc-500">· platí do {{ $balance->includedExpiresAt->timezone($this->household->timezone)->translatedFormat('j. n. Y') }}</span>
                                    @endif
                                @else
                                    <span class="text-zinc-500">žiadne</span>
                                @endif
                            </dd>
                            <dt class="text-zinc-500">Dokúpené</dt>
                            <dd>{{ $balance->purchasedAvailable }}</dd>
                            <dt class="font-medium">Spolu k dispozícii</dt>
                            <dd class="font-medium">{{ $balance->available() }}</dd>
                        </dl>
                    </flux:card>
                @endforeach

                @if ($this->purchased->isNotEmpty())
                    <flux:card class="space-y-2">
                        <flux:heading size="lg" class="font-display">Dokúpené balíky a kompenzácie</flux:heading>
                        <ul class="space-y-1 text-sm">
                            @foreach ($this->purchased as $grant)
                                <li wire:key="grant-{{ $grant->id }}" class="flex justify-between gap-4">
                                    <span>{{ ucfirst($grant->source->label()) }} · {{ $grant->kind->label() }}@if ($grant->note) <span class="text-zinc-500">– {{ $grant->note }}</span>@endif</span>
                                    <span class="tabular-nums">{{ $grant->available() }}/{{ $grant->effectiveQuantity() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </flux:card>
                @endif

                <flux:text class="text-sm">
                    Jedno použitie = jeden úspešne doručený návrh textu alebo jeden obrázok Standard. Technická chyba použitie vráti; neprijatie doručeného výsledku nie.
                    Najskôr sa čerpajú použitia s najbližšou expiráciou (skúšobné, mesačné), potom najstaršie dokúpené. Po vyčerpaní sa nič neúčtuje automaticky.
                </flux:text>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
