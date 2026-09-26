<?php

use App\Enums\CatalogState;
use App\Models\AddonVersion;
use App\Models\PlanVersion;
use App\Services\Billing\Catalog;
use App\Services\Billing\CatalogManager;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Versioned price list. A change is a new draft version; activating it retires the previous active one. Orders
 * keep their snapshot, entitlements their plan version – nothing sold is ever re-priced.
 */
new #[Layout('layouts::admin')] #[Title('Katalóg')] class extends Component {
    /** 'plan' | 'addon' */
    public string $draft_type = '';

    public int $draft_base = 0;

    public string $draft_name = '';

    public string $draft_price = '';

    public string $draft_stripe_price_id = '';

    public string $draft_text_uses = '';

    public string $draft_image_uses = '';

    public string $draft_unit_count = '';

    public string $draft_reason = '';

    public string $reason = '';

    #[Computed]
    public function plans(): Collection
    {
        return PlanVersion::query()->orderBy('code')->orderByDesc('version')->get()->groupBy('code');
    }

    #[Computed]
    public function addons(): Collection
    {
        return AddonVersion::query()->orderBy('code')->orderByDesc('version')->get()->groupBy('code');
    }

    public function startDraft(string $type, int $id): void
    {
        $base = $type === 'plan' ? PlanVersion::query()->findOrFail($id) : AddonVersion::query()->findOrFail($id);

        $this->draft_type = $type;
        $this->draft_base = $base->id;
        $this->draft_name = $base->name;
        $this->draft_price = number_format($base->final_price_cents / 100, 2, ',', '');
        $this->draft_stripe_price_id = (string) $base->stripe_price_id;
        $this->draft_text_uses = $type === 'plan' ? (string) $base->text_uses_per_period : '';
        $this->draft_image_uses = $type === 'plan' ? (string) $base->image_uses_per_period : '';
        $this->draft_unit_count = $type === 'addon' ? (string) $base->unit_count : '';
        $this->draft_reason = '';
        $this->resetErrorBag();
    }

    public function cancelDraft(): void
    {
        $this->draft_type = '';
        $this->draft_base = 0;
    }

    public function saveDraft(CatalogManager $manager): void
    {
        $this->authorize('platform-admin');
        $isPlan = $this->draft_type === 'plan';

        $validated = $this->validate([
            'draft_name' => ['required', 'string', 'max:100'],
            'draft_price' => ['required', 'string', 'max:20'],
            'draft_stripe_price_id' => ['nullable', 'string', 'max:100', 'regex:/^(price_[A-Za-z0-9_]+)?$/'],
            'draft_text_uses' => [$isPlan ? 'required' : 'nullable', 'integer', 'min:0', 'max:10000'],
            'draft_image_uses' => [$isPlan ? 'required' : 'nullable', 'integer', 'min:0', 'max:10000'],
            'draft_unit_count' => [$isPlan ? 'nullable' : 'required', 'integer', 'min:1', 'max:10000'],
            'draft_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $cents = Money::parseEurToCents($validated['draft_price']);
        if ($cents === null || $cents < 1) {
            $this->addError('draft_price', 'Zadaj konečnú cenu v eurách, napr. 2,49.');

            return;
        }

        if ($isPlan) {
            $base = PlanVersion::query()->findOrFail($this->draft_base);
            $version = $manager->newPlanVersion($base, [
                'name' => $validated['draft_name'],
                'final_price_cents' => $cents,
                'stripe_price_id' => $validated['draft_stripe_price_id'] ?: null,
                'text_uses_per_period' => (int) $validated['draft_text_uses'],
                'image_uses_per_period' => (int) $validated['draft_image_uses'],
            ], $validated['draft_reason'], auth()->user());
        } else {
            $base = AddonVersion::query()->findOrFail($this->draft_base);
            $version = $manager->newAddonVersion($base, [
                'name' => $validated['draft_name'],
                'final_price_cents' => $cents,
                'stripe_price_id' => $validated['draft_stripe_price_id'] ?: null,
                'unit_count' => (int) $validated['draft_unit_count'],
            ], $validated['draft_reason'], auth()->user());
        }

        $this->cancelDraft();
        unset($this->plans, $this->addons);
        Flux::toast(variant: 'success', text: "Návrh {$version->code} v{$version->version} uložený. Predáva sa až po aktivácii.");
    }

    public function activate(string $type, int $id, CatalogManager $manager): void
    {
        $this->authorize('platform-admin');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $version = $type === 'plan' ? PlanVersion::query()->findOrFail($id) : AddonVersion::query()->findOrFail($id);

        try {
            $manager->activate($version, $this->reason, auth()->user());
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reset('reason');
        unset($this->plans, $this->addons);
        Flux::toast(variant: 'success', text: "{$version->code} v{$version->version} je aktívna; predchádzajúca verzia je stiahnutá. Kúpené snímky sa nemenia.");
    }

    public function retire(string $type, int $id, CatalogManager $manager): void
    {
        $this->authorize('platform-admin');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $version = $type === 'plan' ? PlanVersion::query()->findOrFail($id) : AddonVersion::query()->findOrFail($id);

        $manager->retire($version, $this->reason, auth()->user());

        $this->reset('reason');
        unset($this->plans, $this->addons);
        Flux::toast(text: "{$version->code} v{$version->version} sa už nepredáva.");
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Katalóg" subtitle="Návrh → aktívna → stiahnutá. Zmena ceny alebo obsahu je nová verzia; zakúpené objednávky, doklady a zaplatené obdobia zostávajú na pôvodnej." />

    <flux:input wire:model="reason" label="Dôvod pre aktiváciu / stiahnutie (ide do auditu)" placeholder="napr. potvrdený cenník 10/2026" class="max-w-lg" />

    <flux:card class="space-y-3 overflow-x-auto" data-test="catalog-plans">
        <flux:heading size="lg" class="font-display">Plány</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Kód</th><th class="py-1 pe-2">Verzia</th><th class="py-1 pe-2">Názov</th><th class="py-1 pe-2 text-right">Cena</th><th class="py-1 pe-2">Použitia / obdobie</th><th class="py-1 pe-2">Stripe price</th><th class="py-1 pe-2">Stav</th><th class="py-1"></th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->plans as $code => $versions)
                    @foreach ($versions as $plan)
                        <tr wire:key="plan-{{ $plan->id }}" class="{{ $plan->state === CatalogState::Retired ? 'text-zinc-400' : '' }}">
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $loop->first ? $code : '' }}</td>
                            <td class="py-1.5 pe-2">v{{ $plan->version }}</td>
                            <td class="py-1.5 pe-2">{{ $plan->name }} <span class="text-xs text-zinc-500">({{ $plan->interval->label() }})</span></td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($plan->final_price_cents, $plan->currency) }}</td>
                            <td class="py-1.5 pe-2">{{ $plan->text_uses_per_period }} textov · {{ $plan->image_uses_per_period }} obrázkov</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $plan->stripe_price_id ?? '— (nepredajné)' }}</td>
                            <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$plan->state->badgeColor()">{{ $plan->state->label() }}</flux:badge>@if ($plan->effective_from)<div class="text-xs text-zinc-500">od {{ $plan->effective_from->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}</div>@endif</td>
                            <td class="py-1.5 whitespace-nowrap text-right">
                                @if ($loop->first)<flux:button size="xs" variant="ghost" icon="document-duplicate" wire:click="startDraft('plan', {{ $plan->id }})">Nová verzia</flux:button>@endif
                                @if ($plan->state === CatalogState::Draft)<flux:button size="xs" variant="primary" wire:click="activate('plan', {{ $plan->id }})" wire:confirm="Aktivovať túto verziu a stiahnuť predchádzajúcu aktívnu?" data-test="activate-plan-{{ $plan->id }}">Aktivovať</flux:button>@endif
                                @if ($plan->state !== CatalogState::Retired)<flux:button size="xs" variant="ghost" wire:click="retire('plan', {{ $plan->id }})" wire:confirm="Prestať predávať túto verziu?">Stiahnuť</flux:button>@endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto" data-test="catalog-addons">
        <flux:heading size="lg" class="font-display">Balíky</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Kód</th><th class="py-1 pe-2">Verzia</th><th class="py-1 pe-2">Názov</th><th class="py-1 pe-2 text-right">Cena</th><th class="py-1 pe-2">Obsah</th><th class="py-1 pe-2">Stripe price</th><th class="py-1 pe-2">Stav</th><th class="py-1"></th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->addons as $code => $versions)
                    @foreach ($versions as $addon)
                        <tr wire:key="addon-{{ $addon->id }}" class="{{ $addon->state === CatalogState::Retired ? 'text-zinc-400' : '' }}">
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $loop->first ? $code : '' }}</td>
                            <td class="py-1.5 pe-2">v{{ $addon->version }}</td>
                            <td class="py-1.5 pe-2">{{ $addon->name }}</td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($addon->final_price_cents, $addon->currency) }}</td>
                            <td class="py-1.5 pe-2">{{ $addon->unit_count }} × {{ $addon->unit_kind->label() }}</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $addon->stripe_price_id ?? '— (nepredajné)' }}</td>
                            <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$addon->state->badgeColor()">{{ $addon->state->label() }}</flux:badge></td>
                            <td class="py-1.5 whitespace-nowrap text-right">
                                @if ($loop->first)<flux:button size="xs" variant="ghost" icon="document-duplicate" wire:click="startDraft('addon', {{ $addon->id }})">Nová verzia</flux:button>@endif
                                @if ($addon->state === CatalogState::Draft)<flux:button size="xs" variant="primary" wire:click="activate('addon', {{ $addon->id }})" wire:confirm="Aktivovať túto verziu a stiahnuť predchádzajúcu aktívnu?" data-test="activate-addon-{{ $addon->id }}">Aktivovať</flux:button>@endif
                                @if ($addon->state !== CatalogState::Retired)<flux:button size="xs" variant="ghost" wire:click="retire('addon', {{ $addon->id }})" wire:confirm="Prestať predávať túto verziu?">Stiahnuť</flux:button>@endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </flux:card>

    @if ($draft_type !== '')
        <flux:card class="space-y-3" data-test="draft-form">
            <form wire:submit="saveDraft" class="space-y-3">
                <flux:heading size="lg" class="font-display">Nová verzia {{ $draft_type === 'plan' ? 'plánu' : 'balíka' }}</flux:heading>
                <flux:text class="text-xs">Vznikne návrh; predávať sa začne až po aktivácii. Stripe price ID musí byť cena s rovnakou sumou a intervalom v Stripe – aplikácia ceny v Stripe nevytvára.</flux:text>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="draft_name" label="Názov" />
                    <flux:input wire:model="draft_price" label="Konečná cena (EUR)" placeholder="2,49" />
                    <flux:input wire:model="draft_stripe_price_id" label="Stripe price ID" placeholder="price_…" />
                    @if ($draft_type === 'plan')
                        <flux:input wire:model="draft_text_uses" type="number" min="0" label="Textových operácií / obdobie" />
                        <flux:input wire:model="draft_image_uses" type="number" min="0" label="Obrázkov / obdobie" />
                    @else
                        <flux:input wire:model="draft_unit_count" type="number" min="1" label="Počet jednotiek" />
                    @endif
                </div>
                <flux:textarea wire:model="draft_reason" rows="2" label="Dôvod (ide do auditu)" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" size="sm">Uložiť návrh</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="cancelDraft">Zrušiť</flux:button>
                </div>
            </form>
        </flux:card>
    @endif
</div>
