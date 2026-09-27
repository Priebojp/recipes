<?php

use App\Enums\CatalogState;
use App\Enums\UsageKind;
use App\Models\AddonVersion;
use App\Models\PlanVersion;
use App\Services\Billing\Catalog;
use App\Services\Ai\ImageProfile;
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

    public string $draft_image_profile = '';

    public string $draft_meal_analyses = '';

    public string $draft_unit_count = '';

    public string $draft_reason = '';

    public string $reason = '';

    /** "Nový balík" (v2.1 stage 13): a pack under a new code, e.g. photo analyses or Economy images. */
    public bool $creating = false;

    public string $new_code = '';

    public string $new_name = '';

    public string $new_kind = '';

    public string $new_unit_count = '';

    public string $new_price = '';

    public string $new_stripe_price_id = '';

    public string $new_reason = '';

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
        $this->draft_image_profile = $type === 'plan' ? $base->imageProfile()->value : '';
        $this->draft_meal_analyses = $type === 'plan' ? (string) $base->meal_analysis_uses_per_period : '';
        $this->draft_unit_count = $type === 'addon' ? (string) $base->unit_count : '';
        $this->draft_reason = '';
        $this->resetErrorBag();
    }

    public function cancelDraft(): void
    {
        $this->draft_type = '';
        $this->draft_base = 0;
    }

    public function startCreate(): void
    {
        $this->reset('new_code', 'new_name', 'new_kind', 'new_unit_count', 'new_price', 'new_stripe_price_id', 'new_reason');
        $this->resetErrorBag();
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
    }

    public function createAddon(CatalogManager $manager): void
    {
        $this->authorize('platform-admin');
        $validated = $this->validate([
            'new_code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]{2,49}$/'],
            'new_name' => ['required', 'string', 'max:100'],
            'new_kind' => ['required', 'in:'.implode(',', array_map(fn (UsageKind $k) => $k->value, UsageKind::cases()))],
            'new_unit_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'new_price' => ['required', 'string', 'max:20'],
            'new_stripe_price_id' => ['nullable', 'string', 'max:100', 'regex:/^(price_[A-Za-z0-9_]+)?$/'],
            'new_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $cents = Money::parseEurToCents($validated['new_price']);
        if ($cents === null || $cents < 1) {
            $this->addError('new_price', __('Zadaj konečnú cenu v eurách, napr. 1,99.'));

            return;
        }

        try {
            $version = $manager->createAddon($validated['new_code'], $validated['new_name'], UsageKind::from($validated['new_kind']), (int) $validated['new_unit_count'], $cents, $validated['new_stripe_price_id'] ?: null, $validated['new_reason'], auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('new_code', $e->getMessage());

            return;
        }

        $this->creating = false;
        unset($this->addons);
        Flux::toast(variant: 'success', text: __('Balík :code v1 je založený ako návrh. Predáva sa až po aktivácii.', ['code' => $version->code]));
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
            'draft_image_profile' => [$isPlan ? 'required' : 'nullable', 'in:'.implode(',', array_map(fn (ImageProfile $p) => $p->value, ImageProfile::selectable()))],
            'draft_meal_analyses' => [$isPlan ? 'required' : 'nullable', 'integer', 'min:0', 'max:10000'],
            'draft_unit_count' => [$isPlan ? 'nullable' : 'required', 'integer', 'min:1', 'max:10000'],
            'draft_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $cents = Money::parseEurToCents($validated['draft_price']);
        if ($cents === null || $cents < 1) {
            $this->addError('draft_price', __('Zadaj konečnú cenu v eurách, napr. 2,49.'));

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
                'image_profile_code' => $validated['draft_image_profile'],
                'meal_analysis_uses_per_period' => (int) $validated['draft_meal_analyses'],
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
        Flux::toast(variant: 'success', text: __('Návrh :code v:version uložený. Predáva sa až po aktivácii.', ['code' => $version->code, 'version' => $version->version]));
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
        Flux::toast(variant: 'success', text: __(':code v:version je aktívna; predchádzajúca verzia je stiahnutá. Kúpené snímky sa nemenia.', ['code' => $version->code, 'version' => $version->version]));
    }

    public function retire(string $type, int $id, CatalogManager $manager): void
    {
        $this->authorize('platform-admin');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $version = $type === 'plan' ? PlanVersion::query()->findOrFail($id) : AddonVersion::query()->findOrFail($id);

        $manager->retire($version, $this->reason, auth()->user());

        $this->reset('reason');
        unset($this->plans, $this->addons);
        Flux::toast(text: __(':code v:version sa už nepredáva.', ['code' => $version->code, 'version' => $version->version]));
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('Katalóg')" :subtitle="__('Návrh → aktívna → stiahnutá. Zmena ceny alebo obsahu je nová verzia; zakúpené objednávky, doklady a zaplatené obdobia zostávajú na pôvodnej.')" />

    <flux:input wire:model="reason" :label="__('Dôvod pre aktiváciu / stiahnutie (ide do auditu)')" :placeholder="__('napr. potvrdený cenník 10/2026')" class="max-w-lg" />

    <flux:card class="space-y-3 overflow-x-auto" data-test="catalog-plans">
        <flux:heading size="lg" class="font-display">{{ __('Plány') }}</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">{{ __('Kód') }}</th><th class="py-1 pe-2">{{ __('Verzia') }}</th><th class="py-1 pe-2">{{ __('Názov') }}</th><th class="py-1 pe-2 text-right">{{ __('Cena') }}</th><th class="py-1 pe-2">{{ __('Použitia / obdobie') }}</th><th class="py-1 pe-2">{{ __('Stripe price') }}</th><th class="py-1 pe-2">{{ __('Stav') }}</th><th class="py-1"></th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->plans as $code => $versions)
                    @foreach ($versions as $plan)
                        <tr wire:key="plan-{{ $plan->id }}" class="{{ $plan->state === CatalogState::Retired ? 'text-zinc-400' : '' }}">
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $loop->first ? $code : '' }}</td>
                            <td class="py-1.5 pe-2">v{{ $plan->version }}</td>
                            <td class="py-1.5 pe-2">{{ $plan->name }} <span class="text-xs text-zinc-500">({{ $plan->interval->label() }})</span></td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($plan->final_price_cents, $plan->currency) }}</td>
                            <td class="py-1.5 pe-2">{{ __(':count textov', ['count' => $plan->text_uses_per_period]) }} · {{ __(':count obrázkov :profile', ['count' => $plan->image_uses_per_period, 'profile' => $plan->imageProfile()->label()]) }}@if ($plan->meal_analysis_uses_per_period > 0) · {{ __(':count analýz jedla', ['count' => $plan->meal_analysis_uses_per_period]) }}@endif</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $plan->stripe_price_id ?? __('— (nepredajné)') }}</td>
                            <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$plan->state->badgeColor()">{{ $plan->state->label() }}</flux:badge>@if ($plan->effective_from)<div class="text-xs text-zinc-500">{{ __('od :date', ['date' => $plan->effective_from->setTimezone(config('recipes.default_timezone'))->format('d.m.Y')]) }}</div>@endif</td>
                            <td class="py-1.5 whitespace-nowrap text-right">
                                @if ($loop->first)<flux:button size="xs" variant="ghost" icon="document-duplicate" wire:click="startDraft('plan', {{ $plan->id }})">{{ __('Nová verzia') }}</flux:button>@endif
                                @if ($plan->state === CatalogState::Draft)<flux:button size="xs" variant="primary" wire:click="activate('plan', {{ $plan->id }})" wire:confirm="{{ __('Aktivovať túto verziu a stiahnuť predchádzajúcu aktívnu?') }}" data-test="activate-plan-{{ $plan->id }}">{{ __('Aktivovať') }}</flux:button>@endif
                                @if ($plan->state !== CatalogState::Retired)<flux:button size="xs" variant="ghost" wire:click="retire('plan', {{ $plan->id }})" wire:confirm="{{ __('Prestať predávať túto verziu?') }}">{{ __('Stiahnuť') }}</flux:button>@endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto" data-test="catalog-addons">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" class="font-display">{{ __('Balíky') }}</flux:heading>
            <flux:button size="xs" variant="ghost" icon="plus" wire:click="startCreate" data-test="catalog-new-addon">{{ __('Nový balík') }}</flux:button>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">{{ __('Kód') }}</th><th class="py-1 pe-2">{{ __('Verzia') }}</th><th class="py-1 pe-2">{{ __('Názov') }}</th><th class="py-1 pe-2 text-right">{{ __('Cena') }}</th><th class="py-1 pe-2">{{ __('Obsah') }}</th><th class="py-1 pe-2">{{ __('Stripe price') }}</th><th class="py-1 pe-2">{{ __('Stav') }}</th><th class="py-1"></th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->addons as $code => $versions)
                    @foreach ($versions as $addon)
                        <tr wire:key="addon-{{ $addon->id }}" class="{{ $addon->state === CatalogState::Retired ? 'text-zinc-400' : '' }}">
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $loop->first ? $code : '' }}</td>
                            <td class="py-1.5 pe-2">v{{ $addon->version }}</td>
                            <td class="py-1.5 pe-2">{{ $addon->name }}</td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($addon->final_price_cents, $addon->currency) }}</td>
                            <td class="py-1.5 pe-2">{{ $addon->unit_count }} × {{ $addon->unit_kind->label() }}</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $addon->stripe_price_id ?? __('— (nepredajné)') }}</td>
                            <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$addon->state->badgeColor()">{{ $addon->state->label() }}</flux:badge></td>
                            <td class="py-1.5 whitespace-nowrap text-right">
                                @if ($loop->first)<flux:button size="xs" variant="ghost" icon="document-duplicate" wire:click="startDraft('addon', {{ $addon->id }})">{{ __('Nová verzia') }}</flux:button>@endif
                                @if ($addon->state === CatalogState::Draft)<flux:button size="xs" variant="primary" wire:click="activate('addon', {{ $addon->id }})" wire:confirm="{{ __('Aktivovať túto verziu a stiahnuť predchádzajúcu aktívnu?') }}" data-test="activate-addon-{{ $addon->id }}">{{ __('Aktivovať') }}</flux:button>@endif
                                @if ($addon->state !== CatalogState::Retired)<flux:button size="xs" variant="ghost" wire:click="retire('addon', {{ $addon->id }})" wire:confirm="{{ __('Prestať predávať túto verziu?') }}">{{ __('Stiahnuť') }}</flux:button>@endif
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
                <flux:heading size="lg" class="font-display">{{ $draft_type === 'plan' ? __('Nová verzia plánu') : __('Nová verzia balíka') }}</flux:heading>
                <flux:text class="text-xs">{{ __('Vznikne návrh; predávať sa začne až po aktivácii. Stripe price ID musí byť cena s rovnakou sumou a intervalom v Stripe – aplikácia ceny v Stripe nevytvára.') }}</flux:text>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="draft_name" :label="__('Názov')" />
                    <flux:input wire:model="draft_price" :label="__('Konečná cena (EUR)')" placeholder="2,49" />
                    <flux:input wire:model="draft_stripe_price_id" :label="__('Stripe price ID')" placeholder="price_…" />
                    @if ($draft_type === 'plan')
                        <flux:input wire:model="draft_text_uses" type="number" min="0" :label="__('Textových operácií / obdobie')" />
                        <flux:input wire:model="draft_image_uses" type="number" min="0" :label="__('Obrázkov / obdobie')" />
                        <flux:select wire:model="draft_image_profile" :label="__('Profil obrázkov')" :description="__('Economy až po vyhodnotení porovnania low/medium (etapa 8); existujúce granty sa nemenia.')" data-test="draft-image-profile">
                            @foreach (ImageProfile::selectable() as $profile)
                                <flux:select.option value="{{ $profile->value }}">{{ $profile->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="draft_meal_analyses" type="number" min="0" :label="__('Analýz jedla / obdobie')" :description="__('0 = plán analýzy neobsahuje')" data-test="draft-meal-analyses" />
                    @else
                        <flux:input wire:model="draft_unit_count" type="number" min="1" :label="__('Počet jednotiek')" />
                    @endif
                </div>
                <flux:textarea wire:model="draft_reason" rows="2" :label="__('Dôvod (ide do auditu)')" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Uložiť návrh') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="cancelDraft">{{ __('Zrušiť') }}</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($creating)
        <flux:card class="space-y-3" data-test="new-addon-form">
            <form wire:submit="createAddon" class="space-y-3">
                <flux:heading size="lg" class="font-display">{{ __('Nový balík') }}</flux:heading>
                <flux:text class="text-xs">{{ __('Balík pod novým kódom (napr. meal_analyses_100, images_economy_20) vznikne ako návrh v1; predáva sa až po aktivácii. Economy balík nacení až výsledok porovnania – nezakladaj ho s neurčenou cenou.') }}</flux:text>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="new_code" :label="__('Kód')" placeholder="meal_analyses_100" data-test="new-code" />
                    <flux:input wire:model="new_name" :label="__('Názov')" data-test="new-name" />
                    <flux:select wire:model="new_kind" :label="__('Druh použitia')" :placeholder="__('vyber')" data-test="new-kind">
                        @foreach (UsageKind::cases() as $kind)
                            <flux:select.option value="{{ $kind->value }}">{{ $kind->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="new_unit_count" type="number" min="1" :label="__('Počet jednotiek')" data-test="new-count" />
                    <flux:input wire:model="new_price" :label="__('Konečná cena (EUR)')" placeholder="1,99" data-test="new-price" />
                    <flux:input wire:model="new_stripe_price_id" :label="__('Stripe price ID')" placeholder="price_…" />
                </div>
                <flux:textarea wire:model="new_reason" rows="2" :label="__('Dôvod (ide do auditu)')" data-test="new-reason" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" size="sm" data-test="new-addon-save">{{ __('Založiť návrh') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="cancelCreate">{{ __('Zrušiť') }}</flux:button>
                </div>
            </form>
        </flux:card>
    @endif
</div>
